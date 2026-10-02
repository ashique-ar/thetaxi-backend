<?php

it('shows permission keys without exposing their database IDs', function () {
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/permissions/list/permissions-list.component.html'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/permissions/list/permissions-list.component.ts'));

    expect($template)->toContain('matColumnDef="name"')
        ->toContain('permission.name')
        ->not->toContain('matColumnDef="id"')
        ->not->toContain('{{ permission.id }}')
        ->and($component)->toContain("['name', 'guard_name', 'created_at', 'actions']");
});
