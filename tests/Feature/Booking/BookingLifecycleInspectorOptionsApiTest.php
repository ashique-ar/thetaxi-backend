<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates active QC Staff only within the requesters Staff scope', function () {
    $company = Company::create(['name' => 'QC Local Company', 'city' => 'Colombo']);
    $foreignCompany = Company::create(['name' => 'QC Foreign Company', 'city' => 'Kandy']);
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    $actor->user->givePermissionTo('bookings.view', 'bookings.qc_inspect', 'staff.view-legal-entity');
    $role = Role::findOrCreate('qc_inspector', 'api');
    $local = Staff::factory()->create(['company_id' => $company->id, 'code' => 'QC-LOCAL']);
    $local->user->assignRole($role);
    $foreign = Staff::factory()->create(['company_id' => $foreignCompany->id, 'code' => 'QC-FOREIGN']);
    $foreign->user->assignRole($role);
    $ended = Staff::factory()->create(['company_id' => $company->id, 'code' => 'QC-ENDED', 'employment_ended_at' => now()]);
    $ended->user->assignRole($role);
    $url = '/api/booking-lifecycle/inspectors/available';

    actingAs($actor->user, 'api')->getJson($url.'?search=QC-LOCAL&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $local->user_id)
        ->assertJsonPath('data.data.0.metadata.staff_code', 'QC-LOCAL')
        ->assertJsonPath('data.data.0.metadata.company', 'QC Local Company')
        ->assertJsonCount(1, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$local->user_id)->assertOk()
        ->assertJsonPath('data.data.0.value', $local->user_id);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$foreign->user_id)->assertOk()
        ->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$ended->user_id)->assertOk()
        ->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();

    actingAs($actor->user, 'api')->postJson('/api/booking-lifecycle/start-qc-inspection', [
        'booking_id' => 'not-a-booking',
        'inspector_id' => $foreign->user_id,
    ])->assertUnprocessable()->assertJsonValidationErrors('inspector_id');
});
