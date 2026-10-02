<?php

uses(Tests\TestCase::class);

it('searches Staff and Customer records by readable fields instead of raw identities', function () {
    $staffSource = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));
    $staffIndex = Str::between($staffSource, 'public function index(Request $request)', 'public function store(');

    $customerSource = file_get_contents(app_path('Http/Controllers/Api/CustomerController.php'));
    $customerIndex = Str::between($customerSource, 'public function index(Request $request)', 'public function search(');
    $customerSearch = Str::between($customerSource, 'public function search(Request $request)', 'public function checkEmail(');
    $customerExport = Str::between($customerSource, 'public function exportCustomers(', 'public function');

    foreach ([$staffIndex, $customerIndex, $customerSearch, $customerExport] as $search) {
        expect($search)
            ->not->toContain("whereLikeInsensitive('id', \$search)", "orWhereLikeInsensitive('id', \$search)", "orWhereLikeInsensitive('user_id', \$search)");
    }

    expect($staffIndex)->toContain("whereLikeInsensitive('code', \$search)", 'first_name');
    expect($customerIndex)->toContain("orWhereLikeInsensitive('code', \$search)", 'first_name');
    expect($customerSearch)->toContain("orWhereLikeInsensitive('code', \$search)", 'first_name');
    expect($customerExport)->toContain("orWhereLikeInsensitive('code', \$search)", 'first_name');
});
