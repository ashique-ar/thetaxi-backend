<?php

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;
use App\Services\UserContextService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('detaches verified direct corporate roles without removing active context or ambiguous grants', function () {
    $corporate = Corporate::create(['name' => 'Role detach company']);
    $user = User::factory()->create();
    $employee = CorporateEmployee::create([
        'user_id' => $user->id,
        'corporate_id' => $corporate->id,
        'is_active' => true,
    ]);
    $context = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'corporate',
        'context_id' => $employee->id,
        'is_active' => true,
    ]);

    $contextRole = Role::create(['name' => 'Corporate_'.$corporate->id.'_Employee', 'guard_name' => 'api']);
    $directRole = Role::create(['name' => 'Corporate_'.$corporate->id.'_Approval_Manager', 'guard_name' => 'api']);
    $legacyRole = Role::create(['name' => 'Corporate_'.$corporate->id.'_Legacy', 'guard_name' => 'api']);

    $user->assignRole($contextRole, $directRole, $legacyRole);
    app(UserService::class)->grantDirectRoleGrants($user, collect([$contextRole, $directRole]));
    app(UserContextService::class)->assignRolesToContext($user, $context, [$contextRole->id]);

    $this->artisan('corporate:detach-global-roles', ['--apply' => true])
        ->expectsOutputToContain('Removed 2 direct sources; retained 1 roles sourced by active contexts; left 1 with unverified origins for review.')
        ->assertExitCode(0);

    $this->assertDatabaseMissing('user_direct_role_grants', ['user_id' => $user->id, 'role_id' => $contextRole->id]);
    $this->assertDatabaseMissing('user_direct_role_grants', ['user_id' => $user->id, 'role_id' => $directRole->id]);
    $this->assertDatabaseHas('user_context_roles', ['user_context_id' => $context->id, 'role_id' => $contextRole->id]);
    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $contextRole->id, 'model_type' => User::class, 'model_id' => $user->id,
    ]);
    $this->assertDatabaseMissing('model_has_roles', [
        'role_id' => $directRole->id, 'model_type' => User::class, 'model_id' => $user->id,
    ]);
    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $legacyRole->id, 'model_type' => User::class, 'model_id' => $user->id,
    ]);
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'user-access', 'subject_id' => $user->id,
        'description' => 'direct_role_detached_context_role_retained',
    ]);
});
