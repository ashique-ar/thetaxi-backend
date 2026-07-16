<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PricingAdjustmentComponentCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('price_adjustments');
        Schema::create('price_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope')->default('global');
            $table->uuid('service_type_id')->nullable();
            $table->uuid('vehicle_group_id')->nullable();
            $table->string('owner_type')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->string('adjustment_type');
            $table->decimal('percentage_change', 8, 4)->nullable();
            $table->decimal('fixed_amount_change', 12, 2)->nullable();
            $table->string('applies_to');
            $table->decimal('minimum_booking_amount', 12, 2)->nullable();
            $table->decimal('maximum_discount_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->boolean('is_cumulative')->default(false);
            $table->dateTime('valid_from');
            $table->dateTime('valid_to');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('price_adjustments');

        parent::tearDown();
    }

    public function test_base_price_adjustment_is_applied_by_the_canonical_calculation(): void
    {
        DB::table('price_adjustments')->insert([
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Base discount',
            'scope' => 'global',
            'adjustment_type' => 'fixed_amount',
            'fixed_amount_change' => -10,
            'applies_to' => 'base_price',
            'is_active' => true,
            'priority' => 10,
            'is_cumulative' => false,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'usage_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'base_charge';
        $definition->variables = [[
            'name' => 'base_charge',
            'type' => 'fixed_value',
            'default_value' => 100,
            'is_required' => true,
        ]];

        $result = $definition->calculatePrice([]);

        self::assertSame(90.0, $result['total_amount']);
        self::assertSame(-10.0, (float) $result['adjustment_details']['total_adjustment']);
    }

    public function test_km_charge_adjustment_changes_only_the_distance_component(): void
    {
        DB::table('price_adjustments')->insert([
            'id' => '22222222-2222-4222-8222-222222222222',
            'name' => 'Distance surcharge',
            'scope' => 'global',
            'adjustment_type' => 'percentage',
            'percentage_change' => 10,
            'applies_to' => 'km_charges',
            'is_active' => true,
            'priority' => 10,
            'is_cumulative' => false,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'usage_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'base_charge + (delivery_distance * vehicle_delivery_rate_per_km)';
        $definition->variables = [
            [
                'name' => 'base_charge',
                'type' => 'fixed_value',
                'default_value' => 200,
                'is_required' => true,
            ],
            ['name' => 'delivery_distance', 'type' => 'distance', 'is_required' => true],
            ['name' => 'vehicle_delivery_rate_per_km', 'type' => 'number', 'is_required' => true],
        ];

        $result = $definition->calculatePrice([
            'delivery_distance' => 10,
            'vehicle_delivery_rate_per_km' => 10,
        ]);

        // Base 200 + distance 100 + ten percent of distance only (10).
        self::assertSame(310.0, $result['total_amount']);
        self::assertSame(10.0, (float) $result['adjustment_details']['total_adjustment']);
        self::assertSame(
            'km_charges',
            collect($result['pricing_adjustments'])->firstWhere('type', 'price_adjustment')['applies_to']
        );
    }
}
