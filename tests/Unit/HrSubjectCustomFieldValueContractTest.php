<?php

it('shares the identical validation/encoding contract between Staff and subject custom-field values', function () {
    $staffService = file_get_contents(app_path('Services/Hr/StaffCustomFieldValueService.php'));
    $subjectService = file_get_contents(app_path('Services/Hr/SubjectCustomFieldValueService.php'));
    $trait = file_get_contents(app_path('Services/Hr/Support/CustomFieldValueValidation.php'));

    expect($staffService)->toContain('use CustomFieldValueValidation;');
    expect($subjectService)->toContain('use CustomFieldValueValidation;');
    expect($trait)->toContain('private function normalizeAndValidate(');
});

it('covers organization unit, position, and employment spell subjects with their own effective-window column', function () {
    $service = file_get_contents(app_path('Services/Hr/SubjectCustomFieldValueService.php'));

    expect($service)
        ->toContain("'organization_unit' => ['table' => 'hr_organization_units', 'from' => 'effective_from', 'until' => 'effective_until']")
        ->toContain("'position' => ['table' => 'hr_positions', 'from' => 'effective_from', 'until' => 'effective_until']")
        ->toContain("'employment_spell' => ['table' => 'hr_employment_spells', 'from' => 'joined_at', 'until' => 'terminated_at']");
});

it('never writes a subject custom-field value outside the subject\'s own retained effective period', function () {
    $service = file_get_contents(app_path('Services/Hr/SubjectCustomFieldValueService.php'));

    expect($service)->toContain('function assertSubjectCovers(array $subject, object $owner, string $date): void');
});

it('reuses the existing hr.custom-fields.values permissions rather than minting subject-specific ones', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('subjects/{ownerType}/{ownerId}/custom-fields', [PeopleCoreController::class,'subjectCustomFieldValues'])->whereUuid('ownerId')->middleware('permission:hr.custom-fields.values.view');")
        ->toContain("Route::put('subjects/{ownerType}/{ownerId}/custom-fields/{definitionId}', [PeopleCoreController::class,'putSubjectCustomFieldValue'])->whereUuid('ownerId')->whereUuid('definitionId')->middleware('permission:hr.custom-fields.values.manage');");
});
