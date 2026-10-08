<?php

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('withholds encrypted employee-record and profile data without confidential permission', function () {
    [$admin, $company] = hr_seed_admin_actor();
    Role::findByName('admin', 'api')->revokePermissionTo('hr.people.timeline-confidential');
    config(['hr.features.people_core' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $privateMarker = 'confidential-record-payload-marker';

    $created = actingAs($admin, 'api')->postJson('/api/hr/employees/'.$staff->id.'/records', [
        'record_type' => 'qualification',
        'title' => 'Restricted qualification record',
        'data' => ['details' => $privateMarker],
        'confidentiality' => 'legal',
        'source' => 'hr_entry',
    ])->assertCreated();

    expect($created->json('data'))->not->toHaveKey('encrypted_data')
        ->not->toHaveKey('verified_by')
        ->and($created->getContent())->not->toContain($privateMarker);

    $listed = actingAs($admin, 'api')->getJson('/api/hr/employees/'.$staff->id.'/records')->assertOk();
    expect($listed->json('data.data.0'))->not->toHaveKey('encrypted_data')
        ->not->toHaveKey('verified_by')
        ->and($listed->getContent())->not->toContain($privateMarker);

    $profile = actingAs($admin, 'api')->postJson('/api/hr/employees/'.$staff->id.'/profile-versions', [
        'profile' => ['address' => $privateMarker],
        'change_reason' => 'Private profile update',
    ])->assertCreated();

    expect($profile->json('data'))->not->toHaveKey('encrypted_profile')
        ->not->toHaveKey('profile_checksum')
        ->not->toHaveKey('change_reason')
        ->not->toHaveKey('changed_by')
        ->and($profile->getContent())->not->toContain($privateMarker);

    $versions = actingAs($admin, 'api')->getJson('/api/hr/employees/'.$staff->id.'/profile-versions')->assertOk();
    expect($versions->json('data.0'))->not->toHaveKey('encrypted_profile')
        ->not->toHaveKey('profile_checksum')
        ->not->toHaveKey('change_reason')
        ->not->toHaveKey('changed_by')
        ->and($versions->getContent())->not->toContain($privateMarker);
});
