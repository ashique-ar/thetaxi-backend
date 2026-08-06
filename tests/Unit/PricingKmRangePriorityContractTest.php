<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehiclePricing\KmRangePricingRule;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PricingKmRangePriorityContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('km_range_pricing_rules');
        Schema::dropIfExists('price_adjustments');
        Schema::create('km_range_pricing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope')->default('global');
            $table->uuid('service_type_id')->nullable();
            $table->uuid('vehicle_group_id')->nullable();
            $table->json('distance_types')->default(json_encode([
                'journey_distance',
                'pickup_distance',
                'delivery_distance',
            ]));
            $table->json('applicable_contexts')->nullable()->default(json_encode(['public']));
            $table->string('owner_type')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->decimal('from_km', 12, 2);
            $table->decimal('to_km', 12, 2)->nullable();
            $table->string('price_type');
            $table->decimal('rate_per_km', 12, 4)->nullable();
            $table->decimal('percentage', 12, 4)->nullable();
            $table->decimal('flat_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->dateTime('effective_from')->nullable();
            $table->dateTime('effective_to')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

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
            $table->json('applicable_contexts')->nullable();
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
        Schema::dropIfExists('km_range_pricing_rules');
        Schema::dropIfExists('price_adjustments');

        parent::tearDown();
    }

    public function test_highest_priority_matching_km_rule_takes_precedence(): void
    {
        $common = [
            'scope' => 'global',
            'distance_types' => json_encode([
                'journey_distance',
                'pickup_distance',
                'delivery_distance',
            ]),
            'from_km' => 0,
            'to_km' => 100,
            'price_type' => 'flat_addition',
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('km_range_pricing_rules')->insert([
            $common + [
                'id' => '11111111-1111-4111-8111-111111111111',
                'name' => 'High priority',
                'flat_amount' => 100,
                'priority' => 100,
            ],
            $common + [
                'id' => '22222222-2222-4222-8222-222222222222',
                'name' => 'Low priority',
                'flat_amount' => 10,
                'priority' => 1,
            ],
        ]);

        $result = KmRangePricingRule::calculateBestPricing(50, 1000);

        self::assertSame(
            '11111111-1111-4111-8111-111111111111',
            $result['rules_applied'][0]['rule_info']['id']
        );
        self::assertSame(100.0, (float) $result['total_adjustment']);
        self::assertSame(1100.0, (float) $result['final_amount']);
    }

    public function test_one_rule_can_manage_all_distance_legs(): void
    {
        $common = [
            'scope' => 'global',
            'from_km' => 0,
            'to_km' => 100,
            'price_type' => 'fixed_rate',
            'rate_per_km' => 10,
            'is_active' => true,
            'priority' => 10,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('km_range_pricing_rules')->insert($common + [
            'id' => '33333333-3333-4333-8333-333333333333',
            'name' => 'All-distance rule',
        ]);

        $journey = KmRangePricingRule::calculateBestPricing(
            40,
            0,
            distanceType: 'journey_distance'
        );
        $pickup = KmRangePricingRule::calculateBestPricing(
            8,
            0,
            distanceType: 'pickup_distance'
        );
        $delivery = KmRangePricingRule::calculateBestPricing(
            6,
            0,
            distanceType: 'delivery_distance'
        );

        self::assertSame('All-distance rule', $journey['rules_applied'][0]['rule_info']['name']);
        self::assertSame(400.0, (float) $journey['total_adjustment']);
        self::assertSame('All-distance rule', $pickup['rules_applied'][0]['rule_info']['name']);
        self::assertSame(80.0, (float) $pickup['total_adjustment']);
        self::assertSame('All-distance rule', $delivery['rules_applied'][0]['rule_info']['name']);
        self::assertSame(60.0, (float) $delivery['total_adjustment']);
    }

    public function test_unselected_distance_leg_is_not_managed_by_the_rule(): void
    {
        DB::table('km_range_pricing_rules')->insert([
            'id' => '66666666-6666-4666-8666-666666666666',
            'name' => 'Journey only',
            'scope' => 'global',
            'distance_types' => json_encode(['journey_distance']),
            'from_km' => 0,
            'to_km' => 100,
            'price_type' => 'fixed_rate',
            'rate_per_km' => 10,
            'is_active' => true,
            'priority' => 10,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pickup = KmRangePricingRule::calculateBestPricing(
            8,
            0,
            distanceType: 'pickup_distance'
        );

        self::assertSame([], $pickup['rules_applied']);
        self::assertSame(0.0, (float) $pickup['total_adjustment']);
    }

    public function test_km_rules_are_isolated_by_pricing_context(): void
    {
        $common = [
            'scope' => 'global',
            'distance_types' => json_encode(['journey_distance']),
            'from_km' => 0,
            'to_km' => 100,
            'price_type' => 'flat_addition',
            'flat_amount' => 100,
            'is_active' => true,
            'priority' => 10,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('km_range_pricing_rules')->insert([
            $common + [
                'id' => '91919191-9191-4919-8919-919191919191',
                'name' => 'Website rule',
                'applicable_contexts' => json_encode(['public']),
            ],
            $common + [
                'id' => '92929292-9292-4929-8929-929292929292',
                'name' => 'Internal rule',
                'applicable_contexts' => json_encode(['portal']),
            ],
        ]);

        $website = KmRangePricingRule::calculateBestPricing(25, pricingContext: 'public');
        $internal = KmRangePricingRule::calculateBestPricing(25, pricingContext: 'portal');

        self::assertSame('Website rule', $website['rules_applied'][0]['rule_info']['name']);
        self::assertSame('Internal rule', $internal['rules_applied'][0]['rule_info']['name']);
    }

    public function test_legacy_contextless_km_rule_defaults_to_website_only(): void
    {
        DB::table('km_range_pricing_rules')->insert([
            'id' => '93939393-9393-4939-8939-939393939393',
            'name' => 'Legacy Website rule',
            'scope' => 'global',
            'distance_types' => json_encode(['journey_distance']),
            'applicable_contexts' => null,
            'from_km' => 0,
            'to_km' => 100,
            'price_type' => 'flat_addition',
            'flat_amount' => 100,
            'is_active' => true,
            'priority' => 10,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertNotEmpty(KmRangePricingRule::calculateBestPricing(25, pricingContext: 'public')['rules_applied']);
        self::assertEmpty(KmRangePricingRule::calculateBestPricing(25, pricingContext: 'portal')['rules_applied']);
    }

    public function test_canonical_calculation_applies_selected_garage_legs_only_for_garage_to_garage(): void
    {
        DB::table('km_range_pricing_rules')->insert([
            'id' => '77777777-7777-4777-8777-777777777777',
            'name' => 'All-distance rule',
            'scope' => 'global',
            'distance_types' => json_encode([
                'journey_distance',
                'pickup_distance',
                'delivery_distance',
            ]),
            'from_km' => 0,
            'to_km' => 100,
            'price_type' => 'fixed_rate',
            'rate_per_km' => 10,
            'is_active' => true,
            'priority' => 10,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = new VehiclePricingCalculationDefinition();
        $method = new \ReflectionMethod($definition, 'applyPricingAdjustments');

        $inputs = [
            'vehicle_group_id' => '88888888-8888-4888-8888-888888888888',
            'journey_distance' => 40,
            'pickup_distance' => 8,
            'delivery_distance' => 6,
        ];

        $kmCalculations = ['journey_distance' => 40];
        $garageToGarage = $method->invoke(
            $definition,
            100,
            $inputs + ['include_garage_distance' => true],
            null,
            null,
            $kmCalculations,
            []
        );
        $journeyOnly = $method->invoke(
            $definition,
            100,
            $inputs + ['include_garage_distance' => false],
            null,
            null,
            $kmCalculations,
            []
        );

        self::assertSame(640.0, (float) $garageToGarage['final_amount']);
        self::assertSame(500.0, (float) $journeyOnly['final_amount']);
        self::assertSame(
            ['journey_distance', 'pickup_distance', 'delivery_distance'],
            collect($garageToGarage['adjustments'])
                ->where('type', 'km_range_pricing')
                ->pluck('distance_type')
                ->values()
                ->all()
        );
    }
}
