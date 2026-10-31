<?php

it('loads enrollment Staff without resolving or requiring a default location', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));
    $start = strpos($controller, 'public function staffOptions(');
    $end = strpos($controller, 'public function reportingProfileOptions(', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)->not->toContain('resolveCompanyId(', 'activeDefaultCompany', 'company_id', "DB::table('companies')");
});
