<?php

use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropAllTables();
    Schema::create('service_types', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('code');
        $table->boolean('uses_dropoff_time')->default(true);
        $table->json('form_config')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('service_packages', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('service_type_id');
        $table->string('name');
        $table->string('code')->unique();
        $table->text('description')->nullable();
        $table->decimal('max_km_per_day')->nullable();
        $table->decimal('max_km_per_package')->nullable();
        $table->decimal('price_multiplier')->default(1);
        $table->string('rate_type')->default('flat');
        $table->integer('default_duration_hours')->nullable();
        $table->integer('default_duration_minutes')->default(0);
        $table->boolean('is_active')->default(true);
        $table->integer('sort_order')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_pricing_common_rate_definitions', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('code')->unique();
        $table->uuid('service_type_id')->nullable();
        $table->text('description')->nullable();
        $table->string('common_rate_type');
        $table->boolean('is_mandatory')->default(false);
        $table->boolean('is_active')->default(true);
        $table->integer('sort_order')->default(0);
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->string('display_unit')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('service_type_id');
        $table->string('name');
        $table->text('description')->nullable();
        $table->text('formula');
        $table->json('variables')->nullable();
        $table->json('conditions')->nullable();
        $table->string('status');
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });

    DB::table('service_types')->insert([
        'id' => '10000000-0000-4000-8000-000000000001',
        'code' => 'hourly_package',
        'uses_dropoff_time' => true,
        'form_config' => json_encode(['dropoff_location' => ['required' => true]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('configures package-selected hourly hires as open packages', function (): void {
    $migration = require base_path('database/migrations/2026_09_09_000001_configure_hourly_open_packages.php');
    $migration->up();

    $config = json_decode(DB::table('service_types')->value('form_config'), true);
    expect($config['trip_mode'])->toBe('open_package')
        ->and($config['dropoff_location']['required'])->toBeFalse()
        ->and($config['service_package_id']['required'])->toBeTrue()
        ->and(DB::table('service_packages')->count())->toBe(4)
        ->and(DB::table('vehicle_pricing_calculation_definitions')->where('status', 'draft')->count())->toBe(4);

    $eightHour = DB::table('service_packages')->where('code', 'hourly_8h_80km')->first();
    $distanceOnly = DB::table('service_packages')->where('code', 'hourly_100_200km')->first();
    expect((int) $eightHour->default_duration_hours)->toBe(8)
        ->and((int) $eightHour->max_km_per_package)->toBe(80)
        ->and((bool) $eightHour->is_active)->toBeFalse()
        ->and((bool) $eightHour->charges_extra_hours)->toBeTrue()
        ->and((bool) $distanceOnly->charges_extra_hours)->toBeFalse();
});

it('prices only the package selected at booking', function (): void {
    $definition = new VehiclePricingCalculationDefinition([
        'service_type_id' => '10000000-0000-4000-8000-000000000001',
        'formula' => 'Package_Rate + (max(0, total_distance - package_included_km) * Extra_KM_Rate) + (max(0, duration_hours - package_included_hours) * Extra_Hour_Rate)',
        'variables' => [
            ['name' => 'Package_Rate', 'type' => 'common_rate', 'is_required' => true],
            ['name' => 'total_distance', 'type' => 'distance', 'is_required' => true],
            ['name' => 'package_included_km', 'type' => 'distance', 'is_required' => true],
            ['name' => 'Extra_KM_Rate', 'type' => 'common_rate', 'is_required' => true],
            ['name' => 'duration_hours', 'type' => 'duration', 'is_required' => true],
            ['name' => 'package_included_hours', 'type' => 'duration', 'is_required' => true],
            ['name' => 'Extra_Hour_Rate', 'type' => 'common_rate', 'is_required' => true],
        ],
        'conditions' => [['field' => 'package_id', 'operator' => '=', 'value' => 'package-8h']],
    ]);

    $method = new \ReflectionMethod(VehiclePricingCalculationDefinition::class, 'evaluateFormulaWithVariables');
    $result = $method->invoke($definition, $definition->formula, [
        'Package_Rate' => 10000,
        'total_distance' => 92, 'package_included_km' => 80, 'Extra_KM_Rate' => 100,
        'duration_hours' => 9, 'package_included_hours' => 8, 'Extra_Hour_Rate' => 500,
    ]);
    expect($result)->toBe(11700.0);
});
