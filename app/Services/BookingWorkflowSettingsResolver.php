<?php

namespace App\Services;

class BookingWorkflowSettingsResolver
{
    public function __construct(private readonly WebsiteSettingsService $websiteSettings)
    {
    }

    public function resolve(?array $serviceConfig = null): array
    {
        // Version distinguishes frozen booking policies from legacy service-level overrides.
        if (($serviceConfig['policy_version'] ?? null) === 1) {
            return [
                'enable_return_stage' => (bool) ($serviceConfig['return_stage_enabled'] ?? false),
                'enable_qc_stage' => (bool) ($serviceConfig['qc_stage_enabled'] ?? false),
                'enable_maintenance_stage' => (bool) ($serviceConfig['maintenance_stage_enabled'] ?? false),
                'require_signed_agreement' => (bool) ($serviceConfig['signed_agreement_required'] ?? false),
                'require_driver_license' => (bool) ($serviceConfig['driver_license_required'] ?? false),
                'require_customer_identity_document' => (bool) ($serviceConfig['customer_identity_document_required'] ?? false),
                'require_booking_approval' => (bool) ($serviceConfig['booking_approval_required'] ?? false),
            ];
        }

        $defaults = $this->businessDefaults();
        $settings = $defaults;

        foreach ([
            'return_stage_enabled' => 'enable_return_stage',
            'qc_stage_enabled' => 'enable_qc_stage',
            'maintenance_stage_enabled' => 'enable_maintenance_stage',
            'signed_agreement_required' => 'require_signed_agreement',
            'driver_license_required' => 'require_driver_license',
            'customer_identity_document_required' => 'require_customer_identity_document',
            'booking_approval_required' => 'require_booking_approval',
        ] as $configKey => $settingKey) {
            if (array_key_exists($configKey, $serviceConfig ?? []) && $serviceConfig[$configKey] !== null) {
                $settings[$settingKey] = $settingKey === 'require_driver_license'
                    ? (bool) $serviceConfig[$configKey]
                    : $settings[$settingKey] && (bool) $serviceConfig[$configKey];
            }
        }

        $settings['enable_qc_stage'] = $settings['enable_qc_stage'] && $settings['enable_return_stage'];

        return $settings;
    }

    public function preview(array $serviceConfig): array
    {
        $defaults = $this->businessDefaults();
        $effective = $this->resolve($serviceConfig);
        $sources = [];

        foreach ([
            'return_stage_enabled' => 'enable_return_stage',
            'qc_stage_enabled' => 'enable_qc_stage',
            'maintenance_stage_enabled' => 'enable_maintenance_stage',
            'signed_agreement_required' => 'require_signed_agreement',
            'driver_license_required' => 'require_driver_license',
            'customer_identity_document_required' => 'require_customer_identity_document',
            'booking_approval_required' => 'require_booking_approval',
        ] as $configKey => $settingKey) {
            $choice = $serviceConfig[$configKey] ?? null;
            if ($choice === null) {
                $sources[$settingKey] = in_array($settingKey, ['require_signed_agreement', 'require_driver_license', 'require_customer_identity_document', 'require_booking_approval'], true) ? 'Built-in default' : 'Business setting';
            } elseif (! (bool) $choice) {
                $sources[$settingKey] = 'Turned off for this service';
            } elseif (in_array($settingKey, ['require_driver_license', 'require_customer_identity_document', 'require_booking_approval'], true)) {
                $sources[$settingKey] = 'Enabled for this service';
            } elseif (! $defaults[$settingKey]) {
                $sources[$settingKey] = 'Blocked by business setting';
            } else {
                $sources[$settingKey] = 'Enabled for this service';
            }
        }

        $warnings = [];
        if (($serviceConfig['qc_stage_enabled'] ?? null) === true && ! $effective['enable_qc_stage']) {
            $warnings[] = 'Return inspection will not run unless both return management and the business QC setting are enabled.';
        }

        return [
            'effective_settings' => $effective,
            'sources' => $sources,
            'warnings' => $warnings,
            'applies_to' => 'New bookings only',
            'existing_bookings_change' => false,
        ];
    }

    private function businessDefaults(): array
    {
        $enableReturn = $this->enabled('feature_vehicle_return_management_enabled');
        return [
            'enable_return_stage' => $enableReturn,
            'enable_qc_stage' => $this->enabled('assignment_enable_qc_stage') && $enableReturn,
            'enable_maintenance_stage' => $this->enabled('assignment_enable_maintenance_stage'),
            'require_signed_agreement' => true,
            'require_driver_license' => false,
            'require_customer_identity_document' => false,
            'require_booking_approval' => false,
        ];
    }

    private function enabled(string $key): bool
    {
        $value = $this->websiteSettings->get($key, false);
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int) $value === 1;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
