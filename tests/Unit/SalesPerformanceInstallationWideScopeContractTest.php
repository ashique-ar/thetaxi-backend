<?php

it('uses Staff permissions and authorized Sales Profiles for performance reads and writes', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));

    expect($controller)
        ->toContain('$this->scope->profileIds(', '$this->scope->assertProfile(', '$this->assertTargetProfilesScope(')
        ->not->toContain('resolveCompanyId(', 'activeDefaultCompany', 'company_id', 'assertCompanyWideScope(');
});
