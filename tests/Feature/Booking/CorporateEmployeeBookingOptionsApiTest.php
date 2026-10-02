<?php

use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('searches and hydrates paginated active CorporateEmployee options within booking scope', function () {
    $corporate = Corporate::create(['name' => 'Booking Options Corporate']);
    $otherCorporate = Corporate::create(['name' => 'Other Booking Corporate']);
    $employee = function (Corporate $company, string $code, string $firstName) {
        $user = User::factory()->create(['first_name' => $firstName, 'last_name' => 'Rider']);

        return CorporateEmployee::create([
            'user_id' => $user->id,
            'corporate_id' => $company->id,
            'employee_code' => $code,
            'is_active' => true,
        ]);
    };

    $first = $employee($corporate, 'BOOK-001', 'Ada');
    $second = $employee($corporate, 'BOOK-002', 'Bea');
    $third = $employee($corporate, 'BOOK-003', 'Cal');
    $inactive = $employee($corporate, 'BOOK-OLD', 'Dee');
    $inactive->update(['is_active' => false]);
    $foreign = $employee($otherCorporate, 'OTHER-001', 'Eve');

    $manager = User::findOrFail($first->user_id);
    $managerContext = UserContext::create([
        'user_id' => $manager->id,
        'context_type' => 'corporate',
        'context_id' => $first->id,
        'is_active' => true,
    ]);
    $this->app->make(\App\Services\CorporateRoleStarterService::class)->provision($corporate);
    $managerContext->roles()->attach(
        $this->app->make(\App\Services\CorporateRoleStarterService::class)->resolve($corporate, 'Corporate_Master_Admin')->id
    );
    $headers = ['X-Active-Context-Type' => 'corporate', 'X-Active-Context-Id' => $managerContext->id];
    $url = "/api/booking-flow/corporates/{$corporate->id}/employee-options";

    $this->actingAs($manager, 'api')->getJson($url.'?search=BOOK-002&per_page=1', $headers)
        ->assertOk()
        ->assertJsonPath('data.data.0.value', $second->id)
        ->assertJsonPath('data.data.0.label', 'Bea Rider')
        ->assertJsonPath('data.data.0.metadata.employee_code', 'BOOK-002')
        ->assertJsonPath('data.current_page', 1)
        ->assertJsonPath('data.total', 1);
    $this->actingAs($manager, 'api')->getJson($url.'?exclude_current_user=true', $headers)
        ->assertOk()->assertJsonCount(2, 'data.data')->assertJsonPath('data.total', 2);

    $this->actingAs($manager, 'api')->getJson($url.'?per_page=2&page=1', $headers)
        ->assertOk()->assertJsonCount(2, 'data.data')->assertJsonPath('data.total', 3);
    $this->actingAs($manager, 'api')->getJson($url.'?per_page=2&page=2', $headers)
        ->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.last_page', 2);
    $this->actingAs($manager, 'api')->getJson($url.'?selected_id='.$third->id, $headers)
        ->assertOk()->assertJsonPath('data.data.0.value', $third->id)
        ->assertJsonPath('data.data.0.record.id', $third->id)
        ->assertJsonPath('data.data.0.record.corporate_id', $corporate->id)
        ->assertJsonPath('data.data.0.record.user.first_name', 'Cal');
    $this->actingAs($manager, 'api')->getJson($url.'?employee_codes=BOOK-001,BOOK-003', $headers)
        ->assertOk()->assertJsonCount(2, 'data.data')
        ->assertJsonPath('data.data.0.record.corporate_id', $corporate->id);
    $this->actingAs($manager, 'api')->getJson($url.'?selected_id='.$foreign->id, $headers)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($manager, 'api')->getJson("/api/booking-flow/corporates/{$otherCorporate->id}/employee-options", $headers)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($manager, 'api')->getJson($url.'?selected_id='.$inactive->id, $headers)
        ->assertOk()->assertJsonPath('data.data.0.status', 'inactive')
        ->assertJsonPath('data.data.0.label', 'Dee Rider (Inactive)');
    $this->actingAs($manager, 'api')->getJson($url.'?search=BOOK-OLD', $headers)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($manager, 'api')->getJson($url.'?per_page=51', $headers)->assertUnprocessable();

    $employeeUser = User::factory()->create();
    $employeeUser->givePermissionTo('bookings.create');
    $ownEmployee = CorporateEmployee::create([
        'user_id' => $employeeUser->id,
        'corporate_id' => $corporate->id,
        'employee_code' => 'BOOK-SELF',
        'is_active' => true,
    ]);
    $employeeContext = UserContext::create([
        'user_id' => $employeeUser->id,
        'context_type' => 'corporate',
        'context_id' => $ownEmployee->id,
        'is_active' => true,
    ]);
    $roles = $this->app->make(\App\Services\CorporateRoleStarterService::class);
    $roles->provision($corporate);
    $employeeContext->roles()->attach($roles->resolve($corporate, 'Corporate_Employee')->id);
    $employeeHeaders = ['X-Active-Context-Type' => 'corporate', 'X-Active-Context-Id' => $employeeContext->id];
    $this->actingAs($employeeUser, 'api')->getJson($url.'?selected_id='.$ownEmployee->id, $employeeHeaders)
        ->assertOk()->assertJsonPath('data.data.0.value', $ownEmployee->id);
    $this->actingAs($employeeUser, 'api')->getJson($url.'?selected_id='.$first->id, $employeeHeaders)
        ->assertOk()->assertJsonCount(0, 'data.data');
});

