<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TaxiMeterServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $fields = [
            'from_date' => ['type' => 'date', 'label' => 'Start Date', 'required' => true, 'order' => 1],
            'from_time' => ['type' => 'time', 'label' => 'Start Time', 'required' => true, 'order' => 2],
            'to_date' => ['type' => 'date', 'label' => 'Expected End Date', 'required' => true, 'order' => 3],
            'to_time' => ['type' => 'time', 'label' => 'Expected End Time', 'required' => true, 'order' => 4],
            'pickup_location' => ['type' => 'location', 'label' => 'Pickup Location', 'location_mode' => 'autocomplete', 'required' => true, 'order' => 5],
            'dropoff_location' => ['type' => 'location', 'label' => 'Drop-off Location (optional)', 'location_mode' => 'autocomplete', 'required' => false, 'order' => 6],
            'meter_package_kind' => [
                'type' => 'select', 'label' => 'Meter Package', 'required' => true, 'order' => 7,
                'options' => [
                    ['value' => 'distance', 'label' => 'Kilometer Package'],
                    ['value' => 'hourly', 'label' => 'Hourly Package'],
                    ['value' => 'combined', 'label' => 'Hours + Kilometers'],
                    ['value' => 'open_meter', 'label' => 'Open Meter'],
                ],
            ],
            'meter_included_km' => [
                'type' => 'number', 'label' => 'Included Kilometers', 'required' => false, 'order' => 8,
                'hint' => 'Enter 50, 100, or any custom distance.', 'validation' => ['min' => 0.01],
                'visible_when' => ['field' => 'meter_package_kind', 'value' => ['distance', 'combined']],
            ],
            'meter_included_hours' => [
                'type' => 'number', 'label' => 'Included Hours', 'required' => false, 'order' => 9,
                'hint' => 'Enter 2, 4, 8, or any custom duration.', 'validation' => ['min' => 0.01],
                'visible_when' => ['field' => 'meter_package_kind', 'value' => ['hourly', 'combined']],
            ],
            'meter_fixed_price' => [
                'type' => 'number', 'label' => 'Fixed Package Price', 'required' => false, 'order' => 10,
                'validation' => ['min' => 0],
                'visible_when' => ['field' => 'meter_package_kind', 'value' => ['distance', 'hourly', 'combined']],
            ],
            'meter_extra_per_km' => [
                'type' => 'number', 'label' => 'Extra Price per Kilometer', 'required' => false, 'order' => 11,
                'validation' => ['min' => 0],
                'visible_when' => ['field' => 'meter_package_kind', 'value' => ['distance', 'combined', 'open_meter']],
            ],
            'meter_extra_per_hour' => [
                'type' => 'number', 'label' => 'Extra Price per Hour', 'required' => false, 'order' => 12,
                'validation' => ['min' => 0],
                'visible_when' => ['field' => 'meter_package_kind', 'value' => ['hourly', 'combined', 'open_meter']],
            ],
        ];

        $actorId = DB::table('users')->where('is_active', true)->orderBy('id')->value('id');
        if (!$actorId) {
            throw new \RuntimeException('Create an active user before seeding the Taxi Meter pricing configuration.');
        }

        DB::transaction(function () use ($fields, $actorId): void {
            $serviceTypes = [];
            foreach (['website', 'corporate'] as $context) {
                $serviceTypeScope = ['code' => 'taxi_meter', 'context' => $context];
                if (Schema::hasColumn('service_types', 'owner_type')) {
                    $serviceTypeScope['owner_type'] = '';
                }
                if (Schema::hasColumn('service_types', 'owner_id')) {
                    $serviceTypeScope['owner_id'] = '';
                }

                $existing = DB::table('service_types')->where($serviceTypeScope)->first();
                $id = $existing->id ?? (string) Str::uuid();
                $existingConfig = json_decode((string) ($existing->form_config ?? ''), true) ?: [];
                $formConfig = array_merge($existingConfig, [
                    'trip_mode' => 'open_package',
                    'disable_route_preview' => true,
                    'disable_distance_estimate' => true,
                    'fields' => array_replace($fields, is_array($existingConfig['fields'] ?? null) ? $existingConfig['fields'] : []),
                ]);

                DB::table('service_types')->updateOrInsert(
                    $serviceTypeScope,
                    [
                        'id' => $id,
                        'name' => 'Taxi Meter',
                        'description' => 'Staff-created meter booking using kilometer and hourly package pricing.',
                        'slug' => 'taxi-meter',
                        'type' => 'with_driver',
                        'pricing_mode' => 'dynamic',
                        'pricing_basis' => 'vehicle_group',
                        'tour_booking_enabled' => false,
                        'uses_dropoff_time' => true,
                        'allow_return_trip' => false,
                        'allow_multiple_pickup_locations' => false,
                        'allow_multiple_dropoff_locations' => false,
                        'form_config' => json_encode($formConfig, JSON_UNESCAPED_SLASHES),
                        'workflow_config' => json_encode(json_decode((string) ($existing->workflow_config ?? ''), true) ?: [], JSON_UNESCAPED_SLASHES),
                        'frontend_category' => 'transport',
                        'category' => 'transport',
                        'priority' => 999,
                        'is_internal' => true,
                        'is_inquiry' => false,
                        'is_active' => true,
                        'created_user_id' => $existing->created_user_id ?? $actorId,
                        'updated_user_id' => $actorId,
                        'created_at' => $existing->created_at ?? now(),
                        'updated_at' => now(),
                        'deleted_at' => null,
                    ]
                );
                $serviceTypeId = (string) DB::table('service_types')
                    ->where($serviceTypeScope)->value('id');
                $serviceTypes[$context] = $serviceTypeId;

                $definition = DB::table('vehicle_pricing_calculation_definitions')
                    ->where('service_type_id', $serviceTypeId)
                    ->where('name', 'Taxi Meter Custom Package')
                    ->first();
                DB::table('vehicle_pricing_calculation_definitions')->updateOrInsert(
                    ['service_type_id' => $serviceTypeId, 'name' => 'Taxi Meter Custom Package'],
                    [
                        'id' => $definition->id ?? (string) Str::uuid(),
                        'description' => 'Per-booking meter package price with kilometer and hourly overages.',
                        'formula' => 'meter_fixed_price + (max(0, meter_actual_km - meter_included_km) * meter_extra_per_km) + (max(0, meter_actual_hours - meter_included_hours) * meter_extra_per_hour)',
                        'variables' => json_encode([
                            ['name' => 'meter_fixed_price', 'type' => 'number', 'default_value' => null, 'is_required' => true],
                            ['name' => 'meter_included_km', 'type' => 'number', 'default_value' => 0, 'is_required' => true],
                            ['name' => 'meter_included_hours', 'type' => 'number', 'default_value' => 0, 'is_required' => true],
                            ['name' => 'meter_extra_per_km', 'type' => 'number', 'default_value' => 0, 'is_required' => true],
                            ['name' => 'meter_extra_per_hour', 'type' => 'number', 'default_value' => 0, 'is_required' => true],
                            ['name' => 'meter_actual_km', 'type' => 'distance', 'default_value' => 0, 'is_required' => false],
                            ['name' => 'meter_actual_hours', 'type' => 'duration', 'default_value' => 0, 'is_required' => false],
                        ]),
                        'conditions' => json_encode([['field' => 'mode', 'operator' => '!=', 'value' => 'meter_telemetry_missing']]),
                        'status' => 'active',
                        'owner_type' => null,
                        'owner_id' => null,
                        'priority' => 1000,
                        'created_by' => $definition->created_by ?? $actorId,
                        'updated_by' => $actorId,
                        'created_at' => $definition->created_at ?? now(),
                        'updated_at' => now(),
                        'deleted_at' => null,
                    ]
                );
            }

            $existingFormConfig = DB::table('service_form_configs')->where('service_code', 'taxi_meter')->first();
            $legacyConfig = json_decode((string) ($existingFormConfig->config ?? ''), true) ?: [];
            DB::table('service_form_configs')->updateOrInsert(
                ['service_code' => 'taxi_meter'],
                ['id' => $existingFormConfig->id ?? (string) Str::uuid(), 'config' => json_encode(array_merge($legacyConfig, ['fields' => array_replace($fields, is_array($legacyConfig['fields'] ?? null) ? $legacyConfig['fields'] : [])])), 'is_active' => true, 'created_at' => $existingFormConfig->created_at ?? now(), 'updated_at' => now()]
            );

            $now = now();
            DB::table('corporates')->where('is_active', true)->whereNull('deleted_at')->get(['id'])->each(function ($corporate) use ($serviceTypes, $actorId, $now): void {
                $existing = DB::table('corporate_service_types')
                    ->where('corporate_id', $corporate->id)
                    ->where('service_type_id', $serviceTypes['corporate'])
                    ->whereNull('deleted_at')->first();
                if ($existing) {
                    DB::table('corporate_service_types')->where('id', $existing->id)->update([
                        'is_active' => true, 'updated_user_id' => $actorId, 'updated_at' => $now,
                    ]);
                } else {
                    DB::table('corporate_service_types')->insert([
                        'id' => (string) Str::uuid(), 'corporate_id' => $corporate->id,
                        'service_type_id' => $serviceTypes['corporate'], 'is_active' => true,
                        'created_user_id' => $actorId, 'updated_user_id' => $actorId,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            });
        });

        app(\App\Services\DynamicServiceConfigurationService::class)->clearCache();
        Cache::put('ref.service-types.v', ((int) Cache::get('ref.service-types.v', 0)) + 1, 86400);
        $this->command?->info('Taxi Meter service type and form configuration are ready.');
    }
}
