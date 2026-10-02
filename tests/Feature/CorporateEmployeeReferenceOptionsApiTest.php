<?php

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;
use App\Services\CorporateRoleStarterService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('searches and hydrates CorporateEmployee department and division references within the active corporate', function () {
    $corporate = Corporate::create(['name' => 'Selector Corporate']);
    $foreignCorporate = Corporate::create(['name' => 'Other Corporate']);
    $department = CorporateDepartment::create(['corporate_id' => $corporate->id, 'name' => 'Operations', 'is_active' => true]);
    $inactiveDepartment = CorporateDepartment::create(['corporate_id' => $corporate->id, 'name' => 'Legacy', 'is_active' => false]);
    $foreignDepartment = CorporateDepartment::create(['corporate_id' => $foreignCorporate->id, 'name' => 'Secret', 'is_active' => true]);
    $division = CorporateDivision::create(['department_id' => $department->id, 'name' => 'Travel', 'is_active' => true]);
    $foreignDivision = CorporateDivision::create(['department_id' => $foreignDepartment->id, 'name' => 'Restricted', 'is_active' => true]);

    $user = User::factory()->create();
    $employee = CorporateEmployee::create([
        'user_id' => $user->id,
        'corporate_id' => $corporate->id,
        'department_id' => $department->id,
        'is_active' => true,
    ]);
    $context = UserContext::create([
        'user_id' => $user->id,
        'context_type' => 'corporate',
        'context_id' => $employee->id,
        'is_active' => true,
    ]);
    $roles = app(CorporateRoleStarterService::class);
    $roles->provision($corporate);
    $context->roles()->attach($roles->resolve($corporate, 'Corporate_Master_Admin')->id);
    $headers = [
        'X-Active-Context-Type' => 'corporate',
        'X-Active-Context-Id' => $context->id,
    ];
    $url = '/api/corporate/employee-reference-options';

    $this->actingAs($user, 'api')->getJson($url.'?record_type=department&search=Operations', $headers)
        ->assertOk()->assertJsonPath('data.data.0.value', $department->id)
        ->assertJsonPath('data.data.0.label', 'Operations');
    $this->actingAs($user, 'api')->getJson($url.'?record_type=department&selected_id='.$inactiveDepartment->id, $headers)
        ->assertOk()->assertJsonPath('data.data.0.status', 'Inactive');
    $this->actingAs($user, 'api')->getJson($url.'?record_type=department&selected_id='.$foreignDepartment->id, $headers)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($user, 'api')->getJson($url.'?record_type=division&department_id='.$department->id.'&search=Travel', $headers)
        ->assertOk()->assertJsonPath('data.data.0.value', $division->id);
    $this->actingAs($user, 'api')->getJson($url.'?record_type=division', $headers)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($user, 'api')->getJson($url.'?record_type=division&department_id='.$department->id.'&selected_id='.$division->id, $headers)
        ->assertOk()->assertJsonPath('data.data.0.value', $division->id);
    $this->actingAs($user, 'api')->getJson($url.'?record_type=division&department_id='.$department->id.'&selected_id='.$foreignDivision->id, $headers)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($user, 'api')->getJson($url.'?record_type=department&per_page=51', $headers)
        ->assertUnprocessable();

    $unprivilegedUser = User::factory()->create();
    $unprivilegedEmployee = CorporateEmployee::create([
        'user_id' => $unprivilegedUser->id,
        'corporate_id' => $corporate->id,
        'department_id' => $department->id,
        'is_active' => true,
    ]);
    $unprivilegedContext = UserContext::create([
        'user_id' => $unprivilegedUser->id,
        'context_type' => 'corporate',
        'context_id' => $unprivilegedEmployee->id,
        'is_active' => true,
    ]);
    $this->actingAs($unprivilegedUser, 'api')
        ->getJson($url.'?record_type=department', [
            'X-Active-Context-Type' => 'corporate',
            'X-Active-Context-Id' => $unprivilegedContext->id,
        ])
        ->assertForbidden();
});
