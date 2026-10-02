<?php

it('reports an expiring_soon compliance status computed from each document type\'s own configured renewal_reminder_days window', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)
        ->toContain("if (\$status === 'satisfied' && \$type->requires_expiry && \$type->renewal_reminder_days && \$expiryDate && \$expiryDate <= now()->addDays((int) \$type->renewal_reminder_days)->toDateString())")
        ->toContain("\$status = 'expiring_soon';")
        ->toContain("'requires_expiry' => (bool) \$type->requires_expiry, 'status' => \$status, 'expiry_date' => \$expiryDate];");
});

it('never treats an unconfigured renewal_reminder_days as an implicit reminder window', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));
    $method = substr($controller, strpos($controller, 'private function documentCompliance('));
    $method = substr($method, 0, strpos($method, 'private function employeeSummary('));

    expect($method)->toContain('$type->renewal_reminder_days &&');
});

it('wires the expiring_soon status and expiry date into the Employee 360 compliance panel', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/hr-people.service.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-detail/people-detail.component.html'));

    expect($service)->toContain("status:'satisfied'|'expiring_soon'|'expired'|'missing';expiry_date?:string|null;");
    expect($template)
        ->toContain("[tone]=\"row.status==='satisfied'?'success':(row.status==='missing'?'danger':'warning')\"")
        ->toContain('@if(row.expiry_date){<p>Expires {{row.expiry_date|date:\'mediumDate\'}}</p>}');
});
