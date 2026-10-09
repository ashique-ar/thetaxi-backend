<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns only current conflict-free case-team chairs from the selected Staff company', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Selected case company']);
    $admin->givePermissionTo('hr.relations.case-admin', 'hr.relations.case.transition');
    $actorStaff = Staff::query()->where('user_id', $admin->id)->where('company_id', $company->id)->firstOrFail();
    $context = UserContext::query()->where('user_id', $admin->id)->where('context_type', 'staff')
        ->where('context_id', $actorStaff->id)->where('is_active', true)->firstOrFail();
    $caseId = (string) Str::uuid();
    DB::table('hr_relation_cases')->insert([
        'id' => $caseId, 'company_id' => $company->id, 'case_number' => 'REL-CHAIR-'.Str::upper(Str::random(8)),
        'case_type' => 'grievance', 'severity' => 'moderate', 'confidentiality' => 'restricted',
        'subject' => 'Chair selector test', 'encrypted_summary' => encrypt('Protected summary'),
        'status' => 'hearing', 'opened_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $active = Staff::factory()->create(['company_id' => $company->id, 'code' => 'CHAIR-ACTIVE']);
    $expired = Staff::factory()->create(['company_id' => $company->id, 'code' => 'CHAIR-EXPIRED']);
    $future = Staff::factory()->create(['company_id' => $company->id, 'code' => 'CHAIR-FUTURE']);
    $ended = Staff::factory()->former()->create(['company_id' => $company->id, 'code' => 'CHAIR-ENDED']);
    $conflicted = Staff::factory()->create(['company_id' => $company->id, 'code' => 'CHAIR-CONFLICT']);
    $addTeamMember = function (Staff $staff, string $from, ?string $until = null) use ($caseId, $admin): void {
        DB::table('hr_relation_case_team')->insert([
            'id' => (string) Str::uuid(), 'case_id' => $caseId, 'staff_id' => $staff->id,
            'team_role' => 'hearing_chair', 'capabilities' => json_encode(['transition'], JSON_THROW_ON_ERROR),
            'status' => 'active', 'effective_from' => $from, 'effective_until' => $until,
            'nominated_by' => $admin->id, 'approved_by' => $admin->id, 'approved_at' => now(),
            'decision_reason' => 'Test setup', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $addTeamMember($active, today()->toDateString());
    $addTeamMember($expired, today()->subDays(4)->toDateString(), today()->subDay()->toDateString());
    $addTeamMember($future, today()->addDay()->toDateString());
    $addTeamMember($ended, today()->subDay()->toDateString());
    $addTeamMember($conflicted, today()->subDay()->toDateString());
    DB::table('hr_relation_conflicts')->insert([
        'id' => (string) Str::uuid(), 'case_id' => $caseId, 'staff_id' => $conflicted->id,
        'conflict_type' => 'personal', 'encrypted_reason' => encrypt('Conflict'), 'status' => 'declared',
        'declared_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($admin, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/relations/hearing-chair-candidates?case_id='.$caseId)
        ->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.value', $active->id);
});
