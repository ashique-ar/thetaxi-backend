<?php

it('keeps the unowned legacy medical-record surface unreachable', function () {
    $api = file_get_contents(base_path('routes/api.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/app.routes.ts'));
    $legacy = file_get_contents(app_path('Http/Controllers/Api/MedicalRecordController.php'));

    expect($api)->not->toContain("prefix' => 'medical-records", 'MedicalRecordController::class')
        ->and($portal)->not->toContain("path: 'medical-records'", 'medicalRecordsRoutes')
        ->and($legacy)->toContain("'subject_id' => 'required|string'", 'MedicalRecord::count()', "Storage::disk('public')->delete")
        ->and($legacy)->not->toContain("where('company_id'", 'lockForUpdate()', 'encrypt(');
});
