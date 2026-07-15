<?php

use App\Models\Corporate\CorporateDistancePricingPolicy;
use App\Models\Corporate\CorporateServiceDistancePolicy;
use App\Services\CorporateDistancePolicyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    activity()->disableLogging();

    Schema::dropIfExists('corporate_service_distance_policies');
    Schema::dropIfExists('corporate_distance_pricing_policies');

    Schema::create('corporate_distance_pricing_policies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_id');
        $table->string('name');
        $table->boolean('is_default')->default(false);
        $table->string('default_service_mode')->default('disabled');
        $table->text('origin_address');
        $table->decimal('origin_latitude', 10, 7);
        $table->decimal('origin_longitude', 10, 7);
        $table->text('return_address')->nullable();
        $table->decimal('return_latitude', 10, 7)->nullable();
        $table->decimal('return_longitude', 10, 7)->nullable();
        $table->boolean('include_origin_to_pickup')->default(true);
        $table->boolean('include_dropoff_to_return')->default(true);
        $table->string('movement_rate_method')->default('normal_rate');
        $table->decimal('outbound_rate', 12, 2)->nullable();
        $table->decimal('return_rate', 12, 2)->nullable();
        $table->decimal('maximum_outbound_km', 10, 2)->nullable();
        $table->decimal('maximum_return_km', 10, 2)->nullable();
        $table->timestamp('effective_from')->nullable();
        $table->timestamp('effective_until')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('corporate_service_distance_policies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_id');
        $table->uuid('service_type_id');
        $table->uuid('policy_id')->nullable();
        $table->string('application_mode')->default('inherit');
        $table->json('origin_location_override')->nullable();
        $table->json('return_location_override')->nullable();
        $table->boolean('include_origin_to_pickup')->nullable();
        $table->boolean('include_dropoff_to_return')->nullable();
        $table->string('movement_rate_method')->nullable();
        $table->decimal('outbound_rate', 12, 2)->nullable();
        $table->decimal('return_rate', 12, 2)->nullable();
        $table->decimal('maximum_outbound_km', 10, 2)->nullable();
        $table->decimal('maximum_return_km', 10, 2)->nullable();
        $table->timestamp('effective_from')->nullable();
        $table->timestamp('effective_until')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
});

function resolverPolicy(string $corporateId, array $attributes = []): CorporateDistancePricingPolicy
{
    return CorporateDistancePricingPolicy::create(array_replace([
        'corporate_id' => $corporateId,
        'name' => 'Default base',
        'is_default' => true,
        'default_service_mode' => 'enabled',
        'origin_address' => 'Origin',
        'origin_latitude' => 6.9270786,
        'origin_longitude' => 79.8612430,
        'movement_rate_method' => 'normal_rate',
        'is_active' => true,
    ], $attributes));
}

it('applies an effective service override before an enabled company default', function () {
    $policy = resolverPolicy('company-a');
    CorporateServiceDistancePolicy::create([
        'corporate_id' => 'company-a',
        'service_type_id' => 'service-a',
        'policy_id' => $policy->id,
        'application_mode' => 'disabled',
        'is_active' => true,
    ]);

    $resolved = app(CorporateDistancePolicyResolver::class)->resolve('company-a', 'service-a');

    expect($resolved['enabled'])->toBeFalse()
        ->and($resolved['source'])->toBe('service_override');
});

it('ignores future and expired overrides', function () {
    $at = CarbonImmutable::parse('2026-07-15 12:00:00');
    resolverPolicy('company-a');
    CorporateServiceDistancePolicy::create([
        'corporate_id' => 'company-a',
        'service_type_id' => 'service-a',
        'application_mode' => 'disabled',
        'effective_from' => $at->addDay(),
        'is_active' => true,
    ]);

    $resolved = app(CorporateDistancePolicyResolver::class)->resolve('company-a', 'service-a', $at);

    expect($resolved['enabled'])->toBeTrue()
        ->and($resolved['source'])->toBe('company_default');
});

it('rejects an enabled override that references another company policy', function () {
    resolverPolicy('company-a', ['default_service_mode' => 'disabled']);
    $foreign = resolverPolicy('company-b');
    CorporateServiceDistancePolicy::create([
        'corporate_id' => 'company-a',
        'service_type_id' => 'service-a',
        'policy_id' => $foreign->id,
        'application_mode' => 'enabled',
        'is_active' => true,
    ]);

    $resolved = app(CorporateDistancePolicyResolver::class)->resolve('company-a', 'service-a');

    expect($resolved['enabled'])->toBeTrue()
        ->and($resolved['policy'])->toBeNull()
        ->and($resolved['error'])->not->toBeNull();
});

it('keeps companies and services isolated when no matching override exists', function () {
    resolverPolicy('company-a');
    resolverPolicy('company-b', ['default_service_mode' => 'disabled']);
    CorporateServiceDistancePolicy::create([
        'corporate_id' => 'company-a',
        'service_type_id' => 'service-b',
        'application_mode' => 'disabled',
        'is_active' => true,
    ]);

    $resolved = app(CorporateDistancePolicyResolver::class)->resolve('company-a', 'service-a');

    expect($resolved['enabled'])->toBeTrue()
        ->and($resolved['source'])->toBe('company_default');
});
