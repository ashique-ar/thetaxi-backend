<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use App\Services\PermissionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('does not use global roles through a former Staff context while retaining the direct grant in other contexts', function () {
    $user = User::factory()->create();
    $former = Staff::factory()->former()->create(['user_id' => $user->id]);
    $former->delete();
    $current = Staff::factory()->create(['user_id' => $user->id]);
    $formerContext = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'staff',
        'context_id' => $former->id,
        'is_active' => true,
    ]);
    $currentContext = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'staff',
        'context_id' => $current->id,
        'is_active' => true,
    ]);
    $driverContext = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'driver',
        'context_id' => (string) Str::uuid(),
        'is_active' => true,
    ]);
    $permission = Permission::create(['name' => 'context-bound-test.view', 'guard_name' => 'api']);
    $role = Role::create(['name' => 'context-bound-test', 'guard_name' => 'api']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);
    DB::table('user_direct_role_grants')->insert(['user_id' => $user->id, 'role_id' => $role->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $evaluator = app(PermissionEvaluator::class);

    expect($evaluator->userHasAnyForRequest($user, [$permission->name], 'staff', $formerContext->id))->toBeFalse()
        ->and($evaluator->userHasAnyForRequest($user, [$permission->name], 'staff', null))->toBeFalse()
        ->and($evaluator->userHasAnyForRequest($user, [$permission->name], 'staff', $currentContext->id))->toBeTrue()
        ->and($evaluator->userHasAnyForRequest($user, [$permission->name], 'driver', $driverContext->id))->toBeTrue();
});
