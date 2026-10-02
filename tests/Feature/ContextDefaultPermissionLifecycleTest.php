<?php

use App\Models\User;
use App\Models\UserContext;
use App\Services\PermissionAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('preserves untracked legacy direct permissions when syncing explicit grants', function () {
    $user = User::factory()->create();
    $legacy = Permission::query()->where('name', 'bookings.view')->where('guard_name', 'api')->firstOrFail();
    DB::table('model_has_permissions')->insert([
        'permission_id' => $legacy->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);

    $assignment = app(PermissionAssignmentService::class);
    $assignment->grantDirectUserPermissions($user, ['dashboard.view']);
    $explicit = Permission::query()->where('name', 'dashboard.view')->where('guard_name', 'api')->firstOrFail();
    $assignment->syncDirectUserPermissions($user, []);

    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $legacy->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $explicit->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
    $this->assertDatabaseMissing('user_direct_permission_grants', ['user_id' => $user->id]);
});

it('removes context defaults on deactivation and keeps separately granted permissions', function () {
    [$admin] = hr_seed_admin_actor();
    $user = User::factory()->create();
    $defaultNames = ['bookings.view', 'bookings.edit', 'customers.view', 'vehicles.view', 'reports.view'];
    $defaultPermissionIds = Permission::query()->whereIn('name', $defaultNames)->pluck('id');
    DB::table('role_has_permissions')->whereIn('permission_id', $defaultPermissionIds)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $legacy = Permission::query()->where('name', 'bookings.edit')->firstOrFail();
    DB::table('model_has_permissions')->insert([
        'permission_id' => $legacy->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);

    $url = '/api/users/'.$user->id.'/contexts';
    actingAs($admin, 'api')->postJson($url.'/activate', ['context_type' => 'staff'])->assertOk();
    $context = UserContext::query()->where('user_id', $user->id)->where('context_type', 'staff')->sole();
    $ownedPermissionIds = DB::table('user_context_permission_grants')
        ->where('user_context_id', $context->id)->pluck('permission_id');
    $this->assertNotEmpty($ownedPermissionIds);

    $access = actingAs($admin, 'api')->getJson('/api/users/'.$user->id.'/permissions')->assertOk();
    $contextDefault = collect($access->json('data.permissions'))->firstWhere('id', $ownedPermissionIds->first());
    $this->assertContains('context default', $contextDefault['sources']);
    $this->assertNotContains('direct grant', $contextDefault['sources']);

    app(PermissionAssignmentService::class)->grantDirectUserPermissions($user, ['dashboard.view']);
    $independent = Permission::query()->where('name', 'dashboard.view')->where('guard_name', 'api')->firstOrFail();
    $access = actingAs($admin, 'api')->getJson('/api/users/'.$user->id.'/permissions')->assertOk();
    $this->assertContains('direct grant', collect($access->json('data.permissions'))
        ->firstWhere('id', $independent->id)['sources']);

    actingAs($admin, 'api')->postJson($url.'/deactivate', ['context_type' => 'staff'])->assertOk();

    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $ownedPermissionIds->first(),
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $legacy->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $independent->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
    $this->assertDatabaseHas('user_direct_permission_grants', [
        'user_id' => $user->id,
        'permission_id' => $independent->id,
    ]);
    $this->assertDatabaseMissing('user_context_permission_grants', ['user_context_id' => $context->id]);
    $this->assertDatabaseHas('activity_log', [
        'description' => 'context_default_permission_sources_released',
        'subject_id' => $user->id,
    ]);
    $this->assertDatabaseHas('activity_log', [
        'description' => 'direct_permission_grants_added',
        'subject_id' => $user->id,
    ]);
});

it('keeps a shared direct permission until its last active context ends', function () {
    [$admin] = hr_seed_admin_actor();
    $user = User::factory()->create();
    $defaultNames = ['bookings.view', 'bookings.create', 'profile.view', 'profile.edit', 'bookings.edit', 'customers.view', 'vehicles.view', 'reports.view'];
    $permissionIds = Permission::query()->whereIn('name', $defaultNames)->pluck('id');
    DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $url = '/api/users/'.$user->id.'/contexts';
    actingAs($admin, 'api')->postJson($url.'/activate', ['context_type' => 'staff'])->assertOk();
    actingAs($admin, 'api')->postJson($url.'/activate', ['context_type' => 'customer'])->assertOk();

    $contexts = UserContext::query()->where('user_id', $user->id)->whereIn('context_type', ['staff', 'customer'])->get();
    $sharedPermissionId = Permission::query()->where('name', 'bookings.view')->firstOrFail()->id;
    $this->assertSame(2, DB::table('user_context_permission_grants')
        ->whereIn('user_context_id', $contexts->pluck('id'))
        ->where('permission_id', $sharedPermissionId)->count());

    actingAs($admin, 'api')->postJson($url.'/deactivate', ['context_type' => 'staff'])->assertOk();
    $this->assertDatabaseHas('model_has_permissions', [
        'permission_id' => $sharedPermissionId,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);

    actingAs($admin, 'api')->postJson($url.'/deactivate', ['context_type' => 'customer'])->assertOk();
    $this->assertDatabaseMissing('model_has_permissions', [
        'permission_id' => $sharedPermissionId,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
});
