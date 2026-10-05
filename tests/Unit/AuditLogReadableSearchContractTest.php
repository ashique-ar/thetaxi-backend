<?php

it('searches audit logs by readable fields without accepting record UUIDs', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/AuditLogController.php'));
    $search = Str::between($controller, "if (\$request->filled('search')) {", "if (\$request->filled('action')) {");
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/system/audit-logs/audit-log-list.component.html'));

    expect($search)
        ->toContain("LOWER(action)")
        ->toContain("LOWER(entity)")
        ->toContain('matchingUserIds')
        ->not->toContain('Str::isUuid')
        ->not->toContain("orWhere('entity_id'");

    expect($template)
        ->toContain('placeholder="User, action or entity type"')
        ->not->toContain('entity or ID', 'log.entity_id', 'ID: {{ log.entity_id }}');

    expect($controller)
        ->not->toContain('entity_id::text as entity_id', 'subject_id::text as entity_id', "'entity_id' => \$log->entity_id");
});
