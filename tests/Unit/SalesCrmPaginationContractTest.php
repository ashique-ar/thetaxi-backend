<?php

it('keeps CRM list pagination filtered inside the central Sales scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));

    expect($controller)->toContain("'search' => ['nullable', 'string', 'max:100']")
        ->toContain("'activity_type' => ['nullable', Rule::in(")
        ->toContain("'status' => ['nullable', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])]")
        ->toContain("'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])]")
        ->toContain("'page' => ['nullable', 'integer', 'min:1']")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:100']")
        ->toContain("profileIds(\$request->user(), 'sales.crm.view-all', 'sales.crm.view-team')")
        ->toContain("whereIn('owner_sales_profile_id', \$ids)")
        ->toContain("whereIn('sales_profile_id', \$ids)")
        ->toContain("paginate(\$request->integer('per_page', 25))");
});
