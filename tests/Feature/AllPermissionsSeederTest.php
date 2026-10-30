<?php

use Database\Seeders\AllPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    foreach (['user_direct_permission_grants', 'user_context_permission_grants', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions'] as $table) {
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
        $table->primary(['permission_id', 'model_id', 'model_type']);
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->string('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });

    Schema::create('user_context_permission_grants', function (Blueprint $table) {
        $table->uuid('user_context_id');
        $table->unsignedBigInteger('permission_id');
        $table->timestamps();
        $table->primary(['user_context_id', 'permission_id']);
    });

    Schema::create('user_direct_permission_grants', function (Blueprint $table) {
        $table->uuid('user_id');
        $table->unsignedBigInteger('permission_id');
        $table->timestamps();
        $table->primary(['user_id', 'permission_id']);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('can run repeatedly without duplicates or removing client assignments', function () {
    $clientPermission = Permission::create([
        'name' => 'client.custom-workflow',
        'guard_name' => 'api',
    ]);
    $clientRole = Role::create([
        'name' => 'client-operations',
        'guard_name' => 'api',
    ]);
    $clientRole->givePermissionTo($clientPermission);

    $this->seed(AllPermissionsSeeder::class);

    $permissionCountAfterFirstRun = Permission::count();
    $roleCountAfterFirstRun = Role::count();

    $this->seed(AllPermissionsSeeder::class);

    expect(Permission::count())->toBe($permissionCountAfterFirstRun)
        ->and(Role::count())->toBe($roleCountAfterFirstRun)
        ->and(Permission::where([
            'name' => 'bookings.tracking_export',
            'guard_name' => 'api',
        ])->exists())->toBeTrue()
        ->and(Permission::where([
            'name' => 'bookings.tracking_export',
            'guard_name' => 'web',
        ])->exists())->toBeFalse()
        ->and($clientRole->fresh()->hasPermissionTo('client.custom-workflow'))->toBeTrue();

    foreach (['api'] as $guard) {
        $admin = Role::where(['name' => 'admin', 'guard_name' => $guard])->firstOrFail();
        $masterAdmin = Role::where(['name' => 'super-admin', 'guard_name' => $guard])->firstOrFail();

        expect($masterAdmin->permissions()->count())->toBe(count(AllPermissionsSeeder::allPermissionNames()))
            ->and($admin->permissions()->count())->toBeLessThan(count(AllPermissionsSeeder::allPermissionNames()))
            ->and($admin->permissions()->whereIn('name', ['roles.manage', 'permissions.manage', 'system.manage'])->exists())->toBeFalse()
            ->and(Role::where(['name' => 'dispatcher', 'guard_name' => $guard])->exists())->toBeTrue()
            ->and(Role::where(['name' => 'customer-support', 'guard_name' => $guard])->exists())->toBeTrue();
    }
});
