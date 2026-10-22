<?php

it('reports inactive customers from the canonical active flag', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/ReportsController.php'));

    expect($controller)
        ->toContain("Customer::withInactive()")
        ->toContain("orderBy('customers.is_active', \$direction)")
        ->toContain("where('is_active', true)")
        ->toContain("'status' => \$customer->is_active ? 'active' : 'inactive'")
        ->not->toContain("customers.status");
});
