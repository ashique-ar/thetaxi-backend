<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $service = DB::table('service_types')->where('code', 'hourly_package')->whereNull('deleted_at')->first();
        if (!$service) {
            return;
        }

        $creatorId = DB::table('vehicle_pricing_calculation_definitions')
            ->where('service_type_id', $service->id)->whereNotNull('created_by')->value('created_by');
        if (!$creatorId && DB::getSchemaBuilder()->hasTable('users')) {
            $creatorId = DB::table('users')->whereNull('deleted_at')->value('id');
        }
        if (!$creatorId) {
            throw new RuntimeException('Hourly Package calculation requires a valid created_by user.');
        }

        DB::table('vehicle_pricing_calculation_definitions')
            ->where('service_type_id', $service->id)
            ->where('name', 'like', 'Hourly Package - %')
            ->update([
                'status' => 'inactive',
                'deleted_at' => now(),
                'updated_at' => now(),
                'updated_by' => $creatorId,
            ]);

        $variables = [
            ['name' => 'package_base_rate', 'type' => 'number', 'is_required' => true],
            ['name' => 'total_distance', 'type' => 'distance', 'default_value' => 0, 'is_required' => false],
            ['name' => 'package_included_km', 'type' => 'distance', 'is_required' => true],
            ['name' => 'package_extra_km_rate', 'type' => 'number', 'is_required' => true],
            ['name' => 'package_charges_extra_km', 'type' => 'number', 'is_required' => true],
            ['name' => 'duration_hours', 'type' => 'duration', 'default_value' => 0, 'is_required' => false],
            ['name' => 'package_included_hours', 'type' => 'duration', 'is_required' => true],
            ['name' => 'package_extra_hour_rate', 'type' => 'number', 'is_required' => true],
            ['name' => 'package_charges_extra_hours', 'type' => 'number', 'is_required' => true],
        ];
        $formula = 'package_base_rate'
            .' + (max(0, total_distance - package_included_km) * package_extra_km_rate * package_charges_extra_km)'
            .' + (max(0, duration_hours - package_included_hours) * package_extra_hour_rate * package_charges_extra_hours)';
        $existing = DB::table('vehicle_pricing_calculation_definitions')
            ->where('service_type_id', $service->id)->where('name', 'Hourly Package')->first();

        DB::table('vehicle_pricing_calculation_definitions')->updateOrInsert(
            ['service_type_id' => $service->id, 'name' => 'Hourly Package'],
            [
                'id' => $existing?->id ?? (string) Str::uuid(),
                'description' => 'Shared calculation for the package preselected at booking.',
                'formula' => $formula,
                'variables' => json_encode($variables),
                'conditions' => json_encode([]),
                'status' => 'active',
                'owner_type' => null,
                'owner_id' => null,
                'priority' => 500,
                'created_by' => $existing?->created_by ?? $creatorId,
                'updated_by' => $creatorId,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]
        );
    }

    public function down(): void
    {
        // Do not reintroduce parallel package-specific pricing authorities.
    }
};
