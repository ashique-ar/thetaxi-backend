<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingCalculationDefinitionController;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class HourlyPackageCalculationTesterTest extends TestCase
{
    public function test_package_test_uses_saved_allowance_and_refuses_a_missing_package(): void
    {
        Schema::create('service_packages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('service_type_id');
            $table->boolean('is_active');
            $table->decimal('max_km_per_package');
            $table->integer('default_duration_hours');
            $table->integer('default_duration_minutes');
            $table->softDeletes();
        });
        Schema::create('vehicle_pricing_slab_definitions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('service_type_id');
            $table->string('service_package_id');
            $table->boolean('is_active');
            $table->string('type');
            $table->integer('min_minutes')->nullable();
            $table->integer('max_minutes')->nullable();
            $table->integer('min_hours')->nullable();
            $table->integer('max_hours')->nullable();
            $table->integer('min_days')->nullable();
            $table->integer('max_days')->nullable();
            $table->integer('priority')->default(0);
            $table->integer('sort_order')->default(0);
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->softDeletes();
        });
        Schema::create('vehicle_group_pricing', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('vehicle_group_id');
            $table->string('slab_definition_id');
            $table->decimal('rate');
            $table->boolean('is_active');
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->softDeletes();
        });
        DB::table('service_packages')->insert([
            'id' => 'package', 'service_type_id' => 'service', 'is_active' => true,
            'max_km_per_package' => 80, 'default_duration_hours' => 8,
            'default_duration_minutes' => 0,
        ]);
        DB::table('vehicle_pricing_slab_definitions')->insert([
            'id' => 'slab', 'service_type_id' => 'service', 'service_package_id' => 'package',
            'is_active' => true, 'type' => 'flat_rate',
        ]);
        DB::table('vehicle_group_pricing')->insert([
            'id' => 'price', 'vehicle_group_id' => 'group', 'slab_definition_id' => 'slab',
            'rate' => 14500, 'is_active' => true,
        ]);

        $controller = (new ReflectionClass(VehiclePricingCalculationDefinitionController::class))
            ->newInstanceWithoutConstructor();
        $prepare = new ReflectionMethod($controller, 'prepareTrustedCalculationInputs');
        $definition = (object) ['variables' => [['name' => 'package_included_km']]];
        $inputs = ['service_package_id' => 'package', 'package_included_km' => 0,
            'package_included_hours' => 0, 'total_distance' => 50, 'duration_hours' => 8];

        $resolved = $prepare->invoke($controller, [$definition], $inputs, 'service', 'group', null, null);
        self::assertSame(80.0, $resolved['package_included_km']);
        self::assertSame(8.0, $resolved['package_included_hours']);
        self::assertSame(0, max(0, $resolved['total_distance'] - $resolved['package_included_km']));
        $evaluate = new ReflectionMethod(VehiclePricingCalculationDefinition::class, 'evaluateFormulaWithVariables');
        self::assertSame(14500.0, $evaluate->invoke(new VehiclePricingCalculationDefinition(),
            'slab_rate + max(0, total_distance - package_included_km) * extra_km_rate',
            ['slab_rate' => 14500, 'total_distance' => 50,
                'package_included_km' => $resolved['package_included_km'], 'extra_km_rate' => 180]));

        DB::table('vehicle_group_pricing')->where('id', 'price')->update(['rate' => 0]);
        try {
            $prepare->invoke($controller, [$definition], $inputs, 'service', 'group', null, null);
            self::fail('A zero package slab rate must not produce an overage-only fare.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('No active slab price', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $prepare->invoke($controller, [$definition], ['total_distance' => 50], 'service', 'group', null, null);
    }
}
