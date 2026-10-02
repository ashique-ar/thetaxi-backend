<?php

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('searches active corporate departments and parent-scoped divisions', function () {
    $corporate = Corporate::create(['name' => 'Lookup Options Corporate']);
    $foreignCorporate = Corporate::create(['name' => 'Other Lookup Options Corporate']);
    $department = CorporateDepartment::create([
        'corporate_id' => $corporate->id,
        'name' => 'Operations',
        'is_active' => true,
    ]);
    $inactiveDepartment = CorporateDepartment::create([
        'corporate_id' => $corporate->id,
        'name' => 'Legacy',
        'is_active' => false,
    ]);
    $foreignDepartment = CorporateDepartment::create([
        'corporate_id' => $foreignCorporate->id,
        'name' => 'Restricted',
        'is_active' => true,
    ]);
    $division = CorporateDivision::create([
        'department_id' => $department->id,
        'name' => 'Travel',
        'is_active' => true,
    ]);
    CorporateDivision::create([
        'department_id' => $foreignDepartment->id,
        'name' => 'Private',
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $user->givePermissionTo('bookings.create');
    $departmentUrl = "/api/booking-flow/corporates/{$corporate->id}/department-options";

    $this->actingAs($user, 'api')->getJson($departmentUrl.'?search=Operations&per_page=1')
        ->assertOk()->assertJsonPath('data.data.0.value', $department->id)
        ->assertJsonPath('data.data.0.label', 'Operations')
        ->assertJsonPath('data.total', 1);
    $this->actingAs($user, 'api')->getJson($departmentUrl.'?selected_id='.$department->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $department->id);
    $this->actingAs($user, 'api')->getJson($departmentUrl.'?selected_id='.$inactiveDepartment->id)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($user, 'api')->getJson($departmentUrl.'?per_page=51')->assertUnprocessable();

    $divisionUrl = "/api/booking-flow/corporates/{$corporate->id}/departments/{$department->id}/division-options";
    $this->actingAs($user, 'api')->getJson($divisionUrl.'?search=Travel')
        ->assertOk()->assertJsonPath('data.data.0.value', $division->id);
    $this->actingAs($user, 'api')->getJson($divisionUrl.'?selected_id='.$division->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $division->id);
    $foreignDivisionUrl = "/api/booking-flow/corporates/{$corporate->id}/departments/{$foreignDepartment->id}/division-options";
    $this->actingAs($user, 'api')->getJson($foreignDivisionUrl)
        ->assertOk()->assertJsonCount(0, 'data.data');
});
