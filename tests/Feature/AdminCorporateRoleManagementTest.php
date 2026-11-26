<?php

use App\Http\Middleware\EnsureCorporateBusinessScope;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    activity()->disableLogging();
    $this->withoutMiddleware(EnsureCorporateBusinessScope::class);

    foreach ([
        'audit_logs',
        'activity_log',
        'user_direct_role_grants',
        'user_context_permission_grants',
        'user_context_roles',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'permissions',
        'roles',
        'user_contexts',
        'corporate_employee_locations',
        'corporate_employees',
        'corporate_divisions',
        'corporate_departments',
        'corporates',
        'users',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('password')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('corporates', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('contact_email')->nullable();
        $table->json('booking_notification_emails')->nullable();
        $table->boolean('is_active')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('corporate_departments', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_id');
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('corporate_divisions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('department_id');
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('corporate_employees', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('corporate_id');
        $table->uuid('department_id')->nullable();
        $table->uuid('division_id')->nullable();
        $table->string('employee_code')->nullable();
        $table->boolean('is_active')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('corporate_employee_locations', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_employee_id');
        $table->string('label')->nullable();
        $table->text('address')->nullable();
        $table->boolean('is_default_pickup')->default(false);
        $table->boolean('is_default_dropoff')->default(false);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('user_contexts', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('context_type');
        $table->uuid('context_id');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('roles', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('guard_name');
        $table->text('description')->nullable();
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::create('permissions', function (Blueprint $table) {
        $table->increments('id');
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedInteger('permission_id');
        $table->unsignedInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });
    Schema::create('user_context_roles', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->uuid('user_context_id');
        $table->unsignedInteger('role_id');
        $table->timestamps();
        $table->unique(['user_context_id', 'role_id']);
    });
    Schema::create('user_context_permission_grants', function (Blueprint $table) {
        $table->uuid('user_context_id');
        $table->unsignedInteger('permission_id');
    });
    Schema::create('user_direct_role_grants', function (Blueprint $table) {
        $table->uuid('user_id');
        $table->unsignedInteger('role_id');
        $table->primary(['user_id', 'role_id']);
    });
    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedInteger('role_id');
        $table->string('model_type');
        $table->uuid('model_id');
        $table->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedInteger('permission_id');
        $table->string('model_type');
        $table->uuid('model_id');
        $table->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('audit_logs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id')->nullable();
        $table->string('action');
        $table->string('entity');
        $table->uuid('entity_id')->nullable();
        $table->timestamp('timestamp')->nullable();
        $table->json('details')->nullable();
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('activity_log', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->uuid('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->uuid('causer_id')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->json('properties')->nullable();
        $table->string('event')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });
});

function actingInternalAdmin(bool $canManageCorporates = true): User
{
    $admin = User::create([
        'id' => (string) Str::uuid(),
        'first_name' => 'Internal',
        'last_name' => 'Admin',
        'email' => Str::uuid() . '@admin.test',
        'is_active' => true,
    ]);

    $context = UserContext::create([
        'user_id' => $admin->id,
        'context_type' => 'internal',
        'context_id' => $admin->id,
        'is_active' => true,
    ]);

    if ($canManageCorporates) {
        $perm = Permission::findOrCreate('corporates.manage', 'api');
        $role = Role::findOrCreate('internal_admin_' . Str::random(6), 'api');
        $role->givePermissionTo($perm);
        $context->roles()->sync([$role->id]);
    }

    Passport::actingAs($admin);

    return $admin;
}

it('forbids internal users without corporates.manage from managing corporate roles', function () {
    actingInternalAdmin(false);
    $corporate = Corporate::create(['name' => 'Acme Corp', 'is_active' => true]);

    $this->getJson("/api/admin/corporates/{$corporate->id}/roles")
        ->assertForbidden();
});

it('allows internal admins to CRUD corporate roles and assign custom roles to employees', function () {
    actingInternalAdmin(true);
    $corporate = Corporate::create(['name' => 'Acme Corp', 'is_active' => true]);

    $empUser = User::create([
        'id' => (string) Str::uuid(),
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@acme.test',
        'is_active' => true,
    ]);
    $employee = CorporateEmployee::create([
        'user_id' => $empUser->id,
        'corporate_id' => $corporate->id,
        'is_active' => true,
    ]);
    UserContext::create([
        'user_id' => $empUser->id,
        'context_type' => 'corporate',
        'context_id' => $employee->id,
        'is_active' => true,
    ]);

    $this->getJson("/api/admin/corporates/{$corporate->id}/roles/permissions")
        ->assertOk()
        ->assertJsonStructure(['status', 'data' => ['permissions']]);

    $indexRes = $this->getJson("/api/admin/corporates/{$corporate->id}/roles")
        ->assertOk();
    expect($indexRes->json('data.roles'))->toHaveCount(4);

    $createRes = $this->postJson("/api/admin/corporates/{$corporate->id}/roles", [
        'name' => 'Fleet Supervisor',
        'description' => 'Can view and approve bookings',
        'permissions' => ['corporate.view', 'view_all_bookings', 'approve_bookings'],
    ])->assertCreated();

    $roleId = $createRes->json('data.role.id');
    $roleName = $createRes->json('data.role.name');
    expect($roleName)->toBe("Corporate_{$corporate->id}_Fleet_Supervisor");

    $this->postJson("/api/admin/corporates/{$corporate->id}/employees/{$employee->id}/role", [
        'role' => $roleName,
    ])->assertOk()
        ->assertJsonPath('data.employee.role', $roleName);

    // Cannot delete a role assigned to an employee
    $this->deleteJson("/api/admin/corporates/{$corporate->id}/roles/{$roleId}")
        ->assertStatus(409);

    // Update permissions on the custom role
    $this->putJson("/api/admin/corporates/{$corporate->id}/roles/{$roleId}", [
        'name' => 'Fleet Supervisor',
        'permissions' => ['corporate.view', 'view_all_bookings'],
    ])->assertOk()
        ->assertJsonCount(2, 'data.role.permissions');

    // Reassign employee back to default role, then delete custom role
    $this->postJson("/api/admin/corporates/{$corporate->id}/employees/{$employee->id}/role", [
        'role' => 'Corporate_Employee',
    ])->assertOk();

    $this->deleteJson("/api/admin/corporates/{$corporate->id}/roles/{$roleId}")
        ->assertOk();
});
