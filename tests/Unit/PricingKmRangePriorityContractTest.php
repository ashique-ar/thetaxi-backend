<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehiclePricing\KmRangePricingRule;
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
        Schema::create('km_range_pricing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope')->default('global');
            $table->uuid('service_type_id')->nullable();
            $table->uuid('vehicle_group_id')->nullable();
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('km_range_pricing_rules');

        parent::tearDown();
    }

    public function test_highest_priority_matching_km_rule_takes_precedence(): void
    {
        $common = [
            'scope' => 'global',
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
}
