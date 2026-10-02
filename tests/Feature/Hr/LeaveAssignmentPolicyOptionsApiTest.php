<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns only approved policies covering a full leave assignment interval', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $type = leave_assignment_policy_type($company->id, $admin->id);
    $covered = leave_assignment_policy_seed($company->id, $admin->id, $type, 'ASSIGN-A', null);
    leave_assignment_policy_seed($company->id, $admin->id, $type, 'ASSIGN-B', today()->addDays(10)->toDateString());
    leave_assignment_policy_seed($company->id, $admin->id, $type, 'ASSIGN-C', today()->addDays(30)->toDateString(), 'pending_approval');
    $start = today()->addDays(1)->toDateString();
    $end = today()->addDays(20)->toDateString();
    $url = '/api/hr/workforce/leave-assignment-policy-options?company_id='.$company->id.'&effective_from='.$start.'&effective_until='.$end.'&search=ASSIGN';

    actingAs($admin, 'api')->getJson($url)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.value', $covered);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.$covered)->assertOk()->assertJsonPath('data.0.value', $covered);
    actingAs($admin, 'api')->getJson($url.'&selected_id='.Str::uuid())->assertOk()->assertJsonCount(0, 'data');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

function leave_assignment_policy_seed(string $companyId, string $actorId, string $typeId, string $code, ?string $until, string $status = 'approved'): string
{
    $id = (string) Str::uuid();
    DB::table('hr_leave_policies')->insert([
        'id' => $id, 'company_id' => $companyId, 'leave_type_id' => $typeId, 'code' => $code, 'version' => 1,
        'rules' => json_encode([], JSON_THROW_ON_ERROR), 'effective_from' => today()->toDateString(), 'effective_until' => $until,
        'status' => $status, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function leave_assignment_policy_type(string $companyId, string $actorId): string
{
    $id = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $id, 'company_id' => $companyId, 'code' => 'ASSIGN', 'name' => 'Assignment leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => today()->toDateString(), 'status' => 'active', 'created_by' => $actorId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}
