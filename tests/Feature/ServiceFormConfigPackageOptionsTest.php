<?php

use App\Http\Controllers\Api\Service\ServiceFormConfigController;
use App\Models\Service\ServicePackage;
use App\Models\Service\ServiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads managed service packages into the dynamic package field', function (): void {
    $service = ServiceType::create([
        'code' => 'hourly-package-test',
        'name' => 'Hourly Package',
        'context' => 'public',
        'type' => 'with_driver',
        'pricing_mode' => 'trip',
        'uses_dropoff_time' => false,
        'form_config' => [
            'service_package_id' => [
                'type' => 'select',
                'label' => 'Hourly Package',
                'required' => true,
                'order' => 4,
                'options' => [],
            ],
        ],
    ]);
    $package = ServicePackage::create([
        'service_type_id' => $service->id,
        'name' => '9 Hours / 100 KM',
        'code' => 'hourly-9h-test',
        'rate_type' => 'hourly',
        'default_duration_hours' => 9,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $response = app(ServiceFormConfigController::class)->getFormConfig($service->id);
    $options = data_get($response->getData(true), 'data.fields.service_package_id.options');

    expect($options)->toBe([[
        'id' => $package->id,
        'value' => $package->id,
        'label' => '9 Hours / 100 KM',
        'name' => '9 Hours / 100 KM',
        'description' => null,
    ]]);
});
