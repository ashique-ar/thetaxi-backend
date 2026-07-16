<?php

use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinitionController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    activity()->disableLogging();

    foreach ([
        'booking_common_rate_pricings',
        'booking_pricings',
        'vehicle_pricing_history',
        'vehicle_group_common_rate_pricing',
        'vehicle_group_pricing',
        'vehicle_pricing_calculation_definitions',
        'vehicle_pricing_common_rate_definitions',
        'vehicle_pricing_slab_definitions',
        'vehicle_groups',
        'corporates',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('vehicle_pricing_common_rate_definitions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('service_type_id')->nullable();
        $table->string('vehicle_group_id')->nullable();
        $table->string('code')->nullable();
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('common_rate_type');
        $table->boolean('is_mandatory')->default(false);
        $table->boolean('is_active')->default(true);
        $table->integer('sort_order')->default(0);
        $table->string('owner_type')->nullable();
        $table->string('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->string('created_user_id')->nullable();
        $table->string('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('vehicle_groups', function (Blueprint $table): void {
        $table->string('id')->primary();
    });

    Schema::create('corporates', function (Blueprint $table): void {
        $table->string('id')->primary();
    });

    Schema::create('vehicle_pricing_slab_definitions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('service_type_id');
        $table->string('name');
        $table->string('type');
        $table->integer('min_minutes')->nullable();
        $table->integer('max_minutes')->nullable();
        $table->integer('min_hours')->nullable();
        $table->integer('max_hours')->nullable();
        $table->integer('min_days')->nullable();
        $table->integer('max_days')->nullable();
        $table->integer('max_km_per_day')->nullable();
        $table->integer('max_km_per_package')->nullable();
        $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->string('owner_type')->nullable();
        $table->string('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('service_type_id');
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('status');
        $table->text('formula');
        $table->json('variables')->nullable();
        $table->json('conditions')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('vehicle_group_pricing', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('slab_definition_id');
        $table->string('vehicle_group_id');
        $table->decimal('rate', 10, 2)->nullable();
        $table->string('rate_type');
        $table->decimal('minimum_charge', 10, 2)->nullable();
        $table->boolean('includes_fuel')->default(false);
        $table->boolean('includes_driver')->default(false);
        $table->boolean('is_active')->default(true);
        $table->string('owner_type')->nullable();
        $table->string('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('vehicle_group_common_rate_pricing', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('common_rate_definition_id');
        $table->string('vehicle_group_id');
        $table->decimal('value', 10, 2)->nullable();
        $table->boolean('is_active')->default(true);
        $table->string('owner_type')->nullable();
        $table->string('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_pricings', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('slab_definition_id');
        $table->string('vehicle_group_pricing_id');
    });

    Schema::create('booking_common_rate_pricings', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('common_rate_definition_id');
        $table->string('vehicle_group_common_rate_pricing_id')->nullable();
    });

    Schema::create('vehicle_pricing_history', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('pricing_slab_definition_id')->nullable();
        $table->string('common_rate_definition_id')->nullable();
    });
});

afterEach(function (): void {
    foreach ([
        'booking_common_rate_pricings',
        'booking_pricings',
        'vehicle_pricing_history',
        'vehicle_group_common_rate_pricing',
        'vehicle_group_pricing',
        'vehicle_pricing_calculation_definitions',
        'vehicle_pricing_common_rate_definitions',
        'vehicle_pricing_slab_definitions',
        'vehicle_groups',
        'corporates',
    ] as $table) {
        Schema::dropIfExists($table);
    }
});

it('keeps definitions global while preserving corporate vehicle-group prices and references', function (): void {
    $now = now();

    $globalCommonDefinition = pricingCommonDefinition('common-global', null, null, $now);
    $corporateCommonDefinition = pricingCommonDefinition('common-corporate', 'corporate', 'corporate-1', $now);
    $corporateCommonDefinition['vehicle_group_id'] = 'group-1';
    DB::table('vehicle_pricing_common_rate_definitions')->insert([
        $globalCommonDefinition,
        $corporateCommonDefinition,
    ]);

    $globalSlabDefinition = pricingSlabDefinition('slab-global', null, null, $now);
    $corporateSlabDefinition = pricingSlabDefinition('slab-corporate', 'corporate', 'corporate-1', $now);
    $corporateSlabDefinition['name'] = 'Corporate First Hour';
    DB::table('vehicle_pricing_slab_definitions')->insert([
        $globalSlabDefinition,
        $corporateSlabDefinition,
    ]);

    DB::table('vehicle_group_common_rate_pricing')->insert([
        pricingCommonValue('common-value-global', 'common-global', 10, null, null, $now),
        pricingCommonValue('common-value-corporate', 'common-corporate', 25, 'corporate', 'corporate-1', $now),
    ]);

    DB::table('vehicle_group_pricing')->insert([
        pricingSlabValue('slab-value-global', 'slab-global', 100, null, null, $now),
        pricingSlabValue('slab-value-corporate', 'slab-corporate', 80, 'corporate', 'corporate-1', $now),
    ]);

    $globalCalculation = pricingCalculationDefinition('calculation-global', 'common-global', null, null, $now);
    $corporateCalculation = pricingCalculationDefinition('calculation-corporate', 'common-corporate', 'corporate', 'corporate-1', $now);
    $corporateCalculation['name'] = 'Corporate Distance Calculation';
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        $globalCalculation,
        $corporateCalculation,
    ]);

    DB::table('booking_pricings')->insert([
        'id' => 'booking-price',
        'slab_definition_id' => 'slab-corporate',
        'vehicle_group_pricing_id' => 'slab-value-corporate',
    ]);
    DB::table('booking_common_rate_pricings')->insert([
        'id' => 'booking-common-price',
        'common_rate_definition_id' => 'common-corporate',
        'vehicle_group_common_rate_pricing_id' => 'common-value-corporate',
    ]);
    DB::table('vehicle_pricing_history')->insert([
        'id' => 'history',
        'pricing_slab_definition_id' => 'slab-corporate',
        'common_rate_definition_id' => 'common-corporate',
    ]);

    pricingOwnershipMigration()->up();

    foreach ([
        'vehicle_pricing_common_rate_definitions',
        'vehicle_pricing_slab_definitions',
        'vehicle_pricing_calculation_definitions',
    ] as $table) {
        expect(DB::table($table)->whereNotNull('owner_type')->orWhereNotNull('owner_id')->count())->toBe(0);
    }

    expect(DB::table('vehicle_pricing_common_rate_definitions')
        ->where('id', 'common-global')
        ->value('vehicle_group_id'))->toBeNull();

    $corporateCommonPrice = DB::table('vehicle_group_common_rate_pricing')
        ->where('id', 'common-value-corporate')
        ->first();
    expect($corporateCommonPrice->common_rate_definition_id)->toBe('common-global')
        ->and((float) $corporateCommonPrice->value)->toBe(25.0)
        ->and($corporateCommonPrice->owner_type)->toBe('corporate')
        ->and($corporateCommonPrice->owner_id)->toBe('corporate-1')
        ->and($corporateCommonPrice->deleted_at)->toBeNull();

    $corporateSlabPrice = DB::table('vehicle_group_pricing')
        ->where('id', 'slab-value-corporate')
        ->first();
    expect($corporateSlabPrice->slab_definition_id)->toBe('slab-global')
        ->and((float) $corporateSlabPrice->rate)->toBe(80.0)
        ->and($corporateSlabPrice->owner_type)->toBe('corporate')
        ->and($corporateSlabPrice->deleted_at)->toBeNull();

    expect(DB::table('booking_pricings')->value('slab_definition_id'))->toBe('slab-global')
        ->and(DB::table('booking_common_rate_pricings')->value('common_rate_definition_id'))->toBe('common-global')
        ->and(DB::table('vehicle_pricing_history')->value('pricing_slab_definition_id'))->toBe('slab-global')
        ->and(DB::table('vehicle_pricing_history')->value('common_rate_definition_id'))->toBe('common-global');

    $legacyCalculation = DB::table('vehicle_pricing_calculation_definitions')
        ->where('id', 'calculation-corporate')
        ->first();
    expect(data_get(json_decode($legacyCalculation->variables, true), '0.source_id'))->toBe('common-global')
        ->and($legacyCalculation->deleted_at)->not->toBeNull();

    expect(fn () => DB::table('vehicle_group_common_rate_pricing')->insert(
        pricingCommonValue('duplicate-corporate-value', 'common-global', 30, 'corporate', 'corporate-1', now())
    ))->toThrow(QueryException::class);
});

