<?php

use App\Models\Company;
use App\Services\BookingFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not return a saved location without map coordinates', function () {
    $company = Company::create([
        'name' => 'Location without coordinates',
        'is_active' => true,
    ]);

    $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();

    expect($service->getCompanyLocations())->toBe([])->and($company->is_active)->toBeTrue();
});

it('returns mapped saved locations without requiring a default location', function () {
    Company::create([
        'name' => 'Location without coordinates',
        'is_active' => true,
    ]);
    Company::create([
        'name' => 'Mapped location',
        'address' => '1 Example Road',
        'latitude' => 6.9,
        'longitude' => 79.8,
        'is_active' => true,
    ]);

    $service = (new ReflectionClass(BookingFlowService::class))->newInstanceWithoutConstructor();
    $locations = $service->getCompanyLocations();

    expect($locations)->toHaveCount(1)
        ->and($locations[0]['name'])->toBe('Mapped location')
        ->and($locations[0])->not->toHaveKey('is_default');
});
