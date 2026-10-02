<?php

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;
use App\Services\CorporateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('toggles and deletes only the selected CorporateEmployee context for a shared User', function () {
    $user = User::factory()->create();
    $firstCorporate = Corporate::create(['name' => 'First context company']);
    $secondCorporate = Corporate::create(['name' => 'Second context company']);
    $firstEmployee = CorporateEmployee::create([
        'user_id' => $user->id,
        'corporate_id' => $firstCorporate->id,
        'is_active' => true,
    ]);
    $secondEmployee = CorporateEmployee::create([
        'user_id' => $user->id,
        'corporate_id' => $secondCorporate->id,
        'is_active' => true,
    ]);
    $firstContext = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'corporate',
        'context_id' => $firstEmployee->id,
        'is_active' => true,
    ]);
    $secondContext = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'corporate',
        'context_id' => $secondEmployee->id,
        'is_active' => true,
    ]);
    $customerContext = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'customer',
        'context_id' => (string) Str::uuid(),
        'is_active' => true,
    ]);
    $driverContext = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'driver',
        'context_id' => (string) Str::uuid(),
        'is_active' => true,
    ]);

    $service = app(CorporateService::class);
    $service->toggleEmployeeStatus($firstEmployee);
    $this->assertDatabaseHas('user_contexts', ['id' => $firstContext->id, 'is_active' => false]);
    $this->assertDatabaseHas('user_contexts', ['id' => $secondContext->id, 'is_active' => true]);

    $service->toggleEmployeeStatus($firstEmployee->fresh());
    $service->deleteEmployee($firstEmployee->fresh());

    $this->assertNotNull(UserContext::withTrashed()->findOrFail($firstContext->id)->deleted_at);
    $this->assertDatabaseHas('user_contexts', ['id' => $secondContext->id, 'is_active' => true]);
    $this->assertDatabaseHas('user_contexts', ['id' => $customerContext->id, 'is_active' => true]);
    $this->assertDatabaseHas('user_contexts', ['id' => $driverContext->id, 'is_active' => true]);
});

it('assigns corporate roles through the context source and audit service', function () {
    $user = User::factory()->create();
    $corporate = Corporate::create(['name' => 'Role assignment company']);
    $employee = CorporateEmployee::create(['user_id' => $user->id, 'corporate_id' => $corporate->id, 'is_active' => true]);
    $context = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'corporate',
        'context_id' => $employee->id,
        'is_active' => true,
    ]);

    app(CorporateService::class)->assignEmployeeRole($employee, 'Corporate_Employee');

    $roleId = DB::table('user_context_roles')->where('user_context_id', $context->id)->value('role_id');
    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $roleId, 'model_type' => User::class, 'model_id' => $user->id,
    ]);
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'user-access', 'subject_id' => $user->id, 'description' => 'context_roles_assigned',
    ]);
});