it('reconciles mandatory flag differences without changing common-rate semantics', function (): void {
    $now = now();
    $global = pricingCommonDefinition('common-global', null, null, $now);
    $global['is_mandatory'] = false;
    $owned = pricingCommonDefinition('common-owned', 'corporate', 'corporate-1', $now);
    $owned['is_mandatory'] = true;

    DB::table('vehicle_pricing_common_rate_definitions')->insert([$global, $owned]);

    pricingOwnershipMigration()->up();

    expect(DB::table('vehicle_pricing_common_rate_definitions')->where('id', 'common-global')->value('is_mandatory'))
        ->toBe(1)
        ->and(DB::table('vehicle_pricing_common_rate_definitions')->where('id', 'common-owned')->value('deleted_at'))
        ->not->toBeNull();
});

it('reports the exact unsafe common-rate type difference', function (): void {
    $now = now();
    $global = pricingCommonDefinition('common-global', null, null, $now);
    $owned = pricingCommonDefinition('common-owned', 'corporate', 'corporate-1', $now);
    $owned['common_rate_type'] = 'fixed_amount';

    DB::table('vehicle_pricing_common_rate_definitions')->insert([$global, $owned]);

    expect(fn () => pricingOwnershipMigration()->up())
        ->toThrow(RuntimeException::class, "common_rate_type='per_km' versus 'fixed_amount'");
});

