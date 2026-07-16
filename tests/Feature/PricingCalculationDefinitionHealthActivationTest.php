<?php

use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingCalculationDefinitionController;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    foreach ([
        'vehicle_group_common_rate_pricing', 'vehicle_group_pricing',
        'vehicle_pricing_slab_definitions', 'vehicle_pricing_common_rate_definitions',
        'vehicle_pricing_calculation_definitions', 'service_types', 'users',
        'vehicle_group_service_pricing_settings', 'vehicle_groups',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('service_types', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('code');
        $table->string('context')->default('public');
        $table->string('owner_type')->default('');
        $table->string('owner_id')->default('');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->timestamps();
    });
    Schema::create('vehicle_groups', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->boolean('is_inquiry_only')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_group_service_pricing_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('vehicle_group_id');
        $table->uuid('service_type_id');
        $table->boolean('is_inquiry_only')->default(false);
        $table->boolean('is_hidden')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_pricing_calculation_definitions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->text('description')->nullable();
        $table->uuid('service_type_id');
        $table->string('status')->default('draft');
        $table->text('formula');
        $table->json('variables')->nullable();
        $table->json('conditions')->nullable();
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_pricing_common_rate_definitions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('service_type_id')->nullable();
        $table->uuid('vehicle_group_id')->nullable();
        $table->string('code');
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('common_rate_type')->default('fixed_amount');
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->boolean('is_mandatory')->default(false);
        $table->boolean('is_active')->default(true);
        $table->integer('sort_order')->default(0);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_pricing_slab_definitions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('service_type_id');
        $table->string('name');
        $table->string('type')->nullable();
        $table->integer('min_minutes')->nullable();
        $table->integer('max_minutes')->nullable();
        $table->integer('min_hours')->nullable();
        $table->integer('max_hours')->nullable();
        $table->integer('min_days')->nullable();
        $table->integer('max_days')->nullable();
        $table->integer('max_km_per_day')->nullable();
        $table->integer('max_km_per_package')->nullable();
        $table->integer('sort_order')->default(0);
        $table->integer('priority')->default(0);
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->boolean('is_active')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_group_pricing', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('slab_definition_id');
        $table->uuid('vehicle_group_id');
        $table->decimal('rate', 12, 2)->nullable();
        $table->string('rate_type')->default('flat_rate');
        $table->decimal('minimum_charge', 12, 2)->nullable();
        $table->boolean('includes_fuel')->default(false);
        $table->boolean('includes_driver')->default(false);
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->boolean('is_active')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('vehicle_group_common_rate_pricing', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('vehicle_group_id');
        $table->uuid('common_rate_definition_id');
        $table->decimal('value', 12, 2)->nullable();
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->integer('priority')->default(0);
        $table->boolean('is_mandatory')->default(false);
        $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $this->serviceId = (string) Str::uuid();
    DB::table('service_types')->insert([
        'id' => $this->serviceId,
        'name' => 'Rental',
        'code' => 'rental',
        'context' => 'public',
        'owner_type' => '',
        'owner_id' => '',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('blocks an ambiguous active candidate and leaves its status unchanged', function () {
    healthTestInsertCalculationDefinition($this->serviceId, 'Primary', 'active');
    $candidateId = healthTestInsertCalculationDefinition($this->serviceId, 'Candidate', 'inactive');
    $controller = app(VehiclePricingCalculationDefinitionController::class);

    $response = $controller->update(
        Request::create('/calculation-definitions/' . $candidateId, 'PUT', healthTestUpdatePayload(
            $this->serviceId,
            'Candidate',
            'active'
        )),
        $candidateId
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['health']['ready_for_activation'])->toBeFalse()
        ->and($response->getData(true)['health']['focus_issues'][0]['code'])->toBe('ambiguous_active_candidates')
        ->and(VehiclePricingCalculationDefinition::findOrFail($candidateId)->status)->toBe('inactive');
});

it('allows deactivation even when it leaves no active candidate', function () {
    $definitionId = healthTestInsertCalculationDefinition($this->serviceId, 'Primary', 'active');
    $controller = app(VehiclePricingCalculationDefinitionController::class);

    $response = $controller->update(
        Request::create('/calculation-definitions/' . $definitionId, 'PUT', healthTestUpdatePayload(
            $this->serviceId,
            'Primary',
            'inactive'
        )),
        $definitionId
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(VehiclePricingCalculationDefinition::findOrFail($definitionId)->status)->toBe('inactive');
});

it('blocks slab-rate activation when active slab dependencies are missing', function () {
    $definitionId = healthTestInsertCalculationDefinition($this->serviceId, 'Slab pricing', 'inactive');
    $controller = app(VehiclePricingCalculationDefinitionController::class);
    $payload = healthTestUpdatePayload($this->serviceId, 'Slab pricing', 'active', 'slab_rate', [[
        'name' => 'slab_rate',
        'type' => 'slab_rate',
        'is_required' => true,
    ]]);

    $response = $controller->update(Request::create('/definition', 'PUT', $payload), $definitionId);
    $codes = collect($response->getData(true)['health']['focus_issues'])->pluck('code');

    expect($response->getStatusCode())->toBe(422)
        ->and($codes)->toContain('slab_no_active_slabs')
        ->and(VehiclePricingCalculationDefinition::findOrFail($definitionId)->status)->toBe('inactive');
});

it('blocks common-rate activation when the shared rate has no active values', function () {
    $definitionId = healthTestInsertCalculationDefinition($this->serviceId, 'Waiting pricing', 'inactive');
    DB::table('vehicle_pricing_common_rate_definitions')->insert([
        'id' => (string) Str::uuid(),
        'service_type_id' => $this->serviceId,
        'code' => 'waiting_charge',
        'name' => 'Waiting charge',
        'common_rate_type' => 'fixed_amount',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $controller = app(VehiclePricingCalculationDefinitionController::class);
    $payload = healthTestUpdatePayload($this->serviceId, 'Waiting pricing', 'active', 'waiting_charge', [[
        'name' => 'waiting_charge',
        'type' => 'common_rate',
        'is_required' => true,
    ]]);

    $response = $controller->update(Request::create('/definition', 'PUT', $payload), $definitionId);
    $codes = collect($response->getData(true)['health']['focus_issues'])->pluck('code');

    expect($response->getStatusCode())->toBe(422)
        ->and($codes)->toContain('missing_common_rate_values')
        ->and(VehiclePricingCalculationDefinition::findOrFail($definitionId)->status)->toBe('inactive');
});

it('activates when referenced slab and common-rate dependencies are complete', function () {
    $definitionId = healthTestInsertCalculationDefinition($this->serviceId, 'Complete pricing', 'inactive');
    $vehicleGroupId = (string) Str::uuid();
    $slabId = (string) Str::uuid();
    $commonRateId = (string) Str::uuid();
    DB::table('vehicle_pricing_slab_definitions')->insert([
        'id' => $slabId,
        'service_type_id' => $this->serviceId,
        'name' => 'All minutes',
        'type' => 'minutes',
        'min_minutes' => 0,
        'max_minutes' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('vehicle_group_pricing')->insert([
        'id' => (string) Str::uuid(),
        'slab_definition_id' => $slabId,
        'vehicle_group_id' => $vehicleGroupId,
        'rate' => 1000,
        'rate_type' => 'flat_rate',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('vehicle_pricing_common_rate_definitions')->insert([
        'id' => $commonRateId,
        'service_type_id' => $this->serviceId,
        'code' => 'waiting_charge',
        'name' => 'Waiting charge',
        'common_rate_type' => 'fixed_amount',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('vehicle_group_common_rate_pricing')->insert([
        'id' => (string) Str::uuid(),
        'vehicle_group_id' => $vehicleGroupId,
        'common_rate_definition_id' => $commonRateId,
        'value' => 50,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $controller = app(VehiclePricingCalculationDefinitionController::class);
    $payload = healthTestUpdatePayload(
        $this->serviceId,
        'Complete pricing',
        'active',
        'slab_rate + waiting_charge',
        [
            ['name' => 'slab_rate', 'type' => 'slab_rate', 'is_required' => true],
            ['name' => 'waiting_charge', 'type' => 'common_rate', 'is_required' => true],
        ]
    );

    $response = $controller->update(Request::create('/definition', 'PUT', $payload), $definitionId);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['configuration_health']['ready_for_activation'])->toBeTrue()
        ->and(VehiclePricingCalculationDefinition::findOrFail($definitionId)->status)->toBe('active');
});

it('requires public slab and common-rate values for every applicable vehicle group', function () {
    $definitionId = healthTestInsertCalculationDefinition($this->serviceId, 'Coverage pricing', 'inactive');
    $publicGroupId = (string) Str::uuid();
    $overrideOnlyGroupId = (string) Str::uuid();
    $corporateId = (string) Str::uuid();
    foreach ([
        $publicGroupId => 'Public group',
        $overrideOnlyGroupId => 'Override-only group',
    ] as $id => $name) {
        DB::table('vehicle_groups')->insert([
            'id' => $id,
            'name' => $name,
            'is_active' => true,
            'is_inquiry_only' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $slabId = (string) Str::uuid();
    $commonRateId = (string) Str::uuid();
    DB::table('vehicle_pricing_slab_definitions')->insert([
        'id' => $slabId,
        'service_type_id' => $this->serviceId,
        'name' => 'All minutes',
        'type' => 'minutes',
        'min_minutes' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('vehicle_pricing_common_rate_definitions')->insert([
        'id' => $commonRateId,
        'service_type_id' => $this->serviceId,
        'code' => 'waiting_charge',
        'name' => 'Waiting charge',
        'common_rate_type' => 'fixed_amount',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ([
        [$publicGroupId, null, null],
        [$overrideOnlyGroupId, 'corporate', $corporateId],
    ] as [$vehicleGroupId, $ownerType, $ownerId]) {
        DB::table('vehicle_group_pricing')->insert([
            'id' => (string) Str::uuid(),
            'slab_definition_id' => $slabId,
            'vehicle_group_id' => $vehicleGroupId,
            'rate' => 1000,
            'rate_type' => 'flat_rate',
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('vehicle_group_common_rate_pricing')->insert([
            'id' => (string) Str::uuid(),
            'vehicle_group_id' => $vehicleGroupId,
            'common_rate_definition_id' => $commonRateId,
            'value' => 50,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $payload = healthTestUpdatePayload(
        $this->serviceId,
        'Coverage pricing',
        'active',
        'slab_rate + waiting_charge',
        [
            ['name' => 'slab_rate', 'type' => 'slab_rate', 'is_required' => true],
            ['name' => 'waiting_charge', 'type' => 'common_rate', 'is_required' => true],
        ]
    );
    $response = app(VehiclePricingCalculationDefinitionController::class)
        ->update(Request::create('/definition', 'PUT', $payload), $definitionId);
    $codes = collect($response->getData(true)['health']['focus_issues'])->pluck('code');

    expect($response->getStatusCode())->toBe(422)
        ->and($codes)->toContain('missing_public_slab_group_values')
        ->and($codes)->toContain('missing_public_common_rate_group_values');
});

it('returns reusable service and definition health response fields', function () {
    $definitionId = healthTestInsertCalculationDefinition($this->serviceId, 'Primary', 'active');
    $controller = app(VehiclePricingCalculationDefinitionController::class);

    $serviceResponse = $controller->health(Request::create('/health', 'GET', [
        'service_type_id' => $this->serviceId,
    ]));
    $definitionResponse = $controller->definitionHealth($definitionId);

    expect($serviceResponse->getStatusCode())->toBe(200)
        ->and($serviceResponse->getData(true)['data'])->toHaveKeys([
            'healthy', 'ready_for_activation', 'service_type_id', 'active_candidate_count',
            'selection_order', 'definitions', 'issues', 'focus_issues', 'summary',
        ])
        ->and($definitionResponse->getData(true)['data'])->toHaveKeys([
            'definition_id', 'ready_for_activation', 'checklist', 'dependencies',
            'scenario_context', 'issues', 'summary', 'service_health',
        ]);
});

it('registers health before the dynamic definition route', function () {
    $route = Route::getRoutes()->match(Request::create(
        '/api/vehicles/calculation-definitions/health',
        'GET',
        ['service_type_id' => $this->serviceId]
    ));

    expect($route->getActionMethod())->toBe('health');
});

it('publishes the final-pricing runtime variables used by formulas and conditions', function () {
    $controller = app(VehiclePricingCalculationDefinitionController::class);
    $response = $controller->getAvailableVariables($this->serviceId);
    $variables = collect($response->getData(true)['data'])->keyBy('name');

    expect($response->getStatusCode())->toBe(200);
    foreach ([
        'number_of_days', 'journey_distance', 'actual_distance', 'package_included_km',
        'manual_additional_charge', 'late_return_fee', 'is_weekend', 'is_holiday',
        'month', 'day_of_week', 'customer_type',
    ] as $name) {
        expect($variables)->toHaveKey($name);
    }
    expect($variables['customer_type']['condition_only'])->toBeTrue()
        ->and($variables['customer_type']['formula_allowed'])->toBeFalse();
});

function healthTestInsertCalculationDefinition(string $serviceId, string $name, string $status): string
{
    $id = (string) Str::uuid();
    DB::table('vehicle_pricing_calculation_definitions')->insert([
        'id' => $id,
        'name' => $name,
        'service_type_id' => $serviceId,
        'status' => $status,
        'formula' => 'base_charge',
        'variables' => json_encode([[
            'name' => 'base_charge',
            'type' => 'fixed_value',
            'default_value' => 100,
            'is_required' => true,
        ]]),
        'conditions' => json_encode([]),
        'priority' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** @return array<string, mixed> */
function healthTestUpdatePayload(
    string $serviceId,
    string $name,
    string $status,
    string $formula = 'base_charge',
    ?array $variables = null
): array
{
    return [
        'name' => $name,
        'service_type_id' => $serviceId,
        'formula' => $formula,
        'variables' => $variables ?? [[
            'name' => 'base_charge',
            'type' => 'fixed_value',
            'default_value' => 100,
            'is_required' => true,
        ]],
        'conditions' => [],
        'status' => $status,
        'priority' => 0,
        'context' => 'public',
    ];
}
