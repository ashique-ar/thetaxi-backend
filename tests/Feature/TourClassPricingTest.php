<?php

namespace Tests\Feature;

use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Services\AssignmentService;
use App\Services\AvailabilityEnforcementService;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\MailDispatchService;
use App\Services\PricingVariableService;
use App\Services\Sms\SmsAutomationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class TourClassPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        activity()->disableLogging();

        Schema::create('service_types', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->string('context')->default('public');
            $table->string('pricing_basis')->default('vehicle_group');
            $table->boolean('tour_booking_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('vehicle_pricing_common_rate_definitions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('service_type_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('common_rate_type')->default('fixed_amount');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->integer('sort_order')->default(0);
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->softDeletes();
        });
        foreach (['vehicle_group_pricing', 'vehicle_group_common_rate_pricing'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->string('id')->primary();
                $table->string('vehicle_group_id')->nullable();
                $table->string('vehicle_class_id')->nullable();
                $table->string('owner_type')->nullable();
                $table->string('owner_id')->nullable();
                $table->integer('priority')->default(0);
                $table->boolean('is_active')->default(true);
                if ($name === 'vehicle_group_pricing') {
                    $table->string('slab_definition_id');
                    $table->decimal('rate', 12, 2);
                    $table->string('rate_type')->default('per_day');
                    $table->decimal('minimum_charge', 12, 2)->nullable();
                    $table->boolean('includes_driver')->default(true);
                    $table->boolean('includes_fuel')->default(true);
                } else {
                    $table->string('common_rate_definition_id');
                    $table->decimal('value', 12, 2);
                }
                $table->timestamps();
                $table->softDeletes();
            });
        }
        DB::table('service_types')->insert([
            ['id' => 'tours', 'name' => 'Tours', 'code' => 'tours', 'pricing_basis' => 'vehicle_class'],
            ['id' => 'taxi', 'name' => 'Taxi', 'code' => 'taxi', 'pricing_basis' => 'vehicle_group'],
        ]);
        DB::table('vehicle_group_pricing')->insert([
            ['id' => 'class-daily', 'vehicle_class_id' => 'standard', 'vehicle_group_id' => null, 'slab_definition_id' => 'days', 'rate' => 15000],
            ['id' => 'group-daily', 'vehicle_class_id' => null, 'vehicle_group_id' => 'group-a', 'slab_definition_id' => 'days', 'rate' => 18000],
        ]);
        DB::table('vehicle_pricing_common_rate_definitions')->insert([
            'id' => 'km', 'service_type_id' => null, 'code' => 'distance_rate', 'name' => 'Distance rate', 'common_rate_type' => 'per_km',
        ]);
        DB::table('vehicle_group_common_rate_pricing')->insert([
            ['id' => 'class-km', 'vehicle_class_id' => 'standard', 'vehicle_group_id' => null, 'common_rate_definition_id' => 'km', 'value' => 160],
            ['id' => 'group-km', 'vehicle_class_id' => null, 'vehicle_group_id' => 'group-a', 'common_rate_definition_id' => 'km', 'value' => 200],
        ]);
        Schema::create('vehicle_pricing_slab_definitions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('service_type_id');
            $table->string('service_package_id')->nullable();
            $table->string('name');
            $table->string('type')->default('per_day');
            foreach (['min_minutes', 'max_minutes', 'min_hours', 'max_hours', 'min_days', 'max_days', 'max_km_per_day', 'max_km_per_package'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->integer('priority')->default(0);
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->softDeletes();
        });
        Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('service_type_id');
            $table->string('name');
            $table->text('formula');
            $table->json('variables')->nullable();
            $table->json('conditions')->nullable();
            $table->string('status')->default('active');
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('vehicle_pricing_slab_definitions')->insert([
            'id' => 'days', 'service_type_id' => 'tours', 'name' => 'Tour days', 'min_days' => 1, 'max_km_per_day' => 100,
        ]);
        Schema::create('price_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Adjustment');
            $table->string('adjustment_type')->default('fixed_amount');
            $table->decimal('fixed_amount_change', 12, 2)->default(0);
            $table->decimal('maximum_discount_amount', 12, 2)->nullable();
            $table->boolean('is_cumulative')->default(false);
            $table->json('applicable_contexts')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->decimal('minimum_booking_amount', 12, 2)->nullable();
            $table->string('applies_to')->default('total_price');
            $table->string('service_type_id')->nullable();
            $table->string('vehicle_group_id')->nullable();
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_tours_resolve_purchased_class_rates_independently_of_assigned_group(): void
    {
        $definition = $this->definition('tours');
        $slab = (new \App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition())->forceFill(['id' => 'days']);
        foreach ([null, 'group-a', 'group-b'] as $group) {
            $inputs = ['vehicle_class_id' => 'standard', 'vehicle_group_id' => $group];
            $daily = (new ReflectionMethod($definition, 'getSlabRateValue'))->invoke($definition, $inputs, ['slab_definition' => $slab]);
            $km = (new ReflectionMethod($definition, 'getCommonRateValue'))->invoke($definition, 'distance_rate', $inputs);
            self::assertSame(15000.0, $daily);
            self::assertSame(160.0, $km);
        }
    }

    public function test_class_priced_booking_item_is_calculated_without_an_operational_group(): void
    {
        $currency = $this->createMock(CurrencyService::class);
        $currency->method('getBookingBaseCurrency')->willReturn('LKR');
        $flow = $this->getMockBuilder(BookingFlowService::class)
            ->setConstructorArgs([
                $currency,
                $this->createMock(PricingVariableService::class),
                $this->createMock(AssignmentService::class),
                $this->createMock(AvailabilityEnforcementService::class),
                $this->createMock(MailDispatchService::class),
                $this->createMock(SmsAutomationService::class),
            ])
            ->onlyMethods(['calculateDynamicPricing'])
            ->getMock();
        $flow->expects(self::once())->method('calculateDynamicPricing')->with(self::callback(
            fn (array $params): bool => ($params['vehicle_class_id'] ?? null) === 'standard'
                && empty($params['vehicle_group_id'])
                && ($params['mode'] ?? null) === 'full_calculation'
        ))->willReturn([
            'total_amount' => 52000,
            'breakdown' => [['component' => 'transport', 'amount' => 52000]],
            'calculation_metadata' => ['definition_used' => 'tour-definition'],
        ]);

        $method = new ReflectionMethod($flow, 'calculateBookingItemsPricing');
        $pricing = $method->invoke($flow, [
            'service_type_id' => 'tours',
            'currency' => 'LKR',
            'booking_items' => [[
                'id' => 'tour-item',
                'service_type_id' => 'tours',
                'vehicle_class_id' => 'standard',
                'pricing_basis' => 'vehicle_class',
                'from_date' => '2026-12-01',
                'to_date' => '2026-12-02',
                'from_time' => '00:00',
                'to_time' => '23:59',
            ]],
        ], 'LKR', 'LKR');

        self::assertSame(52000.0, $pricing['summary']['total']);
        self::assertSame(52000.0, $pricing['base_pricing']['total_amount']);
        self::assertSame(52000.0, $pricing['items']['tour-item']['total_amount']);
        self::assertSame('standard', $pricing['items']['tour-item']['vehicle_class_id']);
    }

    public function test_missing_class_rate_never_falls_back_to_assigned_group(): void
    {
        $definition = $this->definition('tours');
        $inputs = ['vehicle_class_id' => 'unpriced', 'vehicle_group_id' => 'group-a'];
        self::assertNull((new ReflectionMethod($definition, 'getCommonRateValue'))->invoke($definition, 'distance_rate', $inputs));
    }

    public function test_existing_group_service_ignores_customer_supplied_class(): void
    {
        $definition = $this->definition('taxi');
        $inputs = ['vehicle_class_id' => 'standard', 'vehicle_group_id' => 'group-a'];
        self::assertSame(200.0, (new ReflectionMethod($definition, 'getCommonRateValue'))->invoke($definition, 'distance_rate', $inputs));
    }

    public function test_missing_purchased_class_cannot_use_operational_group_for_tours(): void
    {
        $definition = $this->definition('tours');
        self::assertNull((new ReflectionMethod($definition, 'getCommonRateValue'))->invoke($definition, 'distance_rate', ['vehicle_group_id' => 'group-a']));
    }

    public function test_shared_definitions_switch_on_the_whole_hire_allowance(): void
    {
        $daily = $this->definition('tours');
        $daily->formula = 'duration_days * slab_rate';
        $daily->variables = [['name' => 'duration_days', 'type' => 'duration'], ['name' => 'slab_rate', 'type' => 'slab_rate']];
        $daily->conditions = [['field' => 'journey_distance', 'operator' => '<=', 'value' => 0, 'value_field' => 'package_included_km']];
        $km = $this->definition('tours');
        $km->formula = 'journey_distance * distance_rate';
        $km->variables = [['name' => 'journey_distance', 'type' => 'distance'], ['name' => 'distance_rate', 'type' => 'common_rate']];
        $km->conditions = [['field' => 'journey_distance', 'operator' => '>', 'value' => 0, 'value_field' => 'package_included_km']];

        foreach ([280 => 45000.0, 300 => 45000.0, 320 => 51200.0] as $distance => $expected) {
            $result = app(\App\Services\Pricing\PricingDefinitionOrchestrator::class)->resolve([$daily, $km], [
                'vehicle_class_id' => 'standard', 'duration_days' => 3, 'journey_distance' => $distance,
            ]);
            self::assertTrue($result['matched']);
            self::assertSame($expected, $result['result']['total_amount']);
        }
    }

    public function test_public_calculation_rejects_missing_class_common_rate_instead_of_returning_zero(): void
    {
        $definition = $this->definition('tours');
        $definition->formula = 'journey_distance * distance_rate';
        $definition->variables = [['name' => 'journey_distance', 'type' => 'distance'], ['name' => 'distance_rate', 'type' => 'common_rate', 'is_required' => true]];
        $result = $definition->calculatePrice(['vehicle_class_id' => 'unpriced', 'journey_distance' => 320, 'package_included_km' => 300]);
        self::assertFalse($result['calculation_success']);
        self::assertContains('distance_rate', $result['missing_variables']);
    }

    public function test_pricing_preview_and_rate_evidence_use_the_same_class_values(): void
    {
        $definition = $this->definition('tours');
        $definition->formula = 'slab_rate + common_rate_distance_rate';
        $definition->variables = [['name' => 'slab_rate', 'type' => 'slab_rate'], ['name' => 'common_rate_distance_rate', 'type' => 'common_rate']];
        $preview = $definition->getCalculationBreakdown(['vehicle_class_id' => 'standard', 'duration_days' => 3]);
        self::assertSame([], $preview['errors']);
        self::assertSame(15000.0, $preview['slab_details']['calculated_rate']);
        self::assertSame(160.0, $preview['common_rates']['distance_rate']['rate']);
        self::assertSame(15160.0, $preview['total']['total_amount']);
        self::assertSame(['standard', 'standard'], array_column($preview['total']['rate_sources'], 'vehicle_class_id'));
    }

    public function test_dispatch_group_adjustments_cannot_change_class_selling_price(): void
    {
        DB::table('price_adjustments')->insert([
            'vehicle_group_id' => 'group-a', 'service_type_id' => 'tours', 'fixed_amount_change' => 999,
            'valid_from' => '2020-01-01', 'valid_to' => '2099-01-01',
        ]);
        $definition = $this->definition('tours');
        $definition->formula = 'slab_rate';
        $definition->variables = [['name' => 'slab_rate', 'type' => 'slab_rate']];
        $result = $definition->calculatePrice(['vehicle_class_id' => 'standard', 'vehicle_group_id' => 'group-a', 'duration_days' => 3]);
        self::assertSame(15000.0, $result['total_amount']);
        self::assertSame([], $result['pricing_adjustments']);
    }

    public function test_class_rate_can_be_maintained_through_existing_rate_models(): void
    {
        $rate = \App\Models\Vehicle\VehiclePricing\VehicleGroupPricing::create([
            'vehicle_class_id' => 'economy', 'slab_definition_id' => 'days', 'rate' => 12000,
        ]);
        self::assertSame('economy', $rate->fresh()->vehicle_class_id);
    }

    public function test_existing_rate_models_reject_ambiguous_targets_before_writing(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        \App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing::create([
            'vehicle_class_id' => 'standard', 'vehicle_group_id' => 'group-a', 'common_rate_definition_id' => 'km', 'value' => 170,
        ]);
    }

    public function test_class_calculation_cannot_replace_managed_common_rates_with_input_values(): void
    {
        $definition = $this->definition('tours');
        $definition->formula = 'journey_distance * distance_rate';
        $definition->variables = [['name' => 'journey_distance', 'type' => 'distance'], ['name' => 'distance_rate', 'type' => 'common_rate']];
        $result = $definition->calculatePrice([
            'vehicle_class_id' => 'standard', 'journey_distance' => 320, 'package_included_km' => 300,
            'distance_rate' => 1,
        ]);
        self::assertSame(51200.0, $result['total_amount']);
        self::assertSame(160.0, $result['resolved_variables']['distance_rate']);
    }

    public function test_tour_final_calculation_reuses_accepted_definition_and_rate_values(): void
    {
        $definition = $this->definition('tours');
        $definition->id = 'accepted-definition';
        $definition->formula = 'slab_rate + journey_distance * distance_rate';
        $definition->variables = [
            ['name' => 'slab_rate', 'type' => 'slab_rate'],
            ['name' => 'journey_distance', 'type' => 'distance'],
            ['name' => 'distance_rate', 'type' => 'common_rate'],
        ];
        $item = new \App\Models\Booking\BookingItem();
        $item->forceFill([
            'id' => 'accepted-tour-item', 'booking_id' => 'tour-booking', 'service_type_id' => 'tours',
            'pricing_basis' => 'vehicle_class', 'metadata' => [
                'tour_itinerary_id' => 'itinerary',
                'pricing_snapshot' => ['tour_pricing_lock' => [
                    'definition_id' => 'accepted-definition',
                    'definition' => [
                        'id' => 'accepted-definition', 'formula' => $definition->formula,
                        'variables' => $definition->variables, 'conditions' => [],
                    ],
                    'resolved_variables' => ['slab_rate' => 12000, 'distance_rate' => 100],
                    'slab_information' => null,
                ]],
            ],
        ]);
        $item->exists = true;

        $result = $definition->calculatePrice([
            'vehicle_class_id' => 'standard', 'duration_days' => 1, 'journey_distance' => 10,
            '_trusted_tour_booking_item' => $item,
        ]);

        self::assertSame(13000.0, $result['total_amount']);
        self::assertSame(100.0, $result['resolved_variables']['distance_rate']);
    }

    public function test_input_comparison_does_not_match_a_missing_input_against_zero(): void
    {
        $definition = $this->definition('tours');
        $definition->formula = '1';
        $definition->conditions = [['field' => 'journey_distance', 'operator' => 'equals', 'value_field' => 'package_included_km']];
        $result = $definition->calculatePrice(['vehicle_class_id' => 'standard', 'package_included_km' => 0]);
        self::assertFalse($result['conditions_met']);
        self::assertFalse($result['calculation_success']);
    }

    public function test_existing_pricing_api_accepts_a_class_target_for_a_class_service(): void
    {
        Schema::create('vehicle_classes', fn (Blueprint $table) => $table->string('id')->primary());
        Schema::create('vehicle_groups', fn (Blueprint $table) => $table->string('id')->primary());
        $classId = '76765318-3819-45cc-af64-7437a642ddbc';
        $slabId = 'ef58ad82-b5d2-4240-8e7e-e9d4e564ac26';
        DB::table('vehicle_classes')->insert(['id' => $classId]);
        DB::table('vehicle_pricing_slab_definitions')->insert(['id' => $slabId, 'service_type_id' => 'tours', 'name' => 'Class days', 'min_days' => 1]);
        $controller = new \App\Http\Controllers\Api\Vehicle\VehiclePricing\VehicleGroupPricingController();
        $method = new ReflectionMethod($controller, 'validateVehicleGroupPricing');
        $validated = $method->invoke($controller, \Illuminate\Http\Request::create('/rates', 'POST', [
            'vehicle_class_id' => $classId, 'slab_definition_id' => $slabId, 'rate' => 14000,
        ]));
        self::assertSame($classId, $validated['vehicle_class_id']);
        self::assertSame('per_day', $validated['rate_type']);
        self::assertArrayNotHasKey('vehicle_group_id', $validated);
    }

    public function test_bulk_pricing_target_guard_keeps_class_and_group_rates_separate(): void
    {
        $controller = new \App\Http\Controllers\Api\Vehicle\VehiclePricing\VehicleGroupPricingController();
        $method = new ReflectionMethod($controller, 'assertPricingTargetMatchesService');

        $method->invoke($controller, 'vehicle_class_id', ServiceType::findOrFail('tours'));
        $method->invoke($controller, 'vehicle_group_id', ServiceType::findOrFail('taxi'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $method->invoke($controller, 'vehicle_group_id', ServiceType::findOrFail('tours'));
    }

    public function test_class_pricing_matrix_returns_class_rates_and_shared_definitions(): void
    {
        $serviceId = 'b44a4e37-a5c2-49f0-8867-350237930830';
        $classId = 'e06f9be2-365c-495f-9812-98c3ba935301';
        $slabId = '9b1659ae-09ef-4e81-90ec-12ec6f56519e';
        DB::table('service_types')->insert(['id' => $serviceId, 'pricing_basis' => 'vehicle_class', 'tour_booking_enabled' => true]);
        Schema::create('vehicle_classes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('service_type_id');
            $table->string('vehicle_class_id');
            $table->unsignedInteger('max_passengers')->default(4);
            $table->unsignedInteger('max_luggage')->default(2);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_inquiry_only')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('vehicle_classes')->insert(['id' => $classId, 'name' => 'Standard']);
        DB::table('service_vehicle_class_settings')->insert([
            'id' => 'setting', 'service_type_id' => $serviceId, 'vehicle_class_id' => $classId,
        ]);
        DB::table('vehicle_pricing_slab_definitions')->insert([
            'id' => $slabId, 'service_type_id' => $serviceId, 'name' => 'Tour day', 'min_days' => 1, 'max_km_per_day' => 100,
        ]);
        DB::table('vehicle_group_pricing')->insert([
            'id' => 'class-slab', 'vehicle_class_id' => $classId, 'vehicle_group_id' => null,
            'slab_definition_id' => $slabId, 'rate' => 15000,
        ]);
        DB::table('vehicle_group_common_rate_pricing')->insert([
            'id' => 'class-common', 'vehicle_class_id' => $classId, 'vehicle_group_id' => null,
            'common_rate_definition_id' => 'km', 'value' => 100,
        ]);
        DB::table('vehicle_pricing_calculation_definitions')->insert([
            [
                'id' => 'tour-daily', 'service_type_id' => $serviceId, 'name' => 'Tour daily rate',
                'formula' => 'duration_days * slab_rate', 'variables' => json_encode([
                    ['name' => 'duration_days', 'type' => 'duration'], ['name' => 'slab_rate', 'type' => 'slab_rate'],
                ]), 'conditions' => json_encode([
                    ['field' => 'journey_distance', 'operator' => '<=', 'value' => 0, 'value_field' => 'package_included_km'],
                ]), 'status' => 'active', 'priority' => 2,
            ],
            [
                'id' => 'tour-distance', 'service_type_id' => $serviceId, 'name' => 'Tour whole-distance rate',
                'formula' => 'journey_distance * distance_rate', 'variables' => json_encode([
                    ['name' => 'journey_distance', 'type' => 'distance'], ['name' => 'distance_rate', 'type' => 'common_rate'],
                ]), 'conditions' => json_encode([
                    ['field' => 'journey_distance', 'operator' => '>', 'value' => 0, 'value_field' => 'package_included_km'],
                ]), 'status' => 'active', 'priority' => 1,
            ],
        ]);

        $response = (new \App\Http\Controllers\Api\Vehicle\VehiclePricing\VehicleGroupPricingController())
            ->classPricingMatrix(Request::create('/rates/class-matrix', 'GET', ['service_type_id' => $serviceId]))
            ->getData(true);

        self::assertSame($classId, $response['data']['classes'][0]['id']);
        self::assertSame('class-slab', $response['data']['slab_pricing'][0]['id']);
        self::assertSame('class-common', $response['data']['common_rate_pricing'][0]['id']);
        self::assertSame('distance_rate', $response['data']['common_rate_definitions'][0]['code']);
        self::assertSame('tour_threshold_price_drop', $response['data']['threshold_price_warnings'][0]['code']);
        self::assertEquals(15000.0, $response['data']['threshold_price_warnings'][0]['daily_branch_amount']);
        self::assertEqualsWithDelta(10000.1, $response['data']['threshold_price_warnings'][0]['kilometer_branch_amount'], 0.001);

        DB::table('vehicle_group_common_rate_pricing')->where('id', 'class-common')->update(['value' => 160]);
        $response = (new \App\Http\Controllers\Api\Vehicle\VehiclePricing\VehicleGroupPricingController())
            ->classPricingMatrix(Request::create('/rates/class-matrix', 'GET', ['service_type_id' => $serviceId]))
            ->getData(true);
        self::assertSame([], $response['data']['threshold_price_warnings']);
    }

    public function test_calculation_tester_resolves_common_rates_from_the_tour_class(): void
    {
        $serviceId = 'b44a4e37-a5c2-49f0-8867-350237930830';
        $classId = 'e06f9be2-365c-495f-9812-98c3ba935301';
        DB::table('service_types')->insert(['id' => $serviceId, 'pricing_basis' => 'vehicle_class']);
        Schema::create('vehicle_classes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });
        Schema::create('service_vehicle_class_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('service_type_id');
            $table->uuid('vehicle_class_id');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
        Schema::create('website_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('vehicle_classes')->insert(['id' => $classId, 'name' => 'Standard']);
        DB::table('service_vehicle_class_settings')->insert([
            'id' => 'ce75b1f5-634e-4017-b053-0768352a8a93', 'service_type_id' => $serviceId,
            'vehicle_class_id' => $classId, 'is_active' => true,
        ]);
        DB::table('vehicle_group_common_rate_pricing')->where('id', 'class-km')->update(['vehicle_class_id' => $classId]);

        $response = app(\App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingCalculationDefinitionController::class)
            ->testCalculation(Request::create('/vehicle-pricing-calculations/test', 'POST', [
                'formula' => 'journey_distance * distance_rate',
                'variables' => [
                    ['name' => 'journey_distance', 'type' => 'distance'],
                    ['name' => 'distance_rate', 'type' => 'common_rate'],
                ],
                'test_inputs' => [
                    'service_type_id' => $serviceId,
                    'vehicle_class_id' => $classId,
                    'journey_distance' => 10,
                ],
            ]))->getData(true);

        self::assertNotNull($response['result'] ?? null, json_encode($response));
        self::assertEquals(1600, $response['result']['total_amount']);
        self::assertSame($classId, $response['inputs']['vehicle_class_id']);
    }

    public function test_class_rate_history_records_the_purchased_class_without_a_fake_group(): void
    {
        Schema::create('vehicle_pricing_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('vehicle_group_id')->nullable();
            $table->string('vehicle_class_id')->nullable();
            $table->string('service_type_id');
            $table->string('record_type')->nullable();
            $table->string('pricing_slab_definition_id')->nullable();
            $table->string('common_rate_definition_id')->nullable();
            $table->decimal('old_rate', 12, 2);
            $table->decimal('new_rate', 12, 2);
            $table->decimal('rate_change', 12, 2);
            $table->decimal('percentage_change', 12, 2);
            $table->string('change_type');
            $table->text('change_reason')->nullable();
            $table->json('old_pricing_data')->nullable();
            $table->json('new_pricing_data')->nullable();
            $table->string('changed_by')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();
            $table->softDeletes();
        });

        $history = \App\Models\Vehicle\VehiclePricingHistory::createHistoryRecord([
            'vehicle_class_id' => 'standard', 'service_type_id' => 'tours', 'old_rate' => 0, 'new_rate' => 15000,
        ]);

        self::assertSame('standard', $history->vehicle_class_id);
        self::assertNull($history->vehicle_group_id);
    }

    public function test_bulk_save_creates_class_slab_and_common_rates_with_class_history(): void
    {
        $serviceId = 'b44a4e37-a5c2-49f0-8867-350237930830';
        $classId = 'e06f9be2-365c-495f-9812-98c3ba935301';
        $slabId = '9b1659ae-09ef-4e81-90ec-12ec6f56519e';
        $commonId = '489728be-039b-49a3-8e63-8c5dad29e652';
        DB::table('service_types')->insert(['id' => $serviceId, 'pricing_basis' => 'vehicle_class']);
        DB::table('vehicle_pricing_slab_definitions')->insert([
            'id' => $slabId, 'service_type_id' => $serviceId, 'name' => 'Tour day', 'min_days' => 1,
        ]);
        DB::table('vehicle_pricing_common_rate_definitions')->insert([
            'id' => $commonId, 'service_type_id' => $serviceId, 'code' => 'tour_daily', 'name' => 'Tour daily extra',
        ]);
        Schema::create('vehicle_classes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('vehicle_classes')->insert(['id' => $classId, 'name' => 'Standard']);
        Schema::create('vehicle_pricing_bulk_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operation_type');
            $table->string('operation_name')->nullable();
            $table->text('description')->nullable();
            $table->json('operation_data')->nullable();
            $table->json('affected_records')->nullable();
            $table->string('status');
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('processed_records')->default(0);
            $table->unsignedInteger('successful_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->json('errors')->nullable();
            $table->string('initiated_by')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('vehicle_pricing_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('vehicle_group_id')->nullable();
            $table->string('vehicle_class_id')->nullable();
            $table->string('service_type_id');
            $table->string('record_type')->nullable();
            $table->string('pricing_slab_definition_id')->nullable();
            $table->string('common_rate_definition_id')->nullable();
            $table->decimal('old_rate', 12, 2);
            $table->decimal('new_rate', 12, 2);
            $table->decimal('rate_change', 12, 2);
            $table->decimal('percentage_change', 12, 2);
            $table->string('change_type');
            $table->text('change_reason')->nullable();
            $table->json('old_pricing_data')->nullable();
            $table->json('new_pricing_data')->nullable();
            $table->string('changed_by')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();
            $table->softDeletes();
        });

        $response = (new \App\Http\Controllers\Api\Vehicle\VehiclePricing\VehicleGroupPricingController())
            ->bulkSavePricing(Request::create('/rates/bulk-save', 'POST', [
                'context' => 'public',
                'slab_pricing' => [['vehicle_class_id' => $classId, 'slab_definition_id' => $slabId, 'rate' => 14000]],
                'common_rate_pricing' => [['vehicle_class_id' => $classId, 'common_rate_definition_id' => $commonId, 'value' => 155]],
            ]))
            ->getData(true);

        self::assertTrue($response['success']);
        self::assertSame($classId, DB::table('vehicle_group_pricing')->where('slab_definition_id', $slabId)->value('vehicle_class_id'));
        self::assertSame($classId, DB::table('vehicle_group_common_rate_pricing')->where('common_rate_definition_id', $commonId)->value('vehicle_class_id'));
        self::assertSame(2, DB::table('vehicle_pricing_history')->where('vehicle_class_id', $classId)->count());
        self::assertSame(0, DB::table('vehicle_pricing_history')->whereNotNull('vehicle_group_id')->count());
    }

    private function definition(string $service): VehiclePricingCalculationDefinition
    {
        $definition = new VehiclePricingCalculationDefinition(['service_type_id' => $service]);
        $definition->setRelation('serviceType', ServiceType::findOrFail($service));
        return $definition;
    }
}
