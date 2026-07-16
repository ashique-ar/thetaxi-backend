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
