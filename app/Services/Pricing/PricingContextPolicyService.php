<?php

namespace App\Services\Pricing;

use App\Models\Service\ServiceType;
use App\Models\Service\ServicePackage;
use App\Models\Vehicle\VehicleAddon;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PricingContextPolicyService
{
    public const SETTING_KEY = 'internal_pricing_mode';
    public const MODE_INHERIT_WEBSITE = 'inherit_website';
    public const MODE_SEPARATE = 'separate';

    public function __construct(private readonly WebsiteSettingsService $settings)
    {
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->settings->get(
            self::SETTING_KEY,
            self::MODE_INHERIT_WEBSITE
        )));

        return $mode === self::MODE_SEPARATE
            ? self::MODE_SEPARATE
            : self::MODE_INHERIT_WEBSITE;
    }

    public function internalUsesWebsitePricing(): bool
    {
        return $this->mode() === self::MODE_INHERIT_WEBSITE;
    }

    public function effectiveContext(?string $requestedContext): string
    {
        $context = strtolower(trim((string) $requestedContext));
        $context = in_array($context, ['public', 'portal', 'corporate'], true)
            ? $context
            : 'public';

        if ($context === 'portal' && $this->internalUsesWebsitePricing()) {
            return 'public';
        }

        return $context;
    }

    public function applyToRequest(Request $request): void
    {
        $calculationParams = $this->normalizeCalculationParams($request->all());
        $normalized = [];

        foreach ([
            'context',
            'pricing_context',
            'service_type_context',
            'service_context',
            'request_context',
            'applicable_context',
            'fallback_context',
        ] as $key) {
            if (!$request->exists($key)) {
                continue;
            }

            $requestedValue = strtolower(trim((string) $request->input($key)));
            $normalized[$key] = $key === 'context' && $requestedValue === 'all'
                ? 'all'
                : $this->effectiveContext($requestedValue);
        }

        if (
            $request->exists('service_type_id')
            && array_key_exists('service_type_id', $calculationParams)
        ) {
            $normalized['service_type_id'] = $calculationParams['service_type_id'];
        }
        foreach (['package_id', 'service_package_id'] as $key) {
            if ($request->exists($key) && array_key_exists($key, $calculationParams)) {
                $normalized[$key] = $calculationParams[$key];
            }
        }
        foreach (['addon_id', 'addon_ids', 'addons'] as $key) {
            if ($request->exists($key) && array_key_exists($key, $calculationParams)) {
                $normalized[$key] = $calculationParams[$key];
            }
        }

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }

    /**
     * Make Website service definitions and rates authoritative for internal pricing
     * while retaining the requested context for audit output.
     */
    public function normalizeCalculationParams(array $params): array
    {
        $isCorporate = !empty($params['corporate_account_id'])
            || filter_var($params['is_corporate_booking'] ?? false, FILTER_VALIDATE_BOOL);

        if ($isCorporate || !$this->internalUsesWebsitePricing()) {
            return $params;
        }

        $serviceTypeValue = $params['service_type_id'] ?? $params['service_type'] ?? null;
        $serviceType = $this->findServiceType($serviceTypeValue);
        $requestedContext = $params['pricing_context']
            ?? $params['service_type_context']
            ?? $params['service_context']
            ?? $params['request_context']
            ?? $serviceType?->context
            ?? 'public';

        if (strtolower(trim((string) $requestedContext)) === 'portal') {
            $params['requested_pricing_context'] = 'portal';
            $params['pricing_context'] = 'public';

            foreach (['service_type_context', 'service_context', 'request_context'] as $key) {
                if (array_key_exists($key, $params)) {
                    $params[$key] = 'public';
                }
            }

            if ($serviceType?->context === 'portal') {
                $websiteServiceType = $this->websiteServiceTypeFor($serviceType);

                if (!$websiteServiceType) {
                    throw new HttpException(
                        409,
                        "Internal service type {$serviceType->name} has no matching Website service type. Create the Website definition, or enable separate Internal pricing in Business Settings."
                    );
                }

                if (array_key_exists('service_type_id', $params)) {
                    $params['service_type_id'] = $websiteServiceType->id;
                }
                if (array_key_exists('service_type', $params)) {
                    $params['service_type'] = $websiteServiceType->id;
                }
                if (!array_key_exists('service_type_id', $params) && !array_key_exists('service_type', $params)) {
                    $params['service_type_id'] = $websiteServiceType->id;
                }

                $params['requested_service_type_id'] = $serviceType->id;
                $params['pricing_service_type_id'] = $websiteServiceType->id;
            }
        }

        foreach (['package_id', 'service_package_id'] as $key) {
            $package = $this->findServicePackage($params[$key] ?? null);
            if (!$package) {
                continue;
            }

            $effectivePackage = $this->effectiveServicePackage($package);
            if ($effectivePackage->is($package)) {
                continue;
            }

            $params[$key] = $effectivePackage->id;
            $params['requested_service_package_id'] = $package->id;
            $params['pricing_service_package_id'] = $effectivePackage->id;
        }

        if (array_key_exists('addon_id', $params)) {
            $params['addon_id'] = $this->effectiveVehicleAddonId($params['addon_id']);
        }
        if (is_array($params['addon_ids'] ?? null)) {
            $params['addon_ids'] = array_map(
                fn (mixed $addonId) => $this->effectiveVehicleAddonId($addonId),
                $params['addon_ids']
            );
        }
        if (is_array($params['addons'] ?? null)) {
            $params['addons'] = array_map(function (mixed $addon) {
                if (!is_array($addon) || !array_key_exists('addon_id', $addon)) {
                    return $addon;
                }

                $addon['addon_id'] = $this->effectiveVehicleAddonId($addon['addon_id']);

                return $addon;
            }, $params['addons']);
        }

        return $params;
    }

    public function effectiveServiceType(ServiceType $serviceType): ServiceType
    {
        if (!$this->internalUsesWebsitePricing() || $serviceType->context !== 'portal') {
            return $serviceType;
        }

        return $this->websiteServiceTypeFor($serviceType)
            ?? throw new HttpException(
                409,
                "Internal service type {$serviceType->name} has no matching Website service type. Create the Website definition, or enable separate Internal pricing in Business Settings."
            );
    }

    public function assertServiceTypeIsWritable(ServiceType $serviceType): void
    {
        if ($this->internalUsesWebsitePricing() && $serviceType->context === 'portal') {
            throw new HttpException(
                409,
                'Internal service types inherit Website configuration. Edit the matching Website service type, or enable separate Internal pricing in Business Settings.'
            );
        }
    }

    public function effectiveServicePackage(ServicePackage $package): ServicePackage
    {
        $serviceType = $package->serviceType;
        if (!$serviceType || !$this->internalUsesWebsitePricing() || $serviceType->context !== 'portal') {
            return $package;
        }

        $websiteServiceType = $this->websiteServiceTypeFor($serviceType);
        if (!$websiteServiceType) {
            throw new HttpException(
                409,
                "Internal package {$package->name} has no matching Website service type. Create the Website definition, or enable separate Internal pricing in Business Settings."
            );
        }

        return ServicePackage::query()
            ->where('service_type_id', $websiteServiceType->id)
            ->where(function ($query) use ($package) {
                $query->where('code', $package->code)
                    ->orWhere('name', $package->name);
            })
            ->orderByDesc('is_active')
            ->first()
            ?? throw new HttpException(
                409,
                "Internal package {$package->name} has no matching Website package. Create it under the Website service type, or enable separate Internal pricing in Business Settings."
            );
    }

    public function assertServicePackageIsWritable(ServicePackage $package): void
    {
        $serviceType = $package->serviceType;
        if ($serviceType) {
            $this->assertServiceTypeIsWritable($serviceType);
        }
    }

    public function effectiveVehicleAddon(VehicleAddon $addon): VehicleAddon
    {
        $serviceType = $addon->serviceType;
        if (!$serviceType || !$this->internalUsesWebsitePricing() || $serviceType->context !== 'portal') {
            return $addon;
        }

        $websiteServiceType = $this->websiteServiceTypeFor($serviceType);
        if (!$websiteServiceType) {
            throw new HttpException(
                409,
                "Internal add-on {$addon->name} has no matching Website service type. Create the Website definition, or enable separate Internal pricing in Business Settings."
            );
        }

        return VehicleAddon::query()
            ->where('service_type_id', $websiteServiceType->id)
            ->where('name', $addon->name)
            ->when(
                $addon->category_id,
                fn ($query) => $query->where('category_id', $addon->category_id),
                fn ($query) => $query->whereNull('category_id')
            )
            ->orderByDesc('is_active')
            ->first()
            ?? throw new HttpException(
                409,
                "Internal add-on {$addon->name} has no matching Website add-on. Create it under the Website service type, or enable separate Internal pricing in Business Settings."
            );
    }

    public function assertVehicleAddonIsWritable(VehicleAddon $addon): void
    {
        $serviceType = $addon->serviceType;
        if ($serviceType) {
            $this->assertServiceTypeIsWritable($serviceType);
        }
    }

    public function describe(): array
    {
        $mode = $this->mode();

        return [
            'internal_pricing_mode' => $mode,
            'internal_uses_website_pricing' => $mode === self::MODE_INHERIT_WEBSITE,
            'effective_contexts' => [
                'public' => 'public',
                'portal' => $mode === self::MODE_INHERIT_WEBSITE ? 'public' : 'portal',
                'corporate' => 'corporate',
            ],
        ];
    }

    private function findServiceType(mixed $value): ?ServiceType
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (Str::isUuid($value)) {
            return ServiceType::withInactive()->whereKey($value)->first();
        }

        return ServiceType::withInactive()
            ->where(function ($query) use ($value) {
                $query->where('code', $value)
                    ->orWhere('name', $value)
                    ->orWhere('slug', $value);
            })
            ->orderByRaw("CASE WHEN context = 'portal' THEN 0 WHEN context = 'public' THEN 1 ELSE 2 END")
            ->first();
    }

    private function findServicePackage(mixed $value): ?ServicePackage
    {
        if (!is_string($value) || !Str::isUuid(trim($value))) {
            return null;
        }

        return ServicePackage::query()
            ->with('serviceType')
            ->whereKey(trim($value))
            ->first();
    }

    private function effectiveVehicleAddonId(mixed $value): mixed
    {
        if (!is_string($value) || !Str::isUuid(trim($value))) {
            return $value;
        }

        $addon = VehicleAddon::query()
            ->with('serviceType')
            ->whereKey(trim($value))
            ->first();

        return $addon ? $this->effectiveVehicleAddon($addon)->id : $value;
    }

    private function websiteServiceTypeFor(ServiceType $serviceType): ?ServiceType
    {
        return ServiceType::withInactive()
            ->publicContext()
            ->where(function ($query) use ($serviceType) {
                $query->where('code', $serviceType->code);

                if ($serviceType->slug) {
                    $query->orWhere('slug', $serviceType->slug);
                }
            })
            ->orderByDesc('is_active')
            ->orderBy('priority')
            ->first();
    }
}
