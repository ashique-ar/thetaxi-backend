<?php

namespace App\Services;

use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Models\Corporate\CorporateServiceDistancePolicy;
use Carbon\CarbonInterface;

class CorporateDistancePolicyResolver
{
    public function resolve(string $corporateId, string $serviceTypeId, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        $override = CorporateServiceDistancePolicy::query()
            ->with('policy')
            ->where('corporate_id', $corporateId)
            ->where('service_type_id', $serviceTypeId)
            ->effectiveAt($at)
            ->orderByDesc('effective_from')
            ->latest('created_at')
            ->first();

        if ($override?->application_mode === 'disabled') {
            return $this->disabled('service_override', $override);
        }

        $default = CorporateDistancePricingPolicy::query()
            ->where('corporate_id', $corporateId)
            ->where('is_default', true)
            ->effectiveAt($at)
            ->orderByDesc('effective_from')
            ->latest('created_at')
            ->first();

        if ($override?->application_mode === 'enabled') {
            $policy = $override->policy_id ? $override->policy : $default;

            if (! $policy || $policy->corporate_id !== $corporateId || ! $policy->is_active
                || ($policy->effective_from && $policy->effective_from->gt($at))
                || ($policy->effective_until && $policy->effective_until->lt($at))) {
                return [
                    'enabled' => true,
                    'source' => 'service_override',
                    'policy' => null,
                    'override' => $override,
                    'error' => 'The enabled service distance policy has no effective policy for this company.',
                ];
            }

            return $this->enabled('service_override', $policy, $override);
        }

        if ($default && $default->default_service_mode === 'enabled') {
            return $this->enabled('company_default', $default, $override);
        }

        return $this->disabled($default ? 'company_default' : 'normal_pricing', $override, $default);
    }

    private function enabled(string $source, CorporateDistancePricingPolicy $policy, ?CorporateServiceDistancePolicy $override): array
    {
        return ['enabled' => true, 'source' => $source, 'policy' => $policy, 'override' => $override, 'error' => null];
    }

    private function disabled(string $source, ?CorporateServiceDistancePolicy $override, ?CorporateDistancePricingPolicy $policy = null): array
    {
        return ['enabled' => false, 'source' => $source, 'policy' => $policy, 'override' => $override, 'error' => null];
    }
}
