<?php

it('keeps draft booking selection bounded readable and identical to write scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesCrmController.php'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-opportunities/sales-opportunities.component.ts'));

    expect($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']", 'COALESCE(owner_staff.company_id, creator_staff.company_id)', 'COALESCE(owner_staff.id, creator_staff.id)')
        ->and(substr_count($controller, '$this->linkableBookingQuery($opportunity)'))->toBe(2)
        ->and($template)->toContain('opportunity-booking-options')->not->toContain('row.booking_number||row.id')
        ->and($component)->toContain("'Unavailable Sales Profile'")->not->toContain(" : id; }");
});
