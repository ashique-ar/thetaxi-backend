<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns searchable exact-hydrated leave policies assigned to the Staff for the full interval', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    $type = leave_policy_option_type($company, $admin->id);
    $first = leave_policy_option_seed($company, $admin->id, $staff->id, $type, 'ANNUAL-A');
    $second = leave_policy_option_seed($company, $admin->id, $staff->id, $type, 'ANNUAL-B');
    $unassigned = leave_policy_option_seed($company, $admin->id, null, $type, 'ANNUAL-C');
    $expired = leave_policy_option_seed($company, $admin->id, $staff->id, $type, 'ANNUAL-D', today()->addDays(3)->toDateString());
    $foreignCompany = Company::create(['name' => 'Other leave policy tenant']);
    $foreignType = leave_policy_option_type($foreignCompany, $admin->id, 'FOREIGN');
    $foreignStaff = Staff::factory()->create(['company_id' => $foreignCompany->id]);
    $foreign = leave_policy_option_seed($foreignCompany, $admin->id, $foreignStaff->id, $foreignType, 'ANNUAL-E');
    $start = today()->addDays(2)->toDateString();
    $end = today()->addDays(4)->toDateString();
    $url = '/api/hr/workforce/leave-policy-options?company_id='.$company->id.'&staff_id='.$staff->id.'&start_date='.$start.'&end_date='.$end.'&search=ANNUAL';

    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=1')->assertOk()
        ->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first)
        ->assertJsonPath('data.data.0.label', 'Annual leave · ANNUAL-A v1');
    actingAs($admin, 'api')->getJson($url.'&per_page=1&page=2')->assertOk()
        ->assertJsonPath('data.data.0.value', $second);
    actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-policy-options?company_id='.$company->id.'&staff_id='.$staff->id.'&start_date='.$start.'&end_date='.$end.'&selected_id='.$second)
        ->assertOk()->assertJsonPath('data.0.value', $second);
    foreach ([$unassigned, $expired, $foreign] as $excluded) {
        actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-policy-options?company_id='.$company->id.'&staff_id='.$staff->id.'&start_date='.$start.'&end_date='.$end.'&selected_id='.$excluded)
            ->assertOk()->assertJsonCount(0, 'data');
    }
    actingAs($admin, 'api')->getJson('/api/hr/workforce/leave-policy-options')->assertOk()->assertJsonCount(0, 'data');
    actingAs($admin, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

function leave_policy_option_type(Company $company, string $actorId, string $suffix = ''): string
{
    $id = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $id, 'company_id' => $company->id, 'code' => 'ANNUAL'.$suffix, 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => today()->toDateString(), 'status' => 'active', 'created_by' => $actorId,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function leave_policy_option_seed(Company $company, string $actorId, ?string $staffId, string $typeId, string $code, ?string $assignmentUntil = null): string
{
    $id = (string) Str::uuid();
    DB::table('hr_leave_policies')->insert([
        'id' => $id, 'company_id' => $company->id, 'leave_type_id' => $typeId, 'code' => $code, 'version' => 1,
        'rules' => json_encode([], JSON_THROW_ON_ERROR), 'effective_from' => today()->toDateString(),
        'status' => 'approved', 'created_by' => $actorId, 'approved_by' => $actorId, 'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($staffId) {
        DB::table('hr_leave_policy_assignments')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staffId, 'policy_id' => $id,
            'effective_from' => today()->toDateString(), 'effective_until' => $assignmentUntil, 'reason' => 'test assignment',
            'created_by' => $actorId, 'approved_by' => $actorId, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}
