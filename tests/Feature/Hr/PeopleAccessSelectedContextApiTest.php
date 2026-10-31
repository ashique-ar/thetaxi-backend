<?php

use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('makes People Core visible according to permission across location assignments', function () {
    [$user] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $actorStaff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $secondStaff = Staff::factory()->create();
    $spell = HrEmploymentSpell::factory()->create([
        'staff_id' => $secondStaff->id,
        'status' => 'active',
    ]);
    HrEmploymentAssignment::factory()->create([
        'employment_spell_id' => $spell->id,
        'staff_id' => $secondStaff->id,
        'location_code' => 'REMOTE-OFFICE',
        'effective_from' => now()->subDay(),
        'effective_until' => null,
    ]);
    $url = '/api/hr/people/directory';

    $response = actingAs($user, 'api')->getJson($url)->assertOk();
    $rows = collect($response->json('data.data'));
    expect($rows->firstWhere('id', $secondStaff->id))->not->toBeNull()
        ->and($rows->firstWhere('id', $actorStaff->id))->not->toBeNull()
        ->and($rows->first())->not->toHaveKey('company_id');
});
