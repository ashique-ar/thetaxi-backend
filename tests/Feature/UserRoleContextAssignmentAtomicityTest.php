<?php

use App\Models\User;
use App\Models\Staff;
use App\Models\UserContext;
use App\Services\UserContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('rolls back a direct role grant when its mapped context cannot be created', function () {
    [$admin] = hr_seed_admin_actor();
    $target = User::factory()->create();
    $role = Role::create(['name' => 'unsupported-context-role', 'guard_name' => 'api']);
    $role->context_types = json_encode(['unsupported_context']);
    $role->save();

    actingAs($admin, 'api')->postJson('/api/users/'.$target->id.'/roles', [
        'roles' => [$role->name],
        'apply_to_guards' => ['api'],
    ])->assertServerError();

    $this->assertDatabaseMissing('model_has_roles', [
        'role_id' => $role->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);
});

it('reuses one Staff context when role context synchronization repeats', function () {
    [$admin] = hr_seed_admin_actor();
    $target = User::factory()->create();
    $role = Role::query()->where('name', 'staff')->where('guard_name', 'api')->firstOrFail();
    $sync = app(UserContextService::class);

    $sync->syncContextsForAssignedRoles($target, [$role], $admin->id);
    $sync->syncContextsForAssignedRoles($target->fresh(), [$role], $admin->id);

    expect(DB::table('staff')->where('user_id', $target->id)->count())->toBe(1)
        ->and(DB::table('user_contexts')->where('user_id', $target->id)->where('context_type', 'staff')->where('is_active', true)->count())->toBe(1);
});

it('serializes role revocation and retains other active User contexts', function () {
    [$admin] = hr_seed_admin_actor();
    $target = User::factory()->create();
    $role = Role::create(['name' => 'revocable-context-role', 'guard_name' => 'api']);
    $permission = Permission::query()->where('name', 'bookings.view')->where('guard_name', 'api')->firstOrFail();
    $role->givePermissionTo($permission);
    $staff = Staff::factory()->create(['user_id' => $target->id]);
    $staffContext = UserContext::create(['user_id' => $target->id, 'context_type' => 'staff', 'context_id' => $staff->id, 'is_active' => true]);
    $customerContext = UserContext::create(['user_id' => $target->id, 'context_type' => 'customer', 'context_id' => (string) Str::uuid(), 'is_active' => true]);
    DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $target->id]);
    DB::table('model_has_permissions')->insert([
        'permission_id' => $permission->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);
    DB::table('user_direct_permission_grants')->insert([
        'user_id' => $target->id,
        'permission_id' => $permission->id,
    ]);
    DB::table('user_context_permission_grants')->insert([
        'user_context_id' => $staffContext->id,
        'permission_id' => $permission->id,
    ]);
    DB::table('user_context_roles')->insert([
        ['user_context_id' => $staffContext->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_context_id' => $customerContext->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    actingAs($admin, 'api')->deleteJson('/api/users/'.$target->id.'/roles', [
        'roles' => [$role->name],
        'apply_to_guards' => ['api'],
    ])->assertOk();

    $this->assertDatabaseHas('user_contexts', ['id' => $staffContext->id, 'is_active' => true]);
    $this->assertDatabaseHas('user_contexts', ['id' => $customerContext->id, 'is_active' => true]);
    $this->assertDatabaseMissing('model_has_roles', ['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $target->id]);
    $this->assertDatabaseMissing('user_context_roles', ['user_context_id' => $staffContext->id, 'role_id' => $role->id]);
    $this->assertDatabaseMissing('user_context_roles', ['user_context_id' => $customerContext->id, 'role_id' => $role->id]);
    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $permission->id,
        'model_type' => User::class,
        'model_id' => $target->id,
    ]);
});
