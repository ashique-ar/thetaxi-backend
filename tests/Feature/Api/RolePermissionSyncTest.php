<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Schema;
use App\Http\Middleware\PermissionMiddleware;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\putJson;

beforeEach(function () {
    foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('permissions', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->string('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->string('model_id');
        $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
    });

    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('syncs a role to only the requested permissions', function () {
    $this->withoutMiddleware([Authenticate::class, PermissionMiddleware::class]);

    $role = Role::create(['name' => 'operations', 'guard_name' => 'web']);

    Permission::insert([
        ['name' => 'addons.create', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'addons.delete', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'addons.edit', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'addons.manage', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'addons.update', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'addons.view', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $role->givePermissionTo(Permission::where('guard_name', 'web')->get());

    $response = putJson("/api/roles/{$role->id}/permissions/sync", [
        'permissions' => [
            'addons.delete',
            'addons.update',
            'addons.view',
            'addons.edit',
        ],
    ]);

    $response
        ->assertOk()
        ->assertJsonCount(4, 'data.permissions')
        ->assertJsonMissingPath('data.permissions.4');

    expect($role->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe([
        'addons.delete',
        'addons.edit',
        'addons.update',
        'addons.view',
    ]);
});

it('rejects permissions that do not exist for the role guard', function () {
    $this->withoutMiddleware([Authenticate::class, PermissionMiddleware::class]);

    $role = Role::create(['name' => 'operations', 'guard_name' => 'web']);
    Permission::create(['name' => 'addons.view', 'guard_name' => 'api']);

    putJson("/api/roles/{$role->id}/permissions/sync", [
        'permissions' => ['addons.view'],
    ])->assertStatus(422);
});
