<?php

it('limits opportunity reads to workflow fields and enforces owner company integrity', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.html'));
    $start = strpos($controller, 'public function opportunities(');
    $end = strpos($controller, 'public function opportunityOwnerOptions(', $start);
    $list = substr($controller, $start, $end - $start);
    $scopeStart = strpos($controller, 'private function assertOpportunityScope(');
    $scopeEnd = strpos($controller, 'private function writeConfirmation(', $scopeStart);
    $scope = substr($controller, $scopeStart, $scopeEnd - $scopeStart);
    $payloadStart = strpos($controller, 'private function opportunityPayload(');
    $payloadEnd = strpos($controller, 'private function sourceUserIds(', $payloadStart);
    $payload = substr($controller, $payloadStart, $payloadEnd - $payloadStart);

    expect($list)
        ->toContain("'company_id' => ['nullable', 'uuid', 'exists:companies,id']", 'activeDefaultCompany()?->id', "abort_unless(\$data['company_id'], 409")
        ->toContain("whereColumn('profile.company_id', 'sales_opportunities.company_id')")
        ->toContain("whereColumn('owner_staff.company_id', 'sales_opportunities.company_id')")
        ->toContain("constrainBookingOwnerToOpportunity(\$linked, 'won_booking', 'sales_opportunities')")
        ->and($scope)
        ->toContain("where('company_id', \$opportunity->company_id)", '409')
        ->and($payload)
        ->toContain("'id' => (string) \$row->id", "'company_id' => (string) \$row->company_id")
        ->toContain("'owner_sales_profile_id' => (string) \$row->owner_sales_profile_id")
        ->toContain("constrainBookingOwnerToOpportunity(\$booking, 'won_booking', 'won_opportunity')")
        ->not->toContain("'customer_id' =>", "'won_booking_id' =>", 'from_owner_sales_profile_id', 'to_owner_sales_profile_id', 'competitor_notes', 'deadline_snapshot')
        ->and($controller)
        ->toContain('linked_booking_staff.company_id', 'linked_booking_profile.staff_id')
        ->and($template)
        ->toContain('row.opportunity_number', 'row.name', 'row.expected_value_lkr', 'row.probability_percent', 'selected().company_id', 'selected().owner_sales_profile_id', 'row.reason');
});
