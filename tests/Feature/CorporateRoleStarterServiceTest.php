<?php

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\UserContext;
use App\Services\CorporateRoleStarterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    activity()->disableLogging();
    foreach (['user_context_roles', 'role_has_permissions', 'permissions', 'roles', 'user_contexts', 'corporate_employees', 'corporates'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('corporates', function (Blueprint $table) {
        $table->uuid('id')->primary(); $table->string('name'); $table->string('contact_email')->nullable();
        $table->boolean('is_active')->default(true); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('corporate_employees', function (Blueprint $table) {
        $table->uuid('id')->primary(); $table->uuid('user_id'); $table->uuid('corporate_id');
        $table->boolean('is_active')->default(true); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('user_contexts', function (Blueprint $table) {
        $table->uuid('id')->primary(); $table->uuid('user_id'); $table->string('context_type');
        $table->uuid('context_id'); $table->boolean('is_active')->default(true); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('roles', function (Blueprint $table) {
        $table->increments('id'); $table->string('name'); $table->string('guard_name'); $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::create('permissions', function (Blueprint $table) {
        $table->increments('id'); $table->string('name'); $table->string('guard_name'); $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });
    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedInteger('permission_id'); $table->unsignedInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });
    Schema::create('user_context_roles', function (Blueprint $table) {
        $table->bigIncrements('id'); $table->uuid('user_context_id'); $table->unsignedInteger('role_id');
        $table->timestamps(); $table->unique(['user_context_id', 'role_id']);
    });
});

it('creates four separate roles per corporate and preserves changed permissions', function () {
    $first = Corporate::create(['name' => 'First Company', 'contact_email' => 'first@example.test', 'is_active' => true]);
    $second = Corporate::create(['name' => 'Second Company', 'contact_email' => 'second@example.test', 'is_active' => true]);
    $service = app(CorporateRoleStarterService::class);

    $firstResult = $service->provision($first);
    $service->provision($second);

    $firstRoles = Role::where('name', 'like', 'Corporate_'.$first->id.'_%')->get();
    $secondRoles = Role::where('name', 'like', 'Corporate_'.$second->id.'_%')->get();
    $employeeRole = $service->resolve($first, 'Corporate_Employee');
    $employeeRole->syncPermissions(['corporate.view']);

    $rerun = $service->provision($first);
    $employeeRole->refresh();

    expect($firstResult['roles_created'])->toBe(4)
        ->and($firstRoles)->toHaveCount(4)
        ->and($secondRoles)->toHaveCount(4)
        ->and($firstRoles->pluck('id')->intersect($secondRoles->pluck('id')))->toBeEmpty()
        ->and($rerun['roles_created'])->toBe(0)
        ->and($employeeRole->permissions->pluck('name')->all())->toBe(['corporate.view']);
});

it('moves a legacy shared assignment to the matching company role', function () {
    $corporate = Corporate::create(['name' => 'Legacy Company', 'contact_email' => 'legacy@example.test', 'is_active' => true]);
    $userId = (string) Str::uuid();
    $employee = CorporateEmployee::create(['corporate_id' => $corporate->id, 'user_id' => $userId, 'is_active' => true]);
    $context = UserContext::create([
        'user_id' => $userId,
        'context_type' => 'corporate',
        'context_id' => $employee->id,
        'is_active' => true,
    ]);
    $legacy = Role::findOrCreate('Corporate_Employee', 'api');
    $context->roles()->sync([$legacy->id]);

    $result = app(CorporateRoleStarterService::class)->provision($corporate);
    $context->refresh();

    expect($result['assignments_migrated'])->toBe(1)
        ->and($context->roles)->toHaveCount(1)
        ->and($context->roles->first()->name)->toBe('Corporate_'.$corporate->id.'_Employee');
});
