<?php

it('keeps medical record exports and reminder endpoints out of the mounted contract', function () {
    $api = file_get_contents(base_path('routes/api.php'));
    $portal = file_get_contents(base_path('../portal-thetaxi/src/app/app.routes.ts'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/MedicalRecordController.php'));

    expect($api)->toContain("prefix('medical-records')", 'hr.medical-records.view', 'hr.medical-records.manage')
        ->and($portal)->toContain("path: 'medical-records'", 'medicalRecordsRoutes')
        ->and($controller)->toContain('activeDefaultCompany()', 'Crypt::encryptString', "Storage::disk('local')")
        ->and($controller)->not->toContain('sendExpiryReminders', 'bulkExport', "Storage::disk('public')");
});
