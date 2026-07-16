<?php

use App\Http\Controllers\Api\Vehicle\VehiclePricing\VehiclePricingSlabDefinitionController;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Services\VehiclePricingSlabConfigurationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    activity()->disableLogging();
    Schema::dropIfExists('vehicle_pricing_slab_definitions');
    Schema::dropIfExists('service_types');
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
        $table->integer('sort_order')->default(1);
        $table->integer('priority')->default(0);
        $table->string('owner_type')->nullable();
        $table->uuid('owner_id')->nullable();
        $table->boolean('is_active')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

it('resolves exact minutes before rounded hours, days, and per-day slabs', function () {
    $serviceId = (string) Str::uuid();
    insertSlab($serviceId, 'Minute', 'minutes', 0, 60);
    insertSlab($serviceId, 'Hour', 'hours', 1, 2);
    insertSlab($serviceId, 'Day', 'days', 1, 1);
    insertSlab($serviceId, 'Per day', 'per_day', 2, null);

    $resolver = app(VehiclePricingSlabConfigurationService::class);
    $query = VehiclePricingSlabDefinition::query()->where('service_type_id', $serviceId);

    expect($resolver->resolve(clone $query, 30)?->name)->toBe('Minute')
        ->and($resolver->resolve(clone $query, 61)?->name)->toBe('Hour')
        ->and($resolver->resolve(clone $query, 121)?->name)->toBe('Day')
        ->and($resolver->resolve(clone $query, 3000, 2)?->name)->toBe('Per day');
});

it('prevents activating a slab that would introduce a same-unit gap', function () {
    $serviceId = (string) Str::uuid();
    insertSlab($serviceId, 'First', 'minutes', 0, 60);
    $blockedId = insertSlab($serviceId, 'Gap', 'minutes', 120, null, false);

    $controller = new VehiclePricingSlabDefinitionController(
        app(VehiclePricingSlabConfigurationService::class)
    );
    $response = $controller->toggleStatus(Request::create('/toggle', 'PATCH'), $blockedId);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['health']['healthy'])->toBeFalse()
        ->and(VehiclePricingSlabDefinition::withInactive()->findOrFail($blockedId)->is_active)->toBeFalse();
});

it('activates one range-less per-km fallback and resolves it for every duration', function () {
    $serviceId = insertSlabTestService();
    $fallbackId = insertLegacySlab($serviceId, 'Distance fallback', 'per_km', null, null, false);

    $response = slabController()->toggleStatus(Request::create('/toggle', 'PATCH'), $fallbackId);
    $resolver = app(VehiclePricingSlabConfigurationService::class);
    $query = VehiclePricingSlabDefinition::query()->where('service_type_id', $serviceId);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['health']['healthy'])->toBeTrue()
        ->and(VehiclePricingSlabDefinition::withInactive()->findOrFail($fallbackId)->is_active)->toBeTrue()
        ->and($resolver->resolve(clone $query, 1)?->id)->toBe($fallbackId)
        ->and($resolver->resolve(clone $query, 100000)?->id)->toBe($fallbackId);
});

it('blocks activating a second range-less legacy fallback', function () {
    $serviceId = insertSlabTestService();
    insertLegacySlab($serviceId, 'Primary fallback', 'per_km', null, null);
    $duplicateId = insertLegacySlab($serviceId, 'Duplicate fallback', 'per_km', null, null, false);

    $response = slabController()->toggleStatus(Request::create('/toggle', 'PATCH'), $duplicateId);
    $codes = collect($response->getData(true)['health']['issues'])->pluck('code');

    expect($response->getStatusCode())->toBe(422)
        ->and($codes)->toContain('duplicate_duration_independent_fallback')
        ->and(VehiclePricingSlabDefinition::withInactive()->findOrFail($duplicateId)->is_active)->toBeFalse();
});

it('blocks activating overlapping legacy hour packages', function () {
    $serviceId = insertSlabTestService();
    insertLegacySlab($serviceId, 'Primary package', 'per_km', 1, 6);
    $overlapId = insertLegacySlab($serviceId, 'Overlapping package', 'per_km', 2, 12, false);

    $response = slabController()->toggleStatus(Request::create('/toggle', 'PATCH'), $overlapId);
    $codes = collect($response->getData(true)['health']['issues'])->pluck('code');

    expect($response->getStatusCode())->toBe(422)
        ->and($codes)->toContain('overlap')
        ->and(VehiclePricingSlabDefinition::withInactive()->findOrFail($overlapId)->is_active)->toBeFalse();
});

