<?php

it('resolves Learning operations through the shared selected active Staff context', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LearningController.php'));

    expect($controller)->toContain('StaffAccessService::class)->currentActorStaff($r->user())')
        ->toContain("where('company_id',\$staff->company_id)")
        ->toContain('Learning data is outside your legal entity.');
});
