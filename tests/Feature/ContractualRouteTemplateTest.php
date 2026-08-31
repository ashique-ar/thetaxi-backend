<?php

use App\Models\Corporate\CorporateContractLocation;
use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Services\ContractualAnchorSequenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    Schema::create('corporate_contract_locations', function (Blueprint $table) { $table->uuid('id')->primary(); $table->uuid('corporate_id')->nullable(); $table->string('owner_type'); $table->string('name'); $table->text('address'); $table->decimal('latitude', 10, 7); $table->decimal('longitude', 10, 7); $table->boolean('is_active')->default(true); $table->timestamps(); $table->softDeletes(); });
});

function routePolicy(string $template, ?array $sequence = null): CorporateDistancePricingPolicy {
    $policy = new CorporateDistancePricingPolicy(['corporate_id' => 'corp-a', 'name' => 'Route policy', 'route_contract_version' => 2, 'route_template' => $template, 'route_anchor_sequence' => $sequence, 'origin_address' => 'Legacy base', 'origin_latitude' => 6.9, 'origin_longitude' => 79.8, 'include_origin_to_pickup' => true, 'include_dropoff_to_return' => true, 'movement_rate_method' => 'normal_rate', 'default_service_mode' => 'enabled', 'is_active' => true]);
    $policy->id = 'policy-a'; return $policy;
}

function routeJourney(): array { return [['address' => 'Pickup', 'latitude' => 7.0, 'longitude' => 80.0], ['address' => 'Stop', 'latitude' => 7.1, 'longitude' => 80.1], ['address' => 'Drop-off', 'latitude' => 7.2, 'longitude' => 80.2]]; }

it('expands passenger and pickup return presets with ordered booked stops', function () {
    $service = new ContractualAnchorSequenceService;
    $passenger = $service->resolve(routePolicy('passenger_journey_only'), null, routeJourney());
    $closed = $service->resolve(routePolicy('pickup_to_pickup'), null, routeJourney());
    expect(array_column($passenger['anchors'], 'type'))->toBe(['booked_pickup', 'booked_stop', 'booked_dropoff'])
        ->and(array_column($closed['anchors'], 'type'))->toBe(['booked_pickup', 'booked_stop', 'booked_dropoff', 'return_to_start'])
        ->and($closed['anchors'][3]['latitude'])->toBe($closed['anchors'][0]['latitude']);
});

it('resolves customer and operator locations only from their required owners', function () {
    CorporateContractLocation::insert([
        ['id' => '10000000-0000-4000-8000-000000000001', 'corporate_id' => 'corp-a', 'owner_type' => 'customer', 'name' => 'Customer base', 'address' => 'Customer base', 'latitude' => 6.8, 'longitude' => 79.9, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => '10000000-0000-4000-8000-000000000002', 'corporate_id' => null, 'owner_type' => 'operator', 'name' => 'Garage', 'address' => 'Garage', 'latitude' => 6.7, 'longitude' => 79.8, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $service = new ContractualAnchorSequenceService;
    $sequence = [['type' => 'customer_location', 'location_id' => '10000000-0000-4000-8000-000000000001'], ['type' => 'booked_pickup'], ['type' => 'booked_dropoff'], ['type' => 'operator_location', 'location_id' => '10000000-0000-4000-8000-000000000002']];
    $resolved = $service->resolve(routePolicy('custom_sequence', $sequence), null, routeJourney());
    expect($resolved['anchors'][0]['owner_type'])->toBe('customer')->and($resolved['anchors'][3]['owner_type'])->toBe('operator');

    $foreign = routePolicy('custom_sequence', [['type' => 'customer_location', 'location_id' => '10000000-0000-4000-8000-000000000002'], ['type' => 'booked_dropoff']]);
    expect(fn () => $service->resolve($foreign, null, routeJourney()))->toThrow(DomainException::class, 'required owner');
});

it('keeps legacy full movement on the equivalent versioned ordered route', function () {
    $resolved = (new ContractualAnchorSequenceService)->resolve(routePolicy('full_movement'), null, routeJourney());
    expect($resolved['version'])->toBe(2)->and($resolved['template'])->toBe('full_movement')
        ->and(array_column($resolved['anchors'], 'type'))->toBe(['named_contract_location', 'booked_pickup', 'booked_stop', 'booked_dropoff', 'named_contract_location']);
});

it('expands every owner-dependent preset and representative custom sequences', function () {
    CorporateContractLocation::insert([
        ['id' => '20000000-0000-4000-8000-000000000001', 'corporate_id' => 'corp-a', 'owner_type' => 'customer', 'name' => 'Customer A', 'address' => 'Customer A', 'latitude' => 6.8, 'longitude' => 79.9, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => '20000000-0000-4000-8000-000000000002', 'corporate_id' => 'corp-a', 'owner_type' => 'named_contract', 'name' => 'Customer B', 'address' => 'Customer B', 'latitude' => 6.85, 'longitude' => 79.95, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => '20000000-0000-4000-8000-000000000003', 'corporate_id' => null, 'owner_type' => 'operator', 'name' => 'Garage A', 'address' => 'Garage A', 'latitude' => 6.7, 'longitude' => 79.8, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => '20000000-0000-4000-8000-000000000004', 'corporate_id' => null, 'owner_type' => 'operator', 'name' => 'Garage B', 'address' => 'Garage B', 'latitude' => 6.75, 'longitude' => 79.85, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $definitions = [
        'customer_base_to_customer_base' => [['type' => 'customer_location', 'location_id' => '20000000-0000-4000-8000-000000000001'], ['type' => 'booked_pickup'], ['type' => 'booked_stop'], ['type' => 'booked_dropoff'], ['type' => 'named_contract_location', 'location_id' => '20000000-0000-4000-8000-000000000002']],
        'operator_base_to_dropoff' => [['type' => 'operator_location', 'location_id' => '20000000-0000-4000-8000-000000000003'], ['type' => 'booked_pickup'], ['type' => 'booked_stop'], ['type' => 'booked_dropoff']],
        'operator_base_to_operator_base' => [['type' => 'operator_location', 'location_id' => '20000000-0000-4000-8000-000000000003'], ['type' => 'booked_pickup'], ['type' => 'booked_dropoff'], ['type' => 'operator_location', 'location_id' => '20000000-0000-4000-8000-000000000004']],
        'custom_sequence' => [['type' => 'named_contract_location', 'location_id' => '20000000-0000-4000-8000-000000000002'], ['type' => 'booked_pickup'], ['type' => 'booked_stop', 'billable_to_next' => false], ['type' => 'booked_dropoff'], ['type' => 'return_to_start']],
    ];
    foreach ($definitions as $template => $definition) {
        $resolved = (new ContractualAnchorSequenceService)->resolve(routePolicy($template, $definition), null, routeJourney());
        expect($resolved['template'])->toBe($template)->and($resolved['anchors'])->toHaveCount(count($definition));
    }
});
