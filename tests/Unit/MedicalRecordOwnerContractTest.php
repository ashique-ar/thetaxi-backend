<?php

it('keeps medical records unmounted until their owner and tenant contract exists', function (): void {
    $root = dirname(__DIR__, 2);
    $routes = file_get_contents($root . '/routes/api.php');
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/MedicalRecordController.php');
    $model = file_get_contents($root . '/app/Models/MedicalRecord.php');

    expect($routes)->not->toContain("prefix' => 'medical-records", 'MedicalRecordController::class')
        ->and($controller)->toContain("'subject_id' => 'required|string'")
        ->and($model)->not->toContain("'company_id'");
});
