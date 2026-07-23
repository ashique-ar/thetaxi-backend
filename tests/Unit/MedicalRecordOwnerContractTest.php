<?php

it('keeps medical records owned by guarded persisted record routes', function (): void {
    $root = dirname(__DIR__, 2);
    $routes = file_get_contents($root . '/routes/api.php');
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/MedicalRecordController.php');
    $model = file_get_contents($root . '/app/Models/MedicalRecord.php');

    expect($routes)->toContain("Route::group(['prefix' => 'medical-records']")
        ->and($routes)->toContain("Route::get('/', [MedicalRecordController::class, 'index'])")
        ->and($routes)->toContain("Route::post('/', [MedicalRecordController::class, 'store'])")
        ->and($routes)->toContain("Route::get('/stats', [MedicalRecordController::class, 'getStats'])")
        ->and($routes)->toContain("Route::get('/categories', [MedicalRecordController::class, 'getCategories'])")
        ->and($routes)->toContain("Route::get('/compliance-report', [MedicalRecordController::class, 'getComplianceReport'])")
        ->and($routes)->toContain("permission:medical-records.view")
        ->and($routes)->toContain("permission:medical-records.create")
        ->and($routes)->toContain("permission:medical-records.edit")
        ->and($routes)->toContain("permission:medical-records.delete")
        ->and($controller)->toContain('MedicalRecord::')
        ->and($controller)->toContain("'data' => \$records")
        ->and($controller)->toContain("'data' => \$record")
        ->and($model)->toContain("protected \$table = 'medical_records'");
});
