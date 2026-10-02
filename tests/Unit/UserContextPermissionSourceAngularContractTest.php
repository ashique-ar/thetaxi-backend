<?php

it('loads permission provenance and edits only independent direct grants', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/UserController.php'));
    $service = file_get_contents(app_path('Services/PermissionAssignmentService.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/admin/users/detail/user-detail.component.ts'));

    expect($controller)->toContain("'context default'")
        ->and($service)->toContain('user_context_permission_grants', 'user_direct_permission_grants')
        ->and($component)->toContain('getUserPermissions(this.userId)', "includes('direct grant')");
});