it('searches and hydrates only authorized travelers for corporate portal booking', function () {
    $corporate = Corporate::create(['name' => 'Portal Booking Options']);
    $makeEmployee = function (string $code, string $name) use ($corporate) {
        $user = User::factory()->create(['first_name' => $name, 'last_name' => 'Traveler']);
        return CorporateEmployee::create([
            'user_id' => $user->id,
            'corporate_id' => $corporate->id,
            'employee_code' => $code,
            'is_active' => true,
        ]);
    };
    $actorEmployee = $makeEmployee('ACTOR', 'Coordinator');
    $target = $makeEmployee('TARGET-001', 'Selected');
    $makeEmployee('TARGET-002', 'Other');
    $inactive = $makeEmployee('TARGET-OLD', 'Inactive');
    $inactive->update(['is_active' => false]);
    $foreignCorporate = Corporate::create(['name' => 'Foreign Portal Booking Options']);
    $foreignUser = User::factory()->create(['first_name' => 'Foreign', 'last_name' => 'Traveler']);
    $foreign = CorporateEmployee::create([
        'user_id' => $foreignUser->id,
        'corporate_id' => $foreignCorporate->id,
        'employee_code' => 'FOREIGN-001',
        'is_active' => true,
    ]);
    $actor = User::findOrFail($actorEmployee->user_id);
    $context = UserContext::create([
        'user_id' => $actor->id,
        'context_type' => 'corporate',
        'context_id' => $actorEmployee->id,
        'is_active' => true,
    ]);
    $roles = $this->app->make(\App\Services\CorporateRoleStarterService::class);
    $roles->provision($corporate);
    $context->roles()->attach($roles->resolve($corporate, 'Transport_Coordinator')->id);
    $headers = ['X-Active-Context-Type' => 'corporate', 'X-Active-Context-Id' => $context->id];
    $url = '/api/corporate/booking-employee-options';

    $this->actingAs($actor, 'api')->getJson($url.'?search=TARGET-001&per_page=1', $headers)
        ->assertOk()
        ->assertJsonPath('data.data.0.value', $target->id)
        ->assertJsonPath('data.data.0.label', 'Selected Traveler')
        ->assertJsonPath('data.total', 1);
    $this->actingAs($actor, 'api')->getJson($url.'?selected_id='.$target->id, $headers)
        ->assertOk()
        ->assertJsonPath('data.data.0.record.id', $target->id)
        ->assertJsonPath('data.data.0.record.corporate_id', $corporate->id);
    $this->actingAs($actor, 'api')->getJson($url.'?selected_id='.$inactive->id, $headers)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($actor, 'api')->getJson($url.'?selected_id='.$foreign->id, $headers)
        ->assertOk()->assertJsonCount(0, 'data.data');
    $this->actingAs($actor, 'api')->getJson($url.'?employee_codes=TARGET-001,ACTOR', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.record.employee_code', 'TARGET-001');
    $this->actingAs($actor, 'api')->getJson($url.'?per_page=51', $headers)->assertUnprocessable();
});
