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
    foreach (['vehicle_pricing_common_rate_definitions', 'vehicle_pricing_calculation_definitions', 'service_types', 'users'] as $table) {
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
        ->and($definitionResponse->getData(true)['data']['definitions'][$definitionId])->toHaveKeys([
            'checklist', 'dependencies', 'scenario_context', 'issues', 'summary',
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
function healthTestUpdatePayload(string $serviceId, string $name, string $status): array
{
    return [
        'name' => $name,
        'service_type_id' => $serviceId,
        'formula' => 'base_charge',
        'variables' => [[
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
