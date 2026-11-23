<?php

use App\Models\User;
use App\Services\UserContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('keeps an explicitly internal mapped role available in both internal and staff contexts', function () {
    $user = User::factory()->create();
    $permission = Permission::create(['name' => 'roles.view', 'guard_name' => 'api']);
    $role = Role::create(['name' => 'context-mapped-admin', 'guard_name' => 'api']);
    $role->context_types = json_encode(['internal', 'staff']);
    $role->save();
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    app(UserContextService::class)->syncContextsForAssignedRoles($user, [$role]);

    $contexts = app(UserContextService::class)->buildUserContextState($user->fresh())['available_contexts'];

    expect(collect($contexts)->pluck('context_type')->all())->toContain('internal', 'staff');
});
