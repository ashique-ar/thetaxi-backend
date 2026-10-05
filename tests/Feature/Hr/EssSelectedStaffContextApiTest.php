<?php

use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('scopes self-service requests and history to the selected active Staff context', function () {
    [$user, $firstCompany] = hr_seed_admin_actor([], true);
    $firstStaff = Staff::query()->where('user_id', $user->id)->firstOrFail();
    $secondCompany = App\Models\Company::create(['name' => 'Second ESS Company']);
    $secondStaff = Staff::factory()->create(['user_id' => $user->id, 'company_id' => $secondCompany->id]);
    $firstContext = UserContext::query()->where('user_id', $user->id)->where('context_type', 'staff')->where('context_id', $firstStaff->id)->firstOrFail();
    $secondContext = UserContext::create([
        'user_id' => $user->id, 'context_type' => 'staff', 'context_id' => $secondStaff->id,
        'is_active' => true, 'created_user_id' => $user->id,
    ]);
    $firstRequestId = (string) Str::uuid();
    $secondRequestId = (string) Str::uuid();
    foreach ([[$firstRequestId, $firstCompany->id, $firstStaff->id], [$secondRequestId, $secondCompany->id, $secondStaff->id]] as [$id, $companyId, $staffId]) {
        DB::table('hr_request_index')->insert([
            'id' => $id, 'company_id' => $companyId, 'requester_staff_id' => $staffId,
            'request_type' => 'leave', 'source_type' => 'leave_request', 'source_id' => (string) Str::uuid(),
            'status' => 'pending_approval', 'summary' => 'Leave request', 'capability_snapshot' => '{}',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $mismatchedCompanyRequestId = (string) Str::uuid();
    DB::table('hr_request_index')->insert([
        'id' => $mismatchedCompanyRequestId, 'company_id' => $secondCompany->id, 'requester_staff_id' => $firstStaff->id,
        'request_type' => 'leave', 'source_type' => 'leave_request', 'source_id' => (string) Str::uuid(),
        'status' => 'pending_approval', 'summary' => 'Cross-company request', 'capability_snapshot' => '{}',
        'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $mismatchedOwnerRequestId = (string) Str::uuid();
    DB::table('hr_request_index')->insert([
        'id' => $mismatchedOwnerRequestId, 'company_id' => $firstCompany->id, 'requester_staff_id' => $firstStaff->id,
        'request_type' => 'leave', 'source_type' => 'leave_request', 'source_id' => (string) Str::uuid(),
        'status' => 'pending_approval', 'summary' => 'Cross-company owner', 'current_owner_staff_id' => $secondStaff->id,
        'capability_snapshot' => '{}', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = '/api/hr/ess/my-requests';
    actingAs($user, 'api')->getJson($url)->assertForbidden();
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => (string) Str::uuid(),
    ])->getJson($url)->assertForbidden();
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $firstContext->id,
    ])->getJson($url)->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $firstRequestId);
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $firstContext->id,
    ])->getJson($url.'/'.$mismatchedCompanyRequestId)->assertNotFound();
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $secondContext->id,
    ])->getJson($url)->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $secondRequestId);
    actingAs($user, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $secondContext->id,
    ])->getJson($url.'/'.$firstRequestId)->assertNotFound();
});