it('fails and rolls back when corporate and shared definitions cannot be merged safely', function (): void {
    $now = now();
    $global = pricingCommonDefinition('common-global', null, null, $now);
    $corporate = pricingCommonDefinition('common-corporate', 'corporate', 'corporate-1', $now);
    $corporate['common_rate_type'] = 'per_hour';

    DB::table('vehicle_pricing_common_rate_definitions')->insert([$global, $corporate]);
    Schema::table('vehicle_pricing_common_rate_definitions', function (Blueprint $table): void {
        $table->unique(['id'], 'unique_common_rate_definition_code_owner');
    });

    expect(fn () => pricingOwnershipMigration()->up())
        ->toThrow(RuntimeException::class, 'Unsafe common-rate definition conflict');

    expect(DB::table('vehicle_pricing_common_rate_definitions')
        ->where('id', 'common-corporate')
        ->value('owner_type'))->toBe('corporate')
        ->and(collect(Schema::getIndexes('vehicle_pricing_common_rate_definitions'))
            ->pluck('name')->all())->toContain('unique_common_rate_definition_code_owner');
});

it('globalizes multiple active calculation formulas for deterministic runtime orchestration', function (): void {
    $now = now();
    $first = pricingCalculationDefinition(
        'calculation-corporate-one',
        'common-source',
        'corporate',
        'corporate-1',
        $now,
    );
    $first['name'] = 'Corporate Distance One';
    $first['priority'] = 20;

    $second = pricingCalculationDefinition(
        'calculation-corporate-two',
        'common-source',
        'corporate',
        'corporate-2',
        $now,
    );
    $second['name'] = 'Corporate Distance Two';
    $second['formula'] = '(extra_km_rate * extra_km) + 100';
    $second['priority'] = 10;

    DB::table('vehicle_pricing_calculation_definitions')->insert([$first, $second]);

    pricingOwnershipMigration()->up();

    expect(DB::table('vehicle_pricing_calculation_definitions')
        ->whereNull('owner_type')
        ->whereNull('owner_id')
        ->where('status', 'active')
        ->count())->toBe(2)
        ->and(DB::table('vehicle_pricing_calculation_definitions')
            ->where('id', 'calculation-corporate-one')
            ->value('priority'))->toBe(20)
        ->and(DB::table('vehicle_pricing_calculation_definitions')
            ->where('id', 'calculation-corporate-two')
            ->value('priority'))->toBe(10);
});

it('rejects owner-conditioned calculation definitions instead of globalizing them', function (): void {
    $definition = pricingCalculationDefinition(
        'owner-conditioned-calculation',
        'common-source',
        'corporate',
        'corporate-1',
        now(),
    );
    $definition['conditions'] = json_encode([[
        'field' => 'owner_id',
        'operator' => 'equals',
        'value' => 'corporate-1',
    ]]);
    DB::table('vehicle_pricing_calculation_definitions')->insert($definition);

    expect(fn () => pricingOwnershipMigration()->up())
        ->toThrow(RuntimeException::class, 'conditions pricing structure on a corporate owner');

    expect(DB::table('vehicle_pricing_calculation_definitions')
        ->where('id', 'owner-conditioned-calculation')
        ->value('owner_type'))->toBe('corporate');
});