it('activates a bounded flat-rate package and resolves only its explicit duration', function () {
    $serviceId = insertSlabTestService();
    $packageId = insertLegacySlab($serviceId, 'Six-hour package', 'flat_rate', 6, 6, false);

    $response = slabController()->toggleStatus(Request::create('/toggle', 'PATCH'), $packageId);
    $resolver = app(VehiclePricingSlabConfigurationService::class);
    $query = VehiclePricingSlabDefinition::query()->where('service_type_id', $serviceId);
    $lookupResponse = slabController()->findForHours(Request::create('/find', 'GET', [
        'minutes' => 360,
        'service_type_id' => $serviceId,
    ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['health']['healthy'])->toBeTrue()
        ->and(VehiclePricingSlabDefinition::withInactive()->findOrFail($packageId)->is_active)->toBeTrue()
        ->and($resolver->resolve(clone $query, 360)?->id)->toBe($packageId)
        ->and($resolver->resolve(clone $query, 361))->toBeNull()
        ->and($lookupResponse->getData(true)['data']['id'])->toBe($packageId)
        ->and($lookupResponse->getData(true)['resolution']['precedence'])
        ->toBe(VehiclePricingSlabConfigurationService::RESOLUTION_PRECEDENCE);
});

it('blocks activating a legacy package fully shadowed by a higher-precedence duration slab', function () {
    $serviceId = insertSlabTestService();
    insertSlab($serviceId, 'All hours', 'hours', 1, null);
    $packageId = insertLegacySlab($serviceId, 'Six-hour package', 'flat_rate', 6, 6, false);

    $response = slabController()->toggleStatus(Request::create('/toggle', 'PATCH'), $packageId);
    $codes = collect($response->getData(true)['health']['issues'])->pluck('code');

    expect($response->getStatusCode())->toBe(422)
        ->and($codes)->toContain('cross_unit_fully_shadowed')
        ->and(VehiclePricingSlabDefinition::withInactive()->findOrFail($packageId)->is_active)->toBeFalse();
});

it('blocks activating a standard duration configuration with an unsafe leading gap', function () {
    $serviceId = insertSlabTestService();
    $slabId = insertSlab($serviceId, 'Starts late', 'minutes', 30, null, false);

    $response = slabController()->toggleStatus(Request::create('/toggle', 'PATCH'), $slabId);
    $codes = collect($response->getData(true)['health']['issues'])->pluck('code');

    expect($response->getStatusCode())->toBe(422)
        ->and($codes)->toContain('unsafe_leading_gap')
        ->and(VehiclePricingSlabDefinition::withInactive()->findOrFail($slabId)->is_active)->toBeFalse();
});

it('activates a minute window when an hour slab safely covers the remaining durations', function () {
    $serviceId = insertSlabTestService();
    insertSlab($serviceId, 'Hour fallback', 'hours', 1, null);
    $minuteId = insertSlab($serviceId, 'Minute window', 'minutes', 30, 60, false);

    $response = slabController()->toggleStatus(Request::create('/toggle', 'PATCH'), $minuteId);
    $resolver = app(VehiclePricingSlabConfigurationService::class);
    $query = VehiclePricingSlabDefinition::query()->where('service_type_id', $serviceId);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['health']['healthy'])->toBeTrue()
        ->and($resolver->resolve(clone $query, 45)?->id)->toBe($minuteId)
        ->and($resolver->resolve(clone $query, 10)?->name)->toBe('Hour fallback');
});

function insertSlab(
    string $serviceId,
    string $name,
    string $type,
    int $minimum,
    ?int $maximum,
    bool $active = true
): string {
    $id = (string) Str::uuid();
    [$minimumKey, $maximumKey] = match ($type) {
        'minutes' => ['min_minutes', 'max_minutes'],
        'hours' => ['min_hours', 'max_hours'],
        default => ['min_days', 'max_days'],
    };

    DB::table('vehicle_pricing_slab_definitions')->insert([
        'id' => $id,
        'service_type_id' => $serviceId,
        'name' => $name,
        'type' => $type,
        $minimumKey => $minimum,
        $maximumKey => $maximum,
        'sort_order' => 1,
        'priority' => 0,
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function insertLegacySlab(
    string $serviceId,
    string $name,
    string $type,
    ?int $minimumHours,
    ?int $maximumHours,
    bool $active = true
): string {
    $id = (string) Str::uuid();
    DB::table('vehicle_pricing_slab_definitions')->insert([
        'id' => $id,
        'service_type_id' => $serviceId,
        'name' => $name,
        'type' => $type,
        'min_hours' => $minimumHours,
        'max_hours' => $maximumHours,
        'sort_order' => 1,
        'priority' => 0,
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function insertSlabTestService(): string
{
    $id = (string) Str::uuid();
    DB::table('service_types')->insert([
        'id' => $id,
        'name' => 'Slab test service',
        'code' => 'slab-' . Str::lower(Str::random(8)),
        'context' => 'public',
        'owner_type' => '',
        'owner_id' => '',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function slabController(): VehiclePricingSlabDefinitionController
{
    return new VehiclePricingSlabDefinitionController(
        app(VehiclePricingSlabConfigurationService::class)
    );
}
