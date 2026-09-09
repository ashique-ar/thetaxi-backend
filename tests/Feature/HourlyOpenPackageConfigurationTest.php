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
        $table->uuid('created_by');
        $table->uuid('updated_by')->nullable();
        $table->string('status');
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_group_common_rate_pricing', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('vehicle_group_id');
        $table->uuid('common_rate_definition_id');
        $table->decimal('value', 12, 2)->nullable();
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->boolean('is_active')->default(true);
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
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        'id' => '20000000-0000-4000-8000-000000000001',
        'service_type_id' => '10000000-0000-4000-8000-000000000001',
        'name' => 'Hourly Package',
        'formula' => '1',
        'status' => 'active',
        'created_by' => '30000000-0000-4000-8000-000000000001',
        'priority' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('configures package-selected hourly hires as open packages', function (): void {
    $migration = require base_path('database/migrations/2026_09_09_000001_configure_hourly_open_packages.php');
    $migration->up();
    $correctiveMigration = require base_path('database/migrations/2026_09_09_000002_consolidate_hourly_package_calculation.php');
    $correctiveMigration->up();

    $config = json_decode(DB::table('service_types')->value('form_config'), true);
    expect($config['trip_mode'])->toBe('open_package')
        ->and($config['dropoff_location']['required'])->toBeFalse()
        ->and($config['service_package_id']['required'])->toBeTrue()
        ->and(DB::table('service_packages')->count())->toBe(4)
        ->and(DB::table('vehicle_pricing_calculation_definitions')->whereNull('deleted_at')->count())->toBe(1)
        ->and(DB::table('vehicle_pricing_calculation_definitions')->where('name', 'Hourly Package')->value('status'))->toBe('active')
        ->and(DB::table('vehicle_pricing_calculation_definitions')->where('name', 'like', 'Hourly Package - %')->count())->toBe(0);

    $eightHour = DB::table('service_packages')->where('code', 'hourly_8h_80km')->first();
    $distanceOnly = DB::table('service_packages')->where('code', 'hourly_100_200km')->first();
    expect((int) $eightHour->default_duration_hours)->toBe(8)
        ->and((int) $eightHour->max_km_per_package)->toBe(80)
        ->and((bool) $eightHour->is_active)->toBeFalse()
        ->and((bool) $eightHour->charges_extra_hours)->toBeTrue()
        ->and((bool) $distanceOnly->charges_extra_hours)->toBeFalse();
});

it('uses one shared formula with server-resolved selected package values', function (): void {
    $definition = new VehiclePricingCalculationDefinition([
        'service_type_id' => '10000000-0000-4000-8000-000000000001',
        'formula' => 'package_base_rate + (max(0, total_distance - package_included_km) * package_extra_km_rate * package_charges_extra_km) + (max(0, duration_hours - package_included_hours) * package_extra_hour_rate * package_charges_extra_hours)',
        'variables' => [
            ['name' => 'package_base_rate', 'type' => 'number', 'is_required' => true],
            ['name' => 'total_distance', 'type' => 'distance', 'is_required' => true],
            ['name' => 'package_included_km', 'type' => 'distance', 'is_required' => true],
            ['name' => 'package_extra_km_rate', 'type' => 'number', 'is_required' => true],
            ['name' => 'package_charges_extra_km', 'type' => 'number', 'is_required' => true],
            ['name' => 'duration_hours', 'type' => 'duration', 'is_required' => true],
            ['name' => 'package_included_hours', 'type' => 'duration', 'is_required' => true],
            ['name' => 'package_extra_hour_rate', 'type' => 'number', 'is_required' => true],
            ['name' => 'package_charges_extra_hours', 'type' => 'number', 'is_required' => true],
        ],
        'conditions' => [],
    ]);

    $method = new \ReflectionMethod(VehiclePricingCalculationDefinition::class, 'evaluateFormulaWithVariables');
    $result = $method->invoke($definition, $definition->formula, [
        'package_base_rate' => 10000,
        'total_distance' => 92, 'package_included_km' => 80,
        'package_extra_km_rate' => 100, 'package_charges_extra_km' => 1,
        'duration_hours' => 9, 'package_included_hours' => 8,
        'package_extra_hour_rate' => 500, 'package_charges_extra_hours' => 1,
    ]);
    expect($result)->toBe(11700.0);
});

it('resolves only the selected package rates for the corporate vehicle group', function (): void {
    $migration = require base_path('database/migrations/2026_09_09_000001_configure_hourly_open_packages.php');
    $migration->up();

    $corporateId = '40000000-0000-4000-8000-000000000001';
    $vehicleGroupId = '50000000-0000-4000-8000-000000000001';
    $rateValues = [
        'PACKAGE_RATE_HOURLY_8H_80KM' => 10000,
        'EXTRA_KM_RATE_HOURLY_8H_80KM' => 100,
        'EXTRA_HOUR_RATE_HOURLY_8H_80KM' => 500,
    ];
    foreach ($rateValues as $code => $value) {
        DB::table('vehicle_group_common_rate_pricing')->insert([
            'id' => fake()->uuid(),
            'vehicle_group_id' => $vehicleGroupId,
            'common_rate_definition_id' => DB::table('vehicle_pricing_common_rate_definitions')->where('code', $code)->value('id'),
            'value' => $value,
            'owner_type' => 'corporate',
            'owner_id' => $corporateId,
            'priority' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $package = DB::table('service_packages')->where('code', 'hourly_8h_80km')->first();
    $service = (new ReflectionClass(\App\Services\BookingFlowService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'resolveSelectedPackagePricingInputs');
    $resolved = $method->invoke($service, [
        'vehicle_group_id' => $vehicleGroupId,
        'owner_type' => 'corporate',
        'owner_id' => $corporateId,
    ], [
        'code' => $package->code,
        'charges_extra_hours' => true,
        'charges_extra_km' => true,
    ], '10000000-0000-4000-8000-000000000001');

    expect($resolved['package_base_rate'])->toBe(10000.0)
        ->and($resolved['package_extra_km_rate'])->toBe(100.0)
        ->and($resolved['package_extra_hour_rate'])->toBe(500.0)
        ->and($resolved['package_charges_extra_hours'])->toBe(1.0);
});
