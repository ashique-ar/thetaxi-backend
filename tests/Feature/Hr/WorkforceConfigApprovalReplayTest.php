<?php

use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('replays Workforce policy approval for the original approver without rewriting evidence', function () {
    [$approver, $company] = hr_seed_admin_actor();
    config(['hr.features.leave_overtime' => true]);
    $creator = User::factory()->create();
    $otherApprover = User::factory()->create();
    $otherApprover->assignRole('admin');
    Staff::factory()->create(['user_id' => $otherApprover->id, 'company_id' => $company->id]);
    $now = now();
    $leaveTypeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();

    DB::table('hr_leave_types')->insert([
        'id' => $leaveTypeId, 'company_id' => $company->id, 'code' => 'ANNUAL', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => today()->toDateString(), 'status' => 'active', 'created_by' => $creator->id,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $company->id, 'leave_type_id' => $leaveTypeId,
        'code' => 'ANNUAL-2026', 'version' => 1, 'rules' => json_encode(['minutes_per_day' => 480], JSON_THROW_ON_ERROR),
        'effective_from' => today()->toDateString(), 'status' => 'pending_approval', 'created_by' => $creator->id,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $url = '/api/hr/workforce/leave/policies/'.$policyId.'/approve';
    actingAs($approver, 'api')->postJson($url)->assertOk();
    $approved = DB::table('hr_leave_policies')->where('id', $policyId)->first();
    actingAs($approver, 'api')->postJson($url)->assertOk();
    expect(DB::table('hr_leave_policies')->where('id', $policyId)->value('approved_by'))->toBe($approver->id)
        ->and(DB::table('hr_leave_policies')->where('id', $policyId)->value('approved_at'))->toBe($approved->approved_at)
        ->and(DB::table('hr_leave_policies')->where('id', $policyId)->value('updated_at'))->toBe($approved->updated_at);

    actingAs($otherApprover, 'api')->postJson($url)->assertStatus(409);
});
