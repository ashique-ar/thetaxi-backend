<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches authorized active approvers and submits, replays, lists and approves a leave delegation', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $company = Company::create(['name' => 'ESS Delegation Company', 'is_active' => true, 'is_default' => true]);
    $foreignCompany = Company::create(['name' => 'Other ESS Delegation Company', 'is_active' => true, 'is_default' => false]);

    $context = function (User $user, Staff $staff): UserContext {
        return UserContext::create([
            'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $staff->id,
            'is_active' => true, 'created_user_id' => $user->id,
        ]);
    };

    $delegatorUser = User::factory()->create();
    $delegatorUser->givePermissionTo('hr.mss.delegate');
    $delegator = Staff::factory()->create(['user_id' => $delegatorUser->id, 'company_id' => $company->id]);
    $delegatorContext = $context($delegatorUser, $delegator);

    $delegateUser = User::factory()->create();
    $delegateUser->givePermissionTo(['hr.mss.approve', 'hr.leave.approve']);
    $delegate = Staff::factory()->create(['user_id' => $delegateUser->id, 'company_id' => $company->id]);
    $delegateContext = $context($delegateUser, $delegate);
    $leaveRequestId = (string) Str::uuid();
    DB::table('hr_request_index')->insert([
        'id' => $leaveRequestId, 'company_id' => $company->id, 'requester_staff_id' => $delegator->id,
        'request_type' => 'leave', 'source_type' => 'leave_request', 'source_id' => (string) Str::uuid(),
        'status' => 'pending_approval', 'summary' => 'Leave request',
        'current_owner_staff_id' => $delegator->id, 'capability_snapshot' => '{}',
        'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $unqualifiedUser = User::factory()->create();
    $unqualified = Staff::factory()->create(['user_id' => $unqualifiedUser->id, 'company_id' => $company->id]);
    $inboxOnlyUser = User::factory()->create();
    $inboxOnlyUser->givePermissionTo('hr.mss.approve');
    $inboxOnlyStaff = Staff::factory()->create(['user_id' => $inboxOnlyUser->id, 'company_id' => $company->id]);
    $formerUser = User::factory()->create();
    $formerUser->givePermissionTo('hr.mss.approve');
    Staff::factory()->former()->create(['user_id' => $formerUser->id, 'company_id' => $company->id]);
    $foreignUser = User::factory()->create();
    $foreignUser->givePermissionTo('hr.mss.approve');
    Staff::factory()->create(['user_id' => $foreignUser->id, 'company_id' => $foreignCompany->id]);

    $options = actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegatorContext->id,
    ])->getJson('/api/hr/ess/delegate-options')->assertOk();
    expect($options->json('data'))->toBe([
        ['value' => $delegate->id, 'label' => trim($delegateUser->first_name.' '.$delegateUser->last_name).' ('.$delegate->code.')', 'status' => 'active'],
    ]);
    actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegatorContext->id,
    ])->getJson('/api/hr/ess/delegate-options?search='.$delegate->code)->assertOk()
        ->assertJsonPath('data.0.value', $delegate->id);

    $payload = [
        'delegate_staff_id' => $delegate->id,
        'request_types' => ['leave'],
        'effective_from' => today()->toDateString(),
        'effective_until' => today()->addDays(30)->toDateString(),
        'reason' => 'Scheduled leave coverage',
        'idempotency_key' => (string) Str::uuid(),
    ];
    $url = '/api/hr/ess/delegations';
    actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegatorContext->id,
    ])->postJson($url, array_replace($payload, ['delegate_staff_id' => $unqualified->id,
        'idempotency_key' => (string) Str::uuid()]))->assertNotFound();
    actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegatorContext->id,
    ])->postJson($url, array_replace($payload, ['delegate_staff_id' => $inboxOnlyStaff->id,
        'idempotency_key' => (string) Str::uuid()]))->assertNotFound();
    actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegatorContext->id,
    ])->postJson($url, array_replace($payload, ['request_types' => ['overtime'],
        'idempotency_key' => (string) Str::uuid()]))->assertUnprocessable();
    $delegation = actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegatorContext->id,
    ])->postJson($url, $payload)->assertCreated()->json('data.id');
    actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegatorContext->id,
    ])->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $delegation);
    actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegatorContext->id,
    ])->postJson($url, array_replace($payload, ['reason' => 'Changed facts']))->assertStatus(409);
    $this->assertDatabaseCount('hr_approval_delegations', 1);

    $approverUser = User::factory()->create();
    $approverUser->givePermissionTo(['hr.mss.approve', 'hr.mss.delegations.approve']);
    $approver = Staff::factory()->create(['user_id' => $approverUser->id, 'company_id' => $company->id]);
    $approverContext = $context($approverUser, $approver);
    actingAs($approverUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $approverContext->id,
    ])->getJson('/api/hr/ess/delegations')->assertOk()->assertJsonPath('data.0.id', $delegation);
    DB::table('staff')->where('id', $delegator->id)->update(['company_id' => $foreignCompany->id]);
    actingAs($approverUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $approverContext->id,
    ])->getJson('/api/hr/ess/delegations')->assertOk()->assertJsonMissing(['id' => $delegation]);
    DB::table('staff')->where('id', $delegator->id)->update(['company_id' => $company->id]);
    DB::table('staff')->where('id', $delegate->id)->update(['employment_ended_at' => now()]);
    actingAs($approverUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $approverContext->id,
    ])->postJson($url.'/'.$delegation.'/approve')->assertConflict();
    DB::table('staff')->where('id', $delegate->id)->update(['employment_ended_at' => null]);
    $approvalEventsBefore = DB::table('activity_log')->where('description', 'approval_delegation_approved')->count();
    $approved = actingAs($approverUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $approverContext->id,
    ])->postJson($url.'/'.$delegation.'/approve')->assertOk()
        ->assertJsonPath('data.approved_by_staff_id', $approver->id);
    $approvalEvents = DB::table('activity_log')->where('description', 'approval_delegation_approved')->count();
    expect($approvalEvents)->toBe($approvalEventsBefore + 1);
    $replay = actingAs($approverUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $approverContext->id,
    ])->postJson($url.'/'.$delegation.'/approve')->assertOk()
        ->assertJsonPath('data.id', $delegation)
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_by_staff_id', $approver->id);
    expect($replay->json('data.approved_at'))->toBe($approved->json('data.approved_at'))
        ->and(DB::table('activity_log')->where('description', 'approval_delegation_approved')->count())->toBe($approvalEvents);

    $otherApproverUser = User::factory()->create();
    $otherApproverUser->givePermissionTo(['hr.mss.approve', 'hr.mss.delegations.approve']);
    $otherApprover = Staff::factory()->create(['user_id' => $otherApproverUser->id, 'company_id' => $company->id]);
    $otherApproverContext = $context($otherApproverUser, $otherApprover);
    actingAs($otherApproverUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $otherApproverContext->id,
    ])->postJson($url.'/'.$delegation.'/approve')->assertConflict();
    $this->assertDatabaseHas('hr_approval_delegations', [
        'id' => $delegation, 'approved_by' => $approverUser->id, 'approved_by_staff_id' => $approver->id,
    ]);
    expect(DB::table('activity_log')->where('description', 'approval_delegation_approved')->count())->toBe($approvalEvents);

    actingAs($delegateUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $delegateContext->id,
    ])->getJson('/api/hr/ess/approval-inbox')->assertOk()
        ->assertJsonPath('data.data.0.id', $leaveRequestId);
    $this->assertDatabaseHas('hr_approval_delegations', [
        'id' => $delegation, 'status' => 'approved', 'approved_by' => $approverUser->id,
        'approved_by_staff_id' => $approver->id, 'company_id' => $company->id,
    ]);
});