it('hides legacy owned definitions and normalizes owner fields on model writes', function (): void {
    $now = now();
    DB::table('vehicle_pricing_common_rate_definitions')->insert([
        pricingCommonDefinition('global-visible', null, null, $now, 'visible_rate'),
        pricingCommonDefinition('legacy-hidden', 'corporate', 'corporate-1', $now, 'hidden_rate'),
    ]);

    expect(VehiclePricingCommonRateDefinition::withInactive()->pluck('id')->all())
        ->toBe(['global-visible']);

    $created = VehiclePricingCommonRateDefinition::create([
        'name' => 'Attempted Owned Definition',
        'code' => 'attempted_owned',
        'common_rate_type' => 'fixed_amount',
        'is_mandatory' => false,
        'is_active' => true,
        'sort_order' => 0,
        'priority' => 0,
        'owner_type' => 'corporate',
        'owner_id' => 'corporate-1',
        'vehicle_group_id' => 'group-1',
    ]);

    $stored = DB::table('vehicle_pricing_common_rate_definitions')->where('id', $created->id)->first();
    expect($stored->owner_type)->toBeNull()
        ->and($stored->owner_id)->toBeNull()
        ->and($stored->vehicle_group_id)->toBeNull();
});

it('calculates common-rate previews from scoped vehicle-group values', function (): void {
    $now = now();
    $rateId = '11111111-1111-4111-8111-111111111111';
    $vehicleGroupId = '22222222-2222-4222-8222-222222222222';
    $corporateId = '33333333-3333-4333-8333-333333333333';

    DB::table('vehicle_groups')->insert(['id' => $vehicleGroupId]);
    DB::table('corporates')->insert(['id' => $corporateId]);
    DB::table('vehicle_pricing_common_rate_definitions')->insert(
        pricingCommonDefinition($rateId, null, null, $now)
    );
    DB::table('vehicle_group_common_rate_pricing')->insert([
        pricingCommonValue('common-value-global', $rateId, 10, null, null, $now, $vehicleGroupId),
        pricingCommonValue('common-value-corporate', $rateId, 25, 'corporate', $corporateId, $now, $vehicleGroupId),
    ]);

    $response = (new VehiclePricingCommonRateDefinitionController())->calculatePreview(
        Request::create('/preview', 'POST', [
            'rate_id' => $rateId,
            'vehicle_group_id' => $vehicleGroupId,
            'base_amount' => 0,
            'kilometers' => 4,
            'context' => 'corporate',
            'owner_type' => 'corporate',
            'owner_id' => $corporateId,
        ])
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['data']['calculated_amount'])->toEqual(100.0)
        ->and($response->getData(true)['data']['pricing_value']['id'])->toBe('common-value-corporate');

    DB::table('vehicle_group_common_rate_pricing')->where('id', 'common-value-corporate')->delete();

    $fallbackResponse = (new VehiclePricingCommonRateDefinitionController())->calculatePreview(
        Request::create('/preview', 'POST', [
            'rate_id' => $rateId,
            'vehicle_group_id' => $vehicleGroupId,
            'base_amount' => 0,
            'kilometers' => 4,
            'context' => 'corporate',
            'owner_type' => 'corporate',
            'owner_id' => $corporateId,
        ])
    );

    expect($fallbackResponse->getStatusCode())->toBe(200)
        ->and($fallbackResponse->getData(true)['data']['calculated_amount'])->toEqual(40.0)
        ->and($fallbackResponse->getData(true)['data']['pricing_value']['id'])->toBe('common-value-global');

    DB::table('vehicle_group_common_rate_pricing')->delete();

    $missingResponse = (new VehiclePricingCommonRateDefinitionController())->calculatePreview(
        Request::create('/preview', 'POST', [
            'rate_id' => $rateId,
            'vehicle_group_id' => $vehicleGroupId,
            'base_amount' => 0,
            'kilometers' => 4,
        ])
    );

    expect($missingResponse->getStatusCode())->toBe(422)
        ->and($missingResponse->getData(true)['message'])->toContain('No active Pricing Management value');
});

