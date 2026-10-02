<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('activates and deactivates only Staff while preserving Customer and Driver contexts', function () {
    [$admin] = hr_seed_admin_actor();
    $target = User::factory()->create();
    $customer = UserContext::create(['user_id' => $target->id, 'context_type' => 'customer', 'context_id' => (string) Str::uuid(), 'is_active' => true]);
    $driver = UserContext::create(['user_id' => $target->id, 'context_type' => 'driver', 'context_id' => (string) Str::uuid(), 'is_active' => true]);
    $url = '/api/users/'.$target->id.'/contexts';

    actingAs($admin, 'api')->postJson($url.'/activate', ['context_type' => 'staff'])->assertOk();
    actingAs($admin, 'api')->postJson($url.'/activate', ['context_type' => 'staff'])->assertOk();
    $staff = Staff::query()->where('user_id', $target->id)->sole();
    $staffContext = UserContext::query()->where('user_id', $target->id)->where('context_type', 'staff')->sole();

    actingAs($admin, 'api')->postJson($url.'/deactivate', ['context_type' => 'staff'])->assertOk();

    $this->assertDatabaseHas('user_contexts', ['id' => $staffContext->id, 'is_active' => false]);
    $this->assertDatabaseHas('user_contexts', ['id' => $customer->id, 'is_active' => true]);
    $this->assertDatabaseHas('user_contexts', ['id' => $driver->id, 'is_active' => true]);
    $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    $this->assertDatabaseHas('staff', ['id' => $staff->id, 'user_id' => $target->id]);
});

it('does not grant global access when roles are assigned to an inactive context', function () {
    [$admin] = hr_seed_admin_actor();
    $target = User::factory()->create();
    $staff = Staff::factory()->create(['user_id' => $target->id]);
    $context = UserContext::create(['user_id' => $target->id, 'context_type' => 'staff', 'context_id' => $staff->id, 'is_active' => false]);
    $role = Role::query()->where('name', 'staff')->where('guard_name', 'api')->firstOrFail();

    actingAs($admin, 'api')->postJson('/api/users/'.$target->id.'/contexts/'.$context->id.'/roles', [
        'roles' => [$role->id],
        'auto_assign_permissions' => true,
    ])->assertOk();

    $this->assertDatabaseHas('user_context_roles', ['user_context_id' => $context->id, 'role_id' => $role->id]);
    $this->assertDatabaseMissing('model_has_roles', ['role_id' => $role->id, 'model_type' => User::class, 'model_id' => $target->id]);
    $this->assertDatabaseMissing('model_has_permissions', ['model_type' => User::class, 'model_id' => $target->id]);
});
