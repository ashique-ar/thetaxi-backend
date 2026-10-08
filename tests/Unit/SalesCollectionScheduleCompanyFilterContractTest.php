<?php

it('limits schedule company options and schedule rows to the selected authorized active entity', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $optionsStart = strpos($controller, 'public function scheduleCompanyOptions');
    $bookingsStart = strpos($controller, 'public function scheduleBookings', $optionsStart);
    $scheduleStart = strpos($controller, 'public function schedule(', $bookingsStart);
    $options = substr($controller, $optionsStart, $bookingsStart - $optionsStart);
    $bookings = substr($controller, $bookingsStart, $scheduleStart - $bookingsStart);

    expect($options)
        ->toContain("where('is_active', true)")
        ->toContain('->when($companyIds !== null')
        ->toContain('activeDefaultCompany()?->id')
        ->toContain("'default_company_id' => \$defaultCompanyId")
        ->toContain('assertScheduleAttributionOwnerIntegrity(null, $profileIds)')
        ->toContain("'is_default' => (bool) \$company->is_default")
        ->and($bookings)
        ->toContain("'company_id' => ['required', 'uuid', 'exists:companies,id']")
        ->toContain("where('attribution.company_id', \$companyId)")
        ->toContain('assertScheduleAttributionOwnerIntegrity($companyId, $profileIds)')
        ->toContain('$this->companyIntegrity->assertMany(')
        ->toContain('scheduleProfileIds($request, $companyId)')
        ->and($controller)
        ->toContain("->leftJoin('sales_profiles as profile'")
        ->toContain("->leftJoin('staff as staff'")
        ->toContain("orWhereColumn('profile.company_id', '!=', 'attribution.company_id')")
        ->toContain("orWhereColumn('staff.company_id', '!=', 'attribution.company_id')")
        ->toContain('assertScheduleAttributionOwnerIntegrity((string) $attribution->company_id, null, (string) $booking->id)')
        ->and($routes)
        ->toContain("'collection-schedule-company-options', [CollectionScheduleWorkflowController::class, 'scheduleCompanyOptions']")
        ->toContain("->middleware('permission:sales.collection-schedules.revise')");
});
