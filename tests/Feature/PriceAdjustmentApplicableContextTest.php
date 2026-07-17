<?php

use App\Models\Vehicle\VehiclePricing\PriceAdjustment;
use App\Models\Service\ServiceType;
use App\Services\BookingFlowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
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
        $table->string('adjustment_type')->default('percentage');
        $table->decimal('percentage_change', 10, 4)->nullable();
        $table->decimal('fixed_amount_change', 15, 2)->nullable();
        $table->string('applies_to')->default('total_price');
        $table->json('applicable_contexts')->nullable();
        $table->decimal('minimum_booking_amount', 15, 2)->nullable();
        $table->decimal('maximum_discount_amount', 15, 2)->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('priority')->default(0);
        $table->boolean('is_cumulative')->default(false);
        $table->dateTime('valid_from');
        $table->dateTime('valid_to');
        $table->unsignedInteger('usage_limit')->nullable();
        $table->unsignedInteger('usage_count')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function () {
    Schema::dropIfExists('price_adjustments');
});

function insertAdjustmentForContexts(string $name, ?array $contexts): void
{
    DB::table('price_adjustments')->insert([
        'id' => (string) Str::uuid(),
        'name' => $name,
        'scope' => 'global',
        'adjustment_type' => 'percentage',
        'percentage_change' => -5,
        'applies_to' => 'total_price',
        'applicable_contexts' => $contexts === null ? null : json_encode($contexts),
        'is_active' => true,
        'priority' => 10,
        'is_cumulative' => false,
        'valid_from' => now()->subDay(),
        'valid_to' => now()->addDay(),
        'usage_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('selects adjustments only for the requested pricing context and defaults legacy rows to website', function () {
    insertAdjustmentForContexts('Legacy website default', null);
    insertAdjustmentForContexts('Public only', ['public']);
    insertAdjustmentForContexts('Portal only', ['portal']);
    insertAdjustmentForContexts('Public and portal', ['public', 'portal']);
    insertAdjustmentForContexts('Corporate only', ['corporate']);

    $namesFor = fn (string $context) => PriceAdjustment::getApplicableAdjustments(
        1000,
        null,
        null,
        'total_price',
        now(),
        now(),
        null,
        null,
        $context
    )->pluck('name')->sort()->values()->all();

    expect($namesFor('public'))->toBe([
        'Legacy website default',
        'Public and portal',
        'Public only',
    ])->and($namesFor('portal'))->toBe([
        'Portal only',
        'Public and portal',
    ])->and($namesFor('corporate'))->toBe([
        'Corporate only',
    ]);

    expect(PriceAdjustment::DEFAULT_APPLICABLE_CONTEXTS)->toBe(['public']);
});

it('resolves corporate, explicit, service, and default website pricing contexts deterministically', function () {
    $flow = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
    $resolver = new ReflectionMethod(BookingFlowService::class, 'resolvePricingContext');
    $resolver->setAccessible(true);
    $publicService = new ServiceType(['context' => 'public']);

    expect($resolver->invoke($flow, [
        'corporate_account_id' => (string) Str::uuid(),
        'pricing_context' => 'public',
    ], $publicService))->toBe('corporate')
        ->and($resolver->invoke($flow, ['pricing_context' => 'public']))->toBe('public')
        ->and($resolver->invoke($flow, [], $publicService))->toBe('public')
        ->and($resolver->invoke($flow, ['pricing_context' => 'unexpected']))->toBe('public')
        ->and($resolver->invoke($flow, []))->toBe('public');
});
