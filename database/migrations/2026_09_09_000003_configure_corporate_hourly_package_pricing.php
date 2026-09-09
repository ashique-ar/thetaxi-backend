<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PACKAGES = [
        ['code' => 'hourly_8h_80km', 'name' => '8 Hours / 80 KM', 'hours' => 8, 'km' => 80],
        ['code' => 'hourly_10h_100km', 'name' => '10 Hours / 100 KM', 'hours' => 10, 'km' => 100],
        ['code' => 'hourly_100_200km', 'name' => '100 KM - 200 KM', 'hours' => 0, 'km' => 200],
        ['code' => 'hourly_above_200km', 'name' => 'Above 200 KM', 'hours' => 0, 'km' => 200],
    ];

    public function up(): void
    {
        // Undo the discarded package-owned charge flags. Hour eligibility is
        // derived from the package duration and all prices stay in Pricing.
        if (Schema::hasColumn('service_packages', 'charges_extra_hours')) {
            Schema::table('service_packages', function (Blueprint $table): void {
                $table->dropColumn(['charges_extra_hours', 'charges_extra_km']);
            });
        }

        if (!Schema::hasColumn('vehicle_pricing_slab_definitions', 'service_package_id')) {
            Schema::table('vehicle_pricing_slab_definitions', function (Blueprint $table): void {
                $table->uuid('service_package_id')->nullable()->index()->after('service_type_id');
            });
        }

        $service = DB::table('service_types')->where('code', 'hourly_package')->whereNull('deleted_at')->first();
        if (!$service) {
            return;
        }
        $creatorId = DB::table('vehicle_pricing_calculation_definitions')
            ->where('service_type_id', $service->id)->whereNotNull('created_by')->value('created_by')
            ?? DB::table('users')->whereNull('deleted_at')->value('id');
        if (!$creatorId) {
            throw new RuntimeException('Hourly Package pricing requires a valid user.');
        }

        // Remove the discarded package-specific calculation/rate experiment.
        DB::table('vehicle_pricing_calculation_definitions')->where('service_type_id', $service->id)
            ->where('name', 'like', 'Hourly Package - %')
            ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now(), 'updated_by' => $creatorId]);
        DB::table('vehicle_pricing_common_rate_definitions')->where('service_type_id', $service->id)
            ->where(function ($query): void {
                $query->where('code', 'like', 'PACKAGE_RATE_HOURLY_%')
                    ->orWhere('code', 'like', 'EXTRA_KM_RATE_HOURLY_%')
                    ->orWhere('code', 'like', 'EXTRA_HOUR_RATE_HOURLY_%');
            })->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);

        $config = json_decode((string) $service->form_config, true) ?: [];
        $config['trip_mode'] = 'open_package';
        $config['disable_route_preview'] = true;
        $config['disable_distance_estimate'] = true;
        $config['dropoff_location'] = array_merge($config['dropoff_location'] ?? [], ['required' => false]);
        $config['service_package_id'] = array_merge($config['service_package_id'] ?? [], [
            'type' => 'select', 'label' => 'Hourly Package', 'required' => true, 'order' => 4,
            'options' => [], 'help_text' => 'Select the package before confirming the booking.',
        ]);
        DB::table('service_types')->where('id', $service->id)->update([
            'uses_dropoff_time' => false,
            'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        foreach (self::PACKAGES as $index => $item) {
            $package = DB::table('service_packages')->where('code', $item['code'])->first();
            $packageId = $package?->id ?? (string) Str::uuid();
            DB::table('service_packages')->updateOrInsert(['code' => $item['code']], [
                'id' => $packageId, 'service_type_id' => $service->id, 'name' => $item['name'],
                'description' => 'Corporate open package selected and locked before hire.',
                'max_km_per_day' => null, 'max_km_per_package' => $item['km'], 'price_multiplier' => 1,
                'rate_type' => $item['hours'] > 0 ? 'hourly' : 'flat',
                'default_duration_hours' => $item['hours'], 'default_duration_minutes' => 0,
                'is_active' => true, 'sort_order' => $index + 1,
                'updated_at' => now(), 'created_at' => $package?->created_at ?? now(), 'deleted_at' => null,
            ]);

            $slabName = 'Package - '.$item['name'];
            $slab = DB::table('vehicle_pricing_slab_definitions')
                ->where('service_type_id', $service->id)->where('name', $slabName)->first();
            DB::table('vehicle_pricing_slab_definitions')->updateOrInsert(
                ['service_type_id' => $service->id, 'name' => $slabName],
                [
                    'id' => $slab?->id ?? (string) Str::uuid(), 'service_package_id' => $packageId,
                    'type' => 'flat_rate', 'min_minutes' => null, 'max_minutes' => null,
                    'min_hours' => null, 'max_hours' => null, 'min_days' => null, 'max_days' => null,
                    'max_km_per_day' => null, 'max_km_per_package' => $item['km'],
                    'sort_order' => $index + 1, 'is_active' => true,
                    'owner_type' => null, 'owner_id' => null, 'priority' => 500,
                    'created_user_id' => $creatorId, 'updated_user_id' => $creatorId,
                    'created_at' => $slab?->created_at ?? now(), 'updated_at' => now(), 'deleted_at' => null,
                ]
            );
        }

        DB::table('vehicle_pricing_slab_definitions')->where('service_type_id', $service->id)
            ->whereNull('service_package_id')->update(['is_active' => false, 'updated_at' => now()]);

        foreach ([
            'extra_km_rate' => ['Extra KM Rate', 'per_km', 'LKR/km', 10],
            'extra_hour_rate' => ['Extra Hour Rate', 'per_hour', 'LKR/hr', 20],
        ] as $code => [$name, $type, $unit, $order]) {
            $rate = DB::table('vehicle_pricing_common_rate_definitions')
                ->where('service_type_id', $service->id)->where('code', $code)->first();
            DB::table('vehicle_pricing_common_rate_definitions')->updateOrInsert(
                ['service_type_id' => $service->id, 'code' => $code],
                [
                    'id' => $rate?->id ?? (string) Str::uuid(), 'name' => $name,
                    'description' => $name.' shared by the selected hourly package.',
                    'common_rate_type' => $type, 'display_unit' => $unit, 'is_mandatory' => true,
                    'is_active' => true, 'sort_order' => $order, 'owner_type' => null, 'owner_id' => null,
                    'priority' => 100, 'updated_at' => now(), 'created_at' => $rate?->created_at ?? now(),
                    'deleted_at' => null,
                ]
            );
        }

        $formula = 'slab_rate + (max(0, total_distance - package_included_km) * extra_km_rate)'
            .' + (max(0, duration_hours - package_included_hours) * extra_hour_rate * package_has_hour_limit)';
        $variables = [
            ['name' => 'slab_rate', 'type' => 'slab_rate', 'is_required' => true],
            ['name' => 'total_distance', 'type' => 'distance', 'default_value' => 0, 'is_required' => false],
            ['name' => 'package_included_km', 'type' => 'distance', 'is_required' => true],
            ['name' => 'extra_km_rate', 'type' => 'common_rate', 'is_required' => true],
            ['name' => 'duration_hours', 'type' => 'duration', 'default_value' => 0, 'is_required' => false],
            ['name' => 'package_included_hours', 'type' => 'duration', 'is_required' => true],
            ['name' => 'extra_hour_rate', 'type' => 'common_rate', 'is_required' => true],
            ['name' => 'package_has_hour_limit', 'type' => 'number', 'is_required' => true],
        ];
        $existing = DB::table('vehicle_pricing_calculation_definitions')
            ->where('service_type_id', $service->id)->where('name', 'Hourly Package')->first();
        DB::table('vehicle_pricing_calculation_definitions')->updateOrInsert(
            ['service_type_id' => $service->id, 'name' => 'Hourly Package'],
            [
                'id' => $existing?->id ?? (string) Str::uuid(),
                'description' => 'One shared calculation using the preselected package slab and common overage rates.',
                'formula' => $formula, 'variables' => json_encode($variables), 'conditions' => json_encode([]),
                'status' => 'active', 'owner_type' => null, 'owner_id' => null, 'priority' => 500,
                'created_by' => $existing?->created_by ?? $creatorId, 'updated_by' => $creatorId,
                'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(), 'deleted_at' => null,
            ]
        );
    }

    public function down(): void
    {
        // Forward correction only: retain pricing history and avoid destructive rollback.
    }
};
