<?php

it('limits Sales activity reads to private fields and valid linked business records', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-work/sales-work.component.html'));
    $start = strpos($controller, 'public function activities(');
    $end = strpos($controller, 'public function recordActivity(', $start);
    $activities = substr($controller, $start, $end - $start);

    expect($activities)
        ->toContain("whereColumn('profile.id', 'sales_activities.sales_profile_id')")
        ->toContain("whereColumn('activity_staff.id', 'profile.staff_id')")
        ->toContain("whereColumn('opportunity.id', 'sales_activities.opportunity_id')")
        ->toContain("whereColumn('opportunity_staff.id', 'opportunity_owner.staff_id')")
        ->toContain("whereColumn('activity_booking.id', 'sales_activities.booking_id')")
        ->toContain("constrainBookingOwnerToOpportunity(\$linkedBooking, 'activity_booking', 'opportunity')")
        ->toContain("constrainBookingOwnerToOpportunity(\$linkedBooking, 'event_booking', 'opportunity')")
        ->toContain("'activity_type' => \$activity->activity_type")
        ->toContain("'subject' => \$activity->subject")
        ->toContain("'outcome' => \$activity->outcome")
        ->toContain("'next_action' => \$activity->next_action")
        ->toContain("'occurred_at' => \$activity->occurred_at")
        ->not->toContain('company_id')
        ->not->toContain('evidence_file_id', 'source_reference', "'sales_profile_id' => \$activity->sales_profile_id",
            "'notes' => \$activity->notes", "'company_id' => \$activity->company_id")
        ->and($controller)->toContain('linked_booking_profile.staff_id')
        ->and($template)
        ->toContain('item.subject', 'item.activity_type', 'item.outcome', 'item.next_action', 'item.occurred_at');
});
