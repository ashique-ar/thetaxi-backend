<?php

it('limits Sales task reads to portal fields and matching company links', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-work/sales-work.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-work/sales-work.component.ts'));
    $start = strpos($controller, 'public function tasks(');
    $end = strpos($controller, 'public function createTask(', $start);
    $tasks = substr($controller, $start, $end - $start);

    expect($tasks)
        ->toContain("whereColumn('profile.company_id', 'sales_tasks.company_id')")
        ->toContain("whereNull('profile.deleted_at')")
        ->toContain("whereColumn('opportunity.company_id', 'sales_tasks.company_id')")
        ->toContain("constrainBookingOwnerToOpportunity(\$linkedBooking, 'task_booking', 'opportunity')")
        ->toContain("'id' => (string) \$task->id")
        ->toContain("'state_version' => (int) \$task->state_version")
        ->toContain("'title' => \$task->title")
        ->toContain("'priority' => \$task->priority")
        ->toContain("'status' => \$task->status")
        ->toContain("'due_at' => \$task->due_at")
        ->not->toContain('deadline_snapshot', 'deadline_checksum', 'reminder_dispatched_at', 'created_user_id', "'owner_sales_profile_id' => \$task->owner_sales_profile_id")
        ->and($controller)
        ->toContain('linked_booking_staff.company_id', 'linked_booking_profile.staff_id')
        ->and($template)
        ->toContain('item.title', 'item.priority', 'item.due_at', 'item.status')
        ->and($component)
        ->toContain('item.id', 'item.state_version');
});
