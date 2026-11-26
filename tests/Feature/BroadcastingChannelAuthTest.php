<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-reverb-key',
        'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
        'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
    ]);

    foreach ([
        'user_direct_permission_grants',
        'user_direct_role_grants',
        'user_context_permission_grants',
        'user_context_roles',
        'role_has_permissions',
        'model_has_roles',
        'model_has_permissions',
        'roles',
        'permissions',
        'user_contexts',
        'staff',
        'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email')->nullable();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('staff', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->timestamp('employment_ended_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('user_contexts', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('context_type');
        $table->uuid('context_id')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

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
        $table->json('context_types')->nullable();
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->string('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_primary');
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->string('model_id');
        $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_primary');
    });

    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });

    Schema::create('user_context_roles', function (Blueprint $table) {
        $table->increments('id');
        $table->uuid('user_context_id');
        $table->unsignedBigInteger('role_id');
        $table->timestamps();
    });

    Schema::create('user_context_permission_grants', function (Blueprint $table) {
        $table->uuid('user_context_id');
        $table->unsignedBigInteger('permission_id');
        $table->timestamps();
        $table->primary(['user_context_id', 'permission_id']);
    });

    Schema::create('user_direct_role_grants', function (Blueprint $table) {
        $table->uuid('user_id');
        $table->unsignedBigInteger('role_id');
    });

    Schema::create('user_direct_permission_grants', function (Blueprint $table) {
        $table->uuid('user_id');
        $table->unsignedBigInteger('permission_id');
        $table->timestamps();
        $table->primary(['user_id', 'permission_id']);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('authorizes active staff users whose booking permissions come from staff context grants', function () {
    $userId = (string) Str::uuid();
    $staffId = (string) Str::uuid();
    $staffContextId = (string) Str::uuid();

    DB::table('users')->insert(['id' => $userId, 'email' => 'staff@example.com', 'is_active' => true]);
    DB::table('staff')->insert(['id' => $staffId, 'user_id' => $userId]);
    DB::table('user_contexts')->insert([
        'id' => $staffContextId,
        'user_id' => $userId,
        'context_type' => 'staff',
        'context_id' => $staffId,
        'is_active' => true,
    ]);

    $permission = Permission::create(['name' => 'bookings.update', 'guard_name' => 'api']);
    $role = Role::create([
        'name' => 'staff-dispatcher',
        'guard_name' => 'api',
        'context_types' => json_encode(['staff']),
    ]);
    $role->givePermissionTo($permission);

    DB::table('user_context_roles')->insert([
        'user_context_id' => $staffContextId,
        'role_id' => $role->id,
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::query()->findOrFail($userId);

    // Without context headers (direct Echo clients)
    $this->actingAs($user, 'api')
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-admin.assignments',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);

    // With explicit staff context headers
    $this->actingAs($user, 'api')
        ->withHeaders([
            'X-Active-Context-Type' => 'staff',
            'X-Active-Context-Id' => $staffContextId,
        ])
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-admin.assignments',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

it('authorizes super-admin in internal context and denies corporate contexts', function () {
    $userId = (string) Str::uuid();
    $staffId = (string) Str::uuid();
    $staffContextId = (string) Str::uuid();
    $corporateContextId = (string) Str::uuid();

    DB::table('users')->insert(['id' => $userId, 'email' => 'admin@example.com', 'is_active' => true]);
    DB::table('staff')->insert(['id' => $staffId, 'user_id' => $userId]);
    DB::table('user_contexts')->insert([
        [
            'id' => $staffContextId,
            'user_id' => $userId,
            'context_type' => 'staff',
            'context_id' => $staffId,
            'is_active' => true,
        ],
        [
            'id' => $corporateContextId,
            'user_id' => $userId,
            'context_type' => 'corporate',
            'context_id' => (string) Str::uuid(),
            'is_active' => true,
        ],
    ]);

    $bookingView = Permission::create(['name' => 'bookings.view', 'guard_name' => 'api']);
    $superAdmin = Role::create([
        'name' => 'super-admin',
        'guard_name' => 'api',
        'context_types' => json_encode(['internal', 'staff']),
    ]);
    $corporateRole = Role::create([
        'name' => 'corporate',
        'guard_name' => 'api',
        'context_types' => json_encode(['corporate']),
    ]);
    $corporateRole->givePermissionTo($bookingView);

    DB::table('user_context_roles')->insert([
        ['user_context_id' => $staffContextId, 'role_id' => $superAdmin->id],
        ['user_context_id' => $corporateContextId, 'role_id' => $corporateRole->id],
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::query()->findOrFail($userId);

    $this->actingAs($user, 'api')
        ->withHeaders([
            'X-Active-Context-Type' => 'internal',
            'X-Active-Context-Id' => 'internal',
        ])
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-admin.assignments',
        ])
        ->assertOk();

    $this->actingAs($user, 'api')
        ->withHeaders([
            'X-Active-Context-Type' => 'corporate',
            'X-Active-Context-Id' => $corporateContextId,
        ])
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-admin.assignments',
        ])
        ->assertForbidden();
});

it('denies corporate-only or staff users without booking permissions', function () {
    $corporateUserId = (string) Str::uuid();
    $corporateContextId = (string) Str::uuid();
    DB::table('users')->insert(['id' => $corporateUserId, 'email' => 'corp@example.com', 'is_active' => true]);
    DB::table('user_contexts')->insert([
        'id' => $corporateContextId,
        'user_id' => $corporateUserId,
        'context_type' => 'corporate',
        'context_id' => (string) Str::uuid(),
        'is_active' => true,
    ]);

    $bookingCreate = Permission::create(['name' => 'bookings.create', 'guard_name' => 'api']);
    $corporateRole = Role::create([
        'name' => 'Corporate_Employee',
        'guard_name' => 'api',
        'context_types' => json_encode(['corporate']),
    ]);
    $corporateRole->givePermissionTo($bookingCreate);
    DB::table('user_context_roles')->insert([
        'user_context_id' => $corporateContextId,
        'role_id' => $corporateRole->id,
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $corporateUser = User::query()->findOrFail($corporateUserId);

    $this->actingAs($corporateUser, 'api')
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-admin.assignments',
        ])
        ->assertForbidden();

    $hrUserId = (string) Str::uuid();
    $hrStaffId = (string) Str::uuid();
    DB::table('users')->insert(['id' => $hrUserId, 'email' => 'hr@example.com', 'is_active' => true]);
    DB::table('staff')->insert(['id' => $hrStaffId, 'user_id' => $hrUserId]);
    DB::table('user_contexts')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $hrUserId,
        'context_type' => 'staff',
        'context_id' => $hrStaffId,
        'is_active' => true,
    ]);

    $hrUser = User::query()->findOrFail($hrUserId);

    $this->actingAs($hrUser, 'api')
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-admin.assignments',
        ])
        ->assertForbidden();
});
