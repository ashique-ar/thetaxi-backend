<?php

it('requires one company-scoped subject when opening a lifecycle case', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LifecycleController.php'));
    $service = file_get_contents(app_path('Services/Hr/Lifecycle/LifecycleService.php'));

    expect($controller)
        ->toContain("'required_without:application_id','prohibited_with:application_id'")
        ->toContain("'required_without:staff_id','prohibited_with:staff_id'")
        ->and($service)
        ->toContain('abort_unless(($hasStaff xor $hasApplication)')
        ->toContain("whereKey(\$data['staff_id'])->where('company_id', \$data['company_id'])")
        ->toContain("where('id', \$data['application_id'])->where('company_id', \$data['company_id'])");
});

it('scopes lifecycle task completion through its parent case company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LifecycleController.php'));
    $service = file_get_contents(app_path('Services/Hr/Lifecycle/LifecycleService.php'));

    expect($controller)
        ->toContain("completeTask(\$id,\$d['evidence'],\$r->user()->id,\$this->actorCompanyId(\$r))")
        ->and($service)
        ->toContain("join('hr_lifecycle_cases as lifecycle_case'")
        ->toContain("where('lifecycle_case.company_id', \$companyId)");
});

it('scopes clearance completion through its parent exit company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LifecycleController.php'));
    $service = file_get_contents(app_path('Services/Hr/Lifecycle/LifecycleService.php'));

    expect($controller)
        ->toContain("completeClearance(\$id,\$d['resolution'],\$r->user()->id,\$this->actorCompanyId(\$r))")
        ->and($service)
        ->toContain("join('hr_exit_cases as exit_case'")
        ->toContain("where('exit_case.company_id', \$companyId)")
        ->toContain("where('id', \$item->custody_assignment_id)->where('company_id', \$companyId)");
});
