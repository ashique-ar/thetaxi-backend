<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BookingWorkflowSettingsResolver
{
    public function __construct(private readonly WebsiteSettingsService $websiteSettings)
    {
    }

    public function resolve(?array $serviceConfig = null): array
    {
        // Version distinguishes frozen booking policies from legacy service-level overrides.
        if (($serviceConfig['policy_version'] ?? null) === 1) {
            $business = $this->businessDefaults();
            return [
                'enable_return_stage' => $business['enable_return_stage'] && (bool) ($serviceConfig['return_stage_enabled'] ?? false),
                'enable_qc_stage' => $business['enable_qc_stage'] && (bool) ($serviceConfig['qc_stage_enabled'] ?? false),
                'enable_maintenance_stage' => $business['enable_maintenance_stage'] && (bool) ($serviceConfig['maintenance_stage_enabled'] ?? false),
                'require_signed_agreement' => $business['require_signed_agreement'] && (bool) ($serviceConfig['signed_agreement_required'] ?? false),
                'require_driver_license' => (bool) ($serviceConfig['driver_license_required'] ?? false),
                'require_customer_identity_document' => (bool) ($serviceConfig['customer_identity_document_required'] ?? false),
                'require_booking_approval' => (bool) ($serviceConfig['booking_approval_required'] ?? false),
                'require_handover_checklist' => (bool) ($serviceConfig['handover_checklist_required'] ?? false),
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
            'handover_checklist_required' => 'require_handover_checklist',
        ] as $configKey => $settingKey) {
            if (array_key_exists($configKey, $serviceConfig ?? []) && $serviceConfig[$configKey] !== null) {
                if ($settingKey === 'require_signed_agreement') {
                    $settings[$settingKey] = $defaults[$settingKey] && (bool) $serviceConfig[$configKey];
                } elseif (in_array($settingKey, [
                        'require_driver_license',
                        'require_customer_identity_document',
                        'require_booking_approval',
                        'require_handover_checklist',
                    ], true)) {
                    $settings[$settingKey] = (bool) $serviceConfig[$configKey];
                } else {
                    $settings[$settingKey] = $settings[$settingKey] && (bool) $serviceConfig[$configKey];
                }
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
            'handover_checklist_required' => 'require_handover_checklist',
        ] as $configKey => $settingKey) {
            $choice = $serviceConfig[$configKey] ?? null;
            if ($choice === null) {
                $sources[$settingKey] = in_array($settingKey, ['require_signed_agreement', 'require_driver_license', 'require_customer_identity_document', 'require_booking_approval', 'require_handover_checklist'], true) ? 'Built-in default' : 'Business setting';
            } elseif (! (bool) $choice) {
                $sources[$settingKey] = 'Turned off for this service';
            } elseif (in_array($settingKey, ['require_signed_agreement', 'require_driver_license', 'require_customer_identity_document', 'require_booking_approval', 'require_handover_checklist'], true)) {
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

    public function assertCanDisableStage(array $stageKeys): void
    {
        if (!Schema::hasTable('bookings') || !Schema::hasTable('booking_items')) return;

        $openItems = DB::table('booking_items as item')
            ->join('bookings as booking', 'booking.id', '=', 'item.booking_id')
            ->whereNull('booking.deleted_at')->whereNull('item.deleted_at')->whereNull('item.completed_at')
            ->whereNotIn('booking.status', ['completed', 'cancelled', 'canceled', 'booking_cancelled', 'booking_rejected', 'rejected', 'inquiry_cancelled', 'inquiry_canceled'])
            ->where(fn ($status) => $status->whereNull('item.status')->orWhereNotIn('item.status', ['completed', 'cancelled', 'canceled', 'booking_cancelled', 'booking_rejected', 'rejected']))
            ->get(['item.workflow_config_snapshot', 'item.service_type_id', 'booking.booking_number']);

        $legacyServiceIds = $openItems->filter(function ($item): bool {
            $snapshot = is_array($item->workflow_config_snapshot)
                ? $item->workflow_config_snapshot
                : (json_decode((string) $item->workflow_config_snapshot, true) ?: []);
            return (int) ($snapshot['policy_version'] ?? 0) !== 1;
        })->pluck('service_type_id')->filter()->unique()->values();
        $serviceConfigs = $legacyServiceIds->isEmpty()
            ? collect()
            : DB::table('service_types')->whereIn('id', $legacyServiceIds)->pluck('workflow_config', 'id');

        $affected = $openItems->filter(function ($item) use ($stageKeys, $serviceConfigs): bool {
            $snapshot = is_array($item->workflow_config_snapshot)
                ? $item->workflow_config_snapshot
                : (json_decode((string) $item->workflow_config_snapshot, true) ?: []);
            if ((int) ($snapshot['policy_version'] ?? 0) === 1) {
                $effective = [
                    'enable_return_stage' => (bool) ($snapshot['return_stage_enabled'] ?? false),
                    'enable_qc_stage' => (bool) ($snapshot['qc_stage_enabled'] ?? false),
                    'enable_maintenance_stage' => (bool) ($snapshot['maintenance_stage_enabled'] ?? false),
                ];
            } else {
                $config = $serviceConfigs->get($item->service_type_id);
                $config = is_array($config) ? $config : (json_decode((string) $config, true) ?: null);
                $effective = $this->resolve($config);
            }

            foreach ($stageKeys as $stageKey) {
                if (!empty($effective[$stageKey])) return true;
            }
            return false;
        });

        if ($affected->isNotEmpty()) {
            $references = $affected->pluck('booking_number')->filter()->unique()->take(5)->implode(', ');
            $referenceText = $references ? ' Affected bookings: '.$references.'.' : '';
            abort(409, 'This stage cannot be turned off while '.$affected->count().' open booking item(s) still use it. Complete or cancel that work first.'.$referenceText);
        }

        if (in_array('enable_maintenance_stage', $stageKeys, true) && Schema::hasTable('booking_activities')) {
            $pendingMaintenance = DB::table('booking_activities')->whereNull('deleted_at')
                ->where('event_key', 'post_trip_maintenance')->where('result_status', 'pending')->exists();
            abort_unless(!$pendingMaintenance, 409, 'Maintenance cannot be turned off while completed trips still have maintenance work waiting. Finish or clear those maintenance tasks first.');
        }
    }

    private function businessDefaults(): array
    {
        $enableReturn = $this->enabled('feature_vehicle_return_management_enabled');
        return [
            'enable_return_stage' => $enableReturn,
            'enable_qc_stage' => $this->enabled('assignment_enable_qc_stage') && $enableReturn,
            'enable_maintenance_stage' => $this->enabled('assignment_enable_maintenance_stage'),
            'require_signed_agreement' => $this->enabled('booking_signed_agreement_required', true),
            'require_driver_license' => false,
            'require_customer_identity_document' => false,
            'require_booking_approval' => false,
            'require_handover_checklist' => false,
        ];
    }

    private function enabled(string $key, bool $default = false): bool
    {
        $value = $this->websiteSettings->getMultiple([$key])[$key] ?? $default;
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int) $value === 1;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