it('resolves a global common-rate definition during the canonical formula runtime', function (): void {
    $now = now();
    $rateId = '44444444-4444-4444-8444-444444444444';
    $vehicleGroupId = '55555555-5555-4555-8555-555555555555';
    $definitionRow = pricingCommonDefinition($rateId, null, null, $now, 'global_waiting_rate');
    $definitionRow['service_type_id'] = null;

    DB::table('vehicle_groups')->insert(['id' => $vehicleGroupId]);
    DB::table('vehicle_pricing_common_rate_definitions')->insert($definitionRow);
    DB::table('vehicle_group_common_rate_pricing')->insert(
        pricingCommonValue('global-waiting-value', $rateId, 75, null, null, $now, $vehicleGroupId)
    );

    $calculation = new VehiclePricingCalculationDefinition([
        'service_type_id' => 'service-1',
    ]);
    $method = new ReflectionMethod($calculation, 'getCommonRateValue');

    expect($method->invoke($calculation, 'global_waiting_rate', [
        'vehicle_group_id' => $vehicleGroupId,
    ]))->toEqual(75.0);
});

function pricingOwnershipMigration(): Migration
{
    return require base_path('database/migrations/2026_07_16_150000_reconcile_global_pricing_definitions.php');
}

function pricingCommonDefinition(
    string $id,
    ?string $ownerType,
    ?string $ownerId,
    mixed $now,
    string $code = 'extra_km_rate',
): array {
    return [
        'id' => $id,
        'service_type_id' => 'service-1',
        'vehicle_group_id' => null,
        'code' => $code,
        'name' => 'Extra KM Rate',
        'description' => null,
        'common_rate_type' => 'per_km',
        'is_mandatory' => true,
        'is_active' => true,
        'sort_order' => 1,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'priority' => 10,
        'created_user_id' => null,
        'updated_user_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ];
}

function pricingSlabDefinition(string $id, ?string $ownerType, ?string $ownerId, mixed $now): array
{
    return [
        'id' => $id,
        'service_type_id' => 'service-1',
        'name' => 'First Hour',
        'type' => 'minutes',
        'min_minutes' => 0,
        'max_minutes' => 60,
        'min_hours' => null,
        'max_hours' => null,
        'min_days' => null,
        'max_days' => null,
        'max_km_per_day' => null,
        'max_km_per_package' => null,
        'sort_order' => 1,
        'is_active' => true,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'priority' => 10,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ];
}

function pricingCalculationDefinition(
    string $id,
    string $sourceId,
    ?string $ownerType,
    ?string $ownerId,
    mixed $now,
): array {
    return [
        'id' => $id,
        'service_type_id' => 'service-1',
        'name' => 'Distance Calculation',
        'description' => null,
        'status' => 'active',
        'formula' => 'extra_km_rate * extra_km',
        'variables' => json_encode([[
            'name' => 'extra_km_rate',
            'type' => 'common_rate',
            'source_id' => $sourceId,
            'is_required' => true,
        ]]),
        'conditions' => json_encode([]),
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'priority' => 10,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ];
}

function pricingCommonValue(
    string $id,
    string $definitionId,
    float $value,
    ?string $ownerType,
    ?string $ownerId,
    mixed $now,
    string $vehicleGroupId = 'group-1',
): array {
    return [
        'id' => $id,
        'common_rate_definition_id' => $definitionId,
        'vehicle_group_id' => $vehicleGroupId,
        'value' => $value,
        'is_active' => true,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'priority' => $ownerType ? 100 : 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ];
}

function pricingSlabValue(
    string $id,
    string $definitionId,
    float $rate,
    ?string $ownerType,
    ?string $ownerId,
    mixed $now,
): array {
    return [
        'id' => $id,
        'slab_definition_id' => $definitionId,
        'vehicle_group_id' => 'group-1',
        'rate' => $rate,
        'rate_type' => 'flat_rate',
        'minimum_charge' => null,
        'includes_fuel' => true,
        'includes_driver' => true,
        'is_active' => true,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'priority' => $ownerType ? 100 : 0,
        'created_at' => $now,
        'updated_at' => $now,
        'deleted_at' => null,
    ];
}
