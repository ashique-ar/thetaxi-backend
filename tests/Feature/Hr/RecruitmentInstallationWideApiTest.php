<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists recruitment records without requiring a selected company context', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $user = User::factory()->create();
    $role = Role::create(['name' => 'recruitment_installation_wide_reader', 'guard_name' => 'api']);
    $role->givePermissionTo('hr.recruitment.view');
    $user->assignRole($role);

    actingAs($user, 'api')->getJson('/api/hr/recruitment/requisitions')->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($user, 'api')->getJson('/api/hr/recruitment/requisitions?company_id='.Str::uuid())
        ->assertOk()->assertJsonCount(0, 'data.data');
});

it('accepts application owners from any location and still rejects former Staff', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    config(['hr.features.employee_self_service' => true]);

    $user = User::factory()->create();
    $role = Role::create(['name' => 'recruitment_application_owner_installation_wide_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.recruitment.manage', 'hr.ess.use']);
    $user->assignRole($role);
    $actorStaff = Staff::factory()->create(['user_id' => $user->id]);
    $actorContext = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $actorHeaders = ['X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $actorContext->id];
    $formerOwner = Staff::factory()->former()->create();
    $otherLocationOwner = Staff::factory()->create();

    $unitId = (string) Str::uuid();
    DB::table('hr_organization_units')->insert([
        'id' => $unitId, 'unit_type' => 'department', 'code' => 'RECRUIT',
        'name' => 'Recruitment', 'effective_from' => today()->toDateString(), 'created_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $designationId = (string) Str::uuid();
    DB::table('hr_designations')->insert([
        'id' => $designationId, 'code' => 'RECRUIT-TEST', 'name' => 'Recruiter',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $positionId = (string) Str::uuid();
    DB::table('hr_positions')->insert([
        'id' => $positionId, 'organization_unit_id' => $unitId,
        'designation_id' => $designationId, 'position_number' => 'RECRUIT-TEST-1', 'title' => 'Recruiter',
        'effective_from' => today()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $candidateId = (string) Str::uuid();
    DB::table('hr_candidates')->insert([
        'id' => $candidateId, 'candidate_code' => 'CAN-RECRUIT-TEST',
        'encrypted_profile' => Crypt::encryptString('{}'), 'identity_fingerprint' => hash('sha256', 'candidate'),
        'source_type' => 'direct', 'consent_at' => now(), 'status' => 'active', 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $requisitionId = (string) Str::uuid();
    DB::table('hr_job_requisitions')->insert([
        'id' => $requisitionId, 'position_id' => $positionId,
        'code' => 'REQ-RECRUIT-TEST', 'title' => 'Recruiter', 'headcount' => 1, 'requirements' => '[]',
        'status' => 'approved', 'requested_by' => $user->id, 'approved_by' => User::factory()->create()->id,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $payload = [
        'candidate_id' => $candidateId,
        'requisition_id' => $requisitionId, 'owner_staff_id' => $formerOwner->id,
    ];
    actingAs($user, 'api')->withHeaders($actorHeaders)->postJson('/api/hr/recruitment/applications', $payload)->assertStatus(422);
    $this->assertDatabaseMissing('hr_candidate_applications', ['candidate_id' => $candidateId]);

    $payload['owner_staff_id'] = $otherLocationOwner->id;
    actingAs($user, 'api')->withHeaders($actorHeaders)->postJson('/api/hr/recruitment/applications', $payload)->assertCreated();
    $this->assertDatabaseHas('hr_candidate_applications', [
        'candidate_id' => $candidateId, 'owner_staff_id' => $otherLocationOwner->id,
    ]);
    $application = DB::table('hr_candidate_applications')->where('candidate_id', $candidateId)->first();
    $event = DB::table('hr_candidate_application_events')->where('application_id', $application->id)->first();
    expect(json_decode($event->snapshot, true))->toBe(['owner_staff_id' => $otherLocationOwner->id]);

    $spellId = (string) Str::uuid();
    DB::table('hr_employment_spells')->insert([
        'id' => $spellId, 'staff_id' => $otherLocationOwner->id, 'spell_number' => 1,
        'joined_at' => today()->toDateString(), 'service_date' => today()->toDateString(),
        'gratuity_service_start' => today()->toDateString(), 'status' => 'active', 'created_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_candidate_applications')->where('id', $application->id)->update(['stage' => 'interview']);
    $interviewPayload = [
        'interview_type' => 'screening', 'scheduled_at' => now()->addDay()->toISOString(),
        'timezone' => 'Asia/Colombo', 'panel_staff_ids' => [$actorStaff->id],
    ];
    actingAs($user, 'api')->withHeaders($actorHeaders)->postJson('/api/hr/recruitment/applications/'.$application->id.'/interviews', $interviewPayload)
        ->assertStatus(422);
    $interviewPayload['panel_staff_ids'] = [$otherLocationOwner->id];
    actingAs($user, 'api')->withHeaders($actorHeaders)->postJson('/api/hr/recruitment/applications/'.$application->id.'/interviews', $interviewPayload)
        ->assertCreated();

    $actorSpellId = (string) Str::uuid();
    DB::table('hr_employment_spells')->insert([
        'id' => $actorSpellId, 'staff_id' => $actorStaff->id, 'spell_number' => 1,
        'joined_at' => today()->toDateString(), 'service_date' => today()->toDateString(),
        'gratuity_service_start' => today()->toDateString(), 'status' => 'active', 'created_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $interviewPayload['panel_staff_ids'] = [$actorStaff->id];
    actingAs($user, 'api')->withHeaders($actorHeaders)->postJson('/api/hr/recruitment/applications/'.$application->id.'/interviews', $interviewPayload)
        ->assertCreated();
    actingAs($user, 'api')->withHeaders($actorHeaders)->getJson('/api/hr/recruitment/interviews/mine')->assertOk()->assertJsonCount(1, 'data');

});
