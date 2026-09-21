<?php

use App\Http\Controllers\Api\Service\ServiceFormConfigController;

it('loads managed service packages into the dynamic package field', function (): void {
    $method = new ReflectionMethod(ServiceFormConfigController::class, 'withPackageFieldOptions');
    $fields = $method->invoke(null, [
        'package_id' => [
            'type' => 'select',
            'label' => 'Package',
            'required' => true,
            'options' => [],
        ],
        'service_package_id' => [
            'type' => 'select',
            'label' => 'Hourly Package',
            'required' => true,
            'options' => [],
        ],
    ], [[
        'id' => 'package-9h',
        'name' => '9 Hours / 100 KM',
        'description' => 'Corporate hourly package',
    ]]);

    expect($fields['service_package_id']['options'])->toBe([[
        'id' => 'package-9h',
        'value' => 'package-9h',
        'label' => '9 Hours / 100 KM',
        'name' => '9 Hours / 100 KM',
        'description' => 'Corporate hourly package',
    ]])->and($fields['package_id']['options'])->toBe($fields['service_package_id']['options']);
});