it('stores the selected approver Staff identity on a delegation decision', function () {
    [, $company] = hr_seed_admin_actor();
    $delegatorUser = App\Models\User::factory()->create();
    $delegateUser = App\Models\User::factory()->create();
    $delegateUser->givePermissionTo(['hr.mss.approve', 'hr.leave.approve']);
    $approverUser = App\Models\User::factory()->create();
    $delegator = Staff::factory()->create(['user_id' => $delegatorUser->id, 'company_id' => $company->id]);
    $delegate = Staff::factory()->create(['user_id' => $delegateUser->id, 'company_id' => $company->id]);
    $approver = Staff::factory()->create(['user_id' => $approverUser->id, 'company_id' => $company->id]);
    $context = UserContext::create([
        'user_id' => $delegatorUser->id, 'context_type' => 'staff', 'context_id' => $delegator->id,
        'is_active' => true, 'created_user_id' => $delegatorUser->id,
    ]);
    $approverContext = UserContext::create([
        'user_id' => $approverUser->id, 'context_type' => 'staff', 'context_id' => $approver->id,
        'is_active' => true, 'created_user_id' => $approverUser->id,
    ]);
    $delegatorUser->givePermissionTo('hr.mss.delegate');
    $approverUser->givePermissionTo('hr.mss.delegations.approve');

    $delegation = actingAs($delegatorUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->postJson('/api/hr/ess/delegations', [
        'delegate_staff_id' => $delegate->id,
        'request_types' => ['leave'],
        'effective_from' => now()->toDateString(),
        'effective_until' => now()->addDays(30)->toDateString(),
        'reason' => 'Delegation during scheduled leave',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertCreated()->json('data.id');

    actingAs($approverUser, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $approverContext->id,
    ])->postJson('/api/hr/ess/delegations/'.$delegation.'/approve')->assertOk()
        ->assertJsonPath('data.approved_by_staff_id', $approver->id);

    $this->assertDatabaseHas('hr_approval_delegations', [
        'id' => $delegation,
        'company_id' => $company->id,
        'approved_by' => $approverUser->id,
        'approved_by_staff_id' => $approver->id,
    ]);
});

it('grants delegated inbox requests only when request type and approver Staff identity match', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $company = App\Models\Company::create(['name' => 'ESS Delegation Evidence Company', 'is_active' => true, 'is_default' => true]);
    $actor = App\Models\User::factory()->create();
    $actor->givePermissionTo('hr.mss.approve');
    $actorStaff = Staff::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    $actorContext = UserContext::create([
        'user_id' => $actor->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id,
        'is_active' => true, 'created_user_id' => $actor->id,
    ]);
    $mismatchedOwner = Staff::factory()->create(['company_id' => $company->id]);
    $wrongTypeOwner = Staff::factory()->create(['company_id' => $company->id]);
    $validOwner = Staff::factory()->create(['company_id' => $company->id]);
    $multiTypeOwner = Staff::factory()->create(['company_id' => $company->id]);
    $approver = App\Models\User::factory()->create();
    $approverStaff = Staff::factory()->create(['user_id' => $approver->id, 'company_id' => $company->id]);
    $mismatchedApproverStaff = Staff::factory()->create(['company_id' => $company->id]);
    $createdBy = App\Models\User::factory()->create();

    $createDelegation = function (Staff $delegator, string $requestType, string $approverStaffId) use ($actorStaff, $approver, $company, $createdBy): void {
        DB::table('hr_approval_delegations')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'delegator_staff_id' => $delegator->id, 'delegate_staff_id' => $actorStaff->id,
            'request_types' => json_encode([$requestType], JSON_THROW_ON_ERROR),
            'effective_from' => today()->toDateString(), 'effective_until' => today()->addDays(10)->toDateString(),
            'reason' => 'Delegated approval coverage', 'status' => 'approved',
            'created_by' => $createdBy->id, 'approved_by' => $approver->id,
            'approved_by_staff_id' => $approverStaffId, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $createRequest = function (Staff $requester) use ($company): string {
        $id = (string) Str::uuid();
        DB::table('hr_request_index')->insert([
            'id' => $id, 'company_id' => $company->id, 'requester_staff_id' => $requester->id,
            'request_type' => 'leave', 'source_type' => 'leave_request', 'source_id' => (string) Str::uuid(),
            'status' => 'pending_approval', 'summary' => 'Leave request',
            'current_owner_staff_id' => $requester->id, 'capability_snapshot' => '{}',
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    $createRequest($mismatchedOwner);
    $createRequest($wrongTypeOwner);
    $validRequest = $createRequest($validOwner);
    $createDelegation($mismatchedOwner, 'leave', $mismatchedApproverStaff->id);
    $createDelegation($wrongTypeOwner, 'overtime', $approverStaff->id);
    $createDelegation($validOwner, 'leave', $approverStaff->id);
    $createDelegation($multiTypeOwner, 'leave', $approverStaff->id);
    DB::table('hr_approval_delegations')->where('delegator_staff_id', $multiTypeOwner->id)->update([
        'request_types' => json_encode(['leave', 'work_request'], JSON_THROW_ON_ERROR),
    ]);
    DB::table('hr_request_index')->insert([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'requester_staff_id' => $multiTypeOwner->id,
        'request_type' => 'work_request', 'source_type' => 'work_request', 'source_id' => (string) Str::uuid(),
        'status' => 'pending_approval', 'summary' => 'Unsupported delegated type',
        'current_owner_staff_id' => $multiTypeOwner->id, 'capability_snapshot' => '{}',
        'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = actingAs($actor, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $actorContext->id,
    ])->getJson('/api/hr/ess/approval-inbox')->assertOk();

    expect(collect($response->json('data.data'))->pluck('id')->all())->toBe([$validRequest]);
});
