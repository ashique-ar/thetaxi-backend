<?php

use Illuminate\Support\Str;

test('vehicle group move endpoint validates ownership and updates the group atomically', function () {
    $routes = file_get_contents(__DIR__ . '/../../routes/api.php');
    $controller = file_get_contents(__DIR__ . '/../../app/Http/Controllers/Api/Vehicle/VehicleGroupController.php');
    $method = Str::between($controller, 'public function moveVehicles(', 'public function destroy(');

    expect($routes)->toContain("Route::post('vehicle-groups/{vehicleGroup}/move-vehicles'")
        ->and($controller)->toContain("permission:vehicles.edit')->only(['moveVehicles'])")
        ->and($method)->toContain("Rule::exists('vehicles', 'id')->where('vehicle_group_id', \$vehicleGroup->id)")
        ->and($method)->toContain('Rule::notIn([$vehicleGroup->id])')
        ->and($method)->toContain('DB::transaction')
        ->and($method)->toContain("->where('vehicle_group_id', \$vehicleGroup->id)");
});
