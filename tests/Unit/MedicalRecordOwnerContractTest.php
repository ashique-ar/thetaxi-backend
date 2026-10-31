<?php

it('mounts installation-wide medical records behind private storage and dedicated permissions', function (): void {
    $root = dirname(__DIR__, 2);
    $routes = file_get_contents($root . '/routes/api.php');
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/MedicalRecordController.php');
    $model = file_get_contents($root . '/app/Models/MedicalRecord.php');

    expect($routes)->toContain("prefix('medical-records')", 'hr.medical-records.view', 'hr.medical-records.manage')
        ->and($controller)->not->toContain('activeDefaultCompany()', 'company_id')
        ->toContain("Storage::disk('local')", "'Cache-Control' => 'private, no-store'")
        ->and($controller)->not->toContain("Storage::disk('public')")
        ->and($model)->not->toContain("'company_id'")
        ->toContain("'description' => 'encrypted'");
});
