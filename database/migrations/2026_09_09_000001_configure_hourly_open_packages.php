<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PACKAGES = [
        ['code' => 'hourly_8h_80km', 'name' => '8 Hours / 80 KM', 'hours' => 8, 'km' => 80, 'extra_hours' => true],
        ['code' => 'hourly_10h_100km', 'name' => '10 Hours / 100 KM', 'hours' => 10, 'km' => 100, 'extra_hours' => true],
        ['code' => 'hourly_100_200km', 'name' => '100 KM - 200 KM', 'hours' => 0, 'km' => 200, 'extra_hours' => false],
        ['code' => 'hourly_above_200km', 'name' => 'Above 200 KM', 'hours' => 0, 'km' => 200, 'extra_hours' => false],
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('service_packages', 'charges_extra_hours')) {
            Schema::table('service_packages', function (Blueprint $table): void {
                $table->boolean('charges_extra_hours')->default(false)->after('default_duration_minutes');
                $table->boolean('charges_extra_km')->default(true)->after('charges_extra_hours');
            });
        }

        $service = DB::table('service_types')->where('code', 'hourly_package')->whereNull('deleted_at')->first();
        if (!$service) {
            return;
        }

        $config = json_decode((string) $service->form_config, true) ?: [];
        $config['trip_mode'] = 'open_package';
        $config['disable_route_preview'] = true;
        $config['disable_distance_estimate'] = true;
        $config['dropoff_location'] = array_merge($config['dropoff_location'] ?? [], ['required' => false]);
        $config['service_package_id'] = array_merge($config['service_package_id'] ?? [], [
            'type' => 'select', 'label' => 'Hourly Package', 'required' => true,
            'order' => 4, 'options' => [], 'help_text' => 'Select the package before confirming the booking.',
        ]);
        DB::table('service_types')->where('id', $service->id)->update([
            'uses_dropoff_time' => false,
            'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        foreach (self::PACKAGES as $index => $definition) {
            $package = DB::table('service_packages')->where('code', $definition['code'])->first();
            $packageId = $package?->id ?? (string) Str::uuid();
            DB::table('service_packages')->updateOrInsert(['code' => $definition['code']], [
                'id' => $packageId,
                'service_type_id' => $service->id,
                'name' => $definition['name'],
                'description' => 'Preselected open package. The selected package remains fixed for the hire.',
                'max_km_per_day' => null,
                'max_km_per_package' => $definition['km'],
                'price_multiplier' => 1,
                'rate_type' => $definition['hours'] > 0 ? 'hourly' : 'flat',
                'default_duration_hours' => $definition['hours'],
                'default_duration_minutes' => 0,
                'charges_extra_hours' => $definition['extra_hours'],
                'charges_extra_km' => true,
                // Keep unavailable until real corporate rates are entered.
                'is_active' => false,
                'sort_order' => $index + 1,
                'updated_at' => now(),
                'created_at' => $package?->created_at ?? now(),
                'deleted_at' => null,
            ]);

            $slug = strtoupper($definition['code']);
            $rateCodes = [
                "PACKAGE_RATE_{$slug}" => ['Package Rate - '.$definition['name'], 'fixed_amount', 'LKR'],
                "EXTRA_KM_RATE_{$slug}" => ['Extra KM Rate - '.$definition['name'], 'per_km', 'LKR/km'],
            ];
            if ($definition['extra_hours']) {
                $rateCodes["EXTRA_HOUR_RATE_{$slug}"] = ['Extra Hour Rate - '.$definition['name'], 'per_hour', 'LKR/hr'];
            }
            foreach ($rateCodes as $code => [$name, $type, $unit]) {
                DB::table('vehicle_pricing_common_rate_definitions')->updateOrInsert(
                    ['code' => $code],
                    [
                        'id' => DB::table('vehicle_pricing_common_rate_definitions')->where('code', $code)->value('id') ?? (string) Str::uuid(),
                        'name' => $name, 'service_type_id' => $service->id, 'description' => $name,
                        'common_rate_type' => $type, 'display_unit' => $unit, 'is_mandatory' => true,
                        'is_active' => true, 'sort_order' => ($index + 1) * 10,
                        'owner_type' => null, 'owner_id' => null, 'priority' => 100,
                        'updated_at' => now(), 'created_at' => now(), 'deleted_at' => null,
                    ]
                );
            }

            $variables = [
                ['name' => "PACKAGE_RATE_{$slug}", 'type' => 'common_rate', 'is_required' => true],
                ['name' => 'total_distance', 'type' => 'distance', 'default_value' => 0, 'is_required' => false],
                ['name' => 'package_included_km', 'type' => 'distance', 'is_required' => true],
                ['name' => "EXTRA_KM_RATE_{$slug}", 'type' => 'common_rate', 'is_required' => true],
            ];
            $formula = "PACKAGE_RATE_{$slug} + (max(0, total_distance - package_included_km) * EXTRA_KM_RATE_{$slug})";
            if ($definition['extra_hours']) {
                $variables[] = ['name' => 'duration_hours', 'type' => 'duration', 'default_value' => 0, 'is_required' => false];
                $variables[] = ['name' => 'package_included_hours', 'type' => 'duration', 'is_required' => true];
                $variables[] = ['name' => "EXTRA_HOUR_RATE_{$slug}", 'type' => 'common_rate', 'is_required' => true];
                $formula .= " + (max(0, duration_hours - package_included_hours) * EXTRA_HOUR_RATE_{$slug})";
            }
            DB::table('vehicle_pricing_calculation_definitions')->updateOrInsert(
                ['service_type_id' => $service->id, 'name' => 'Hourly Package - '.$definition['name']],
                [
                    'id' => DB::table('vehicle_pricing_calculation_definitions')->where('service_type_id', $service->id)->where('name', 'Hourly Package - '.$definition['name'])->value('id') ?? (string) Str::uuid(),
                    'description' => 'Package-selected hourly open-package calculation.',
                    'formula' => $formula, 'variables' => json_encode($variables),
                    'conditions' => json_encode([['field' => 'package_id', 'operator' => '=', 'value' => $packageId]]),
                    // Pricing definitions are activated after mandatory rates
                    // are entered; a missing monetary rate must never become zero.
                    'status' => 'draft', 'owner_type' => null, 'owner_id' => null,
                    'priority' => 500 + $index, 'updated_at' => now(), 'created_at' => now(), 'deleted_at' => null,
                ]
            );
        }

        DB::table('vehicle_pricing_calculation_definitions')
            ->where('service_type_id', $service->id)->where('name', 'Hourly Package')->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        $codes = array_column(self::PACKAGES, 'code');
        DB::table('vehicle_pricing_calculation_definitions')
            ->where('name', 'like', 'Hourly Package - %')->delete();
        $rateCodes = collect(self::PACKAGES)->flatMap(function (array $package): array {
            $slug = strtoupper($package['code']);
            return ["PACKAGE_RATE_{$slug}", "EXTRA_KM_RATE_{$slug}", "EXTRA_HOUR_RATE_{$slug}"];
        });
        DB::table('vehicle_pricing_common_rate_definitions')->whereIn('code', $rateCodes)->delete();
        DB::table('service_packages')->whereIn('code', $codes)->delete();
        $service = DB::table('service_types')->where('code', 'hourly_package')->whereNull('deleted_at')->first();
        if ($service) {
            $config = json_decode((string) $service->form_config, true) ?: [];
            $config['trip_mode'] = 'fixed_route';
            $config['disable_route_preview'] = false;
            $config['disable_distance_estimate'] = false;
            $config['dropoff_location'] = array_merge($config['dropoff_location'] ?? [], ['required' => true]);
            unset($config['service_package_id']);
            DB::table('service_types')->where('id', $service->id)->update([
                'form_config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
        if (Schema::hasColumn('service_packages', 'charges_extra_hours')) {
            Schema::table('service_packages', fn (Blueprint $table) => $table->dropColumn(['charges_extra_hours', 'charges_extra_km']));
        }
    }
};
