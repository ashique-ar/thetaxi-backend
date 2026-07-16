<?php

namespace Tests\Unit;

use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Services\BookingFlowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class PricingResolvedVariableAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('price_adjustments');
        Schema::create('price_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(true);
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->decimal('minimum_booking_amount', 12, 2)->nullable();
            $table->string('applies_to')->default('total_price');
            $table->uuid('service_type_id')->nullable();
            $table->uuid('vehicle_group_id')->nullable();
            $table->string('owner_type')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('price_adjustments');

        parent::tearDown();
    }

    public function test_calculation_preserves_name_list_and_exposes_numeric_resolved_values(): void
    {
        $definition = new VehiclePricingCalculationDefinition();
        $definition->formula = 'distance_rate * actual_distance';
        $definition->variables = [
            ['name' => 'distance_rate', 'type' => 'number', 'is_required' => true],
            ['name' => 'actual_distance', 'type' => 'distance', 'is_required' => true],
        ];

        $result = $definition->calculatePrice([
            'distance_rate' => 12.5,
            'actual_distance' => 4,
        ]);

        self::assertSame(['distance_rate', 'actual_distance'], $result['variables_used']);
        self::assertSame([
            'distance_rate' => 12.5,
            'actual_distance' => 4.0,
        ], $result['resolved_variables']);
        self::assertSame(50.0, $result['total_amount']);
    }

    public function test_booking_flow_result_keeps_resolved_values_for_final_audit_consumers(): void
    {
        $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'transformCalculationResult');

        $result = $method->invoke($service, [
            'total_amount' => 50,
            'total_amount_without_customizations' => 50,
            'breakdown' => [],
            'variables_used' => ['distance_rate', 'actual_distance'],
            'resolved_variables' => [
                'distance_rate' => 12.5,
                'actual_distance' => 4.0,
            ],
            'conditions_evaluated' => [],
        ], [], 'final_calculation', null);

        self::assertSame(
            ['distance_rate' => 12.5, 'actual_distance' => 4.0],
            $result['calculation_metadata']['resolved_variables']
        );
    }
}
