<?php

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates active QC Staff across the installation under Staff permissions', function () {
    $actor = Staff::factory()->create();
    $actor->user->givePermissionTo('bookings.view', 'bookings.qc_inspect', 'staff.view-all');
    $role = Role::findOrCreate('qc_inspector', 'api');
    $local = Staff::factory()->create(['code' => 'QC-LOCAL']);
    $local->user->assignRole($role);
    $foreign = Staff::factory()->create(['code' => 'QC-OTHER-LOCATION']);
    $foreign->user->assignRole($role);
    $ended = Staff::factory()->create(['code' => 'QC-ENDED', 'employment_ended_at' => now()]);
    $ended->user->assignRole($role);
    $url = '/api/booking-lifecycle/inspectors/available';

    actingAs($actor->user, 'api')->getJson($url.'?search=QC-LOCAL&per_page=1')->assertOk()
        ->assertJsonPath('data.data.0.value', $local->user_id)
        ->assertJsonPath('data.data.0.metadata.staff_code', 'QC-LOCAL')
        ->assertJsonMissingPath('data.data.0.metadata.company')
        ->assertJsonCount(1, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$local->user_id)->assertOk()
        ->assertJsonPath('data.data.0.value', $local->user_id);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$foreign->user_id)->assertOk()
        ->assertJsonPath('data.data.0.value', $foreign->user_id);
    actingAs($actor->user, 'api')->getJson($url.'?selected_id='.$ended->user_id)->assertOk()
        ->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
});
