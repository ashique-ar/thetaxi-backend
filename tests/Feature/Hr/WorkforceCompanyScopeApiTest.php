<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('offers only companies in the authenticated Staff scope and rejects unrelated companies', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $user = User::factory()->create();
    $role = Role::create(['name' => 'hr_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.leave.config.manage', 'hr.leave.config.approve', 'hr.work-requests.config.manage', 'hr.work-requests.config.approve', 'staff.view-legal-entity']);
    $user->assignRole($role);
    $authorizedCompany = Company::create(['name' => 'Authorized Workforce Company']);
    $unassignedCompany = Company::create(['name' => 'Unassigned Workforce Company']);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $authorizedCompany->id]);

    $options = actingAs($user, 'api')->getJson('/api/hr/workforce/company-options')->assertOk();
    $companyIds = collect($options->json('data'))->pluck('value')->all();
    expect($companyIds)->toContain($authorizedCompany->id);
    expect($companyIds)->not->toContain($unassignedCompany->id);

    actingAs($user, 'api')->getJson('/api/hr/workforce/references?company_id='.$authorizedCompany->id)
        ->assertOk()->assertJsonPath('data.company_id', $authorizedCompany->id);
    actingAs($user, 'api')->getJson('/api/hr/workforce/references?company_id='.$unassignedCompany->id)
        ->assertForbidden();
    actingAs($user, 'api')->getJson('/api/hr/workforce/leave/policies?company_id='.$authorizedCompany->id)
        ->assertOk()->assertJsonPath('data.total', 0);
    actingAs($user, 'api')->getJson('/api/hr/workforce/work-request-policies?company_id='.$authorizedCompany->id)
        ->assertOk()->assertJsonPath('data.total', 0);
    actingAs($user, 'api')->getJson('/api/hr/workforce/work-request-policies?company_id='.$unassignedCompany->id)
        ->assertForbidden();
});

it('hides Leave decision requests outside the approver legal entity as not found', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $approver = User::factory()->create();
    $role = Role::create(['name' => 'leave_approver_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.leave.approve', 'staff.view-legal-entity']);
    $approver->assignRole($role);
    $approverCompany = Company::create(['name' => 'Approver Company']);
    $otherCompany = Company::create(['name' => 'Other Leave Company']);
    Staff::factory()->create(['user_id' => $approver->id, 'company_id' => $approverCompany->id]);
    $requester = User::factory()->create();
    $subject = Staff::factory()->create(['user_id' => $requester->id, 'company_id' => $otherCompany->id]);
    $typeId = (string) Str::uuid();
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert([
        'id' => $typeId, 'company_id' => $otherCompany->id, 'code' => 'ANNUAL', 'name' => 'Annual leave',
        'category' => 'annual', 'unit' => 'day', 'paid' => true, 'medical_confidential' => false,
        'effective_from' => now()->toDateString(), 'status' => 'active', 'created_by' => $requester->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_policies')->insert([
        'id' => $policyId, 'company_id' => $otherCompany->id, 'leave_type_id' => $typeId,
        'code' => 'ANNUAL-1', 'version' => 1, 'rules' => json_encode(['minutes_per_day' => 480]),
        'effective_from' => now()->toDateString(), 'status' => 'approved', 'created_by' => $requester->id,
        'approved_by' => $requester->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_leave_requests')->insert([
        'id' => $requestId, 'company_id' => $otherCompany->id, 'staff_id' => $subject->id,
        'leave_type_id' => $typeId, 'policy_id' => $policyId, 'start_date' => '2026-11-02', 'end_date' => '2026-11-02',
        'unit' => 'day', 'requested_minutes' => 480, 'reserved_minutes' => 480, 'status' => 'pending_approval',
        'reason' => 'Leave', 'calculation_snapshot' => '{}', 'request_checksum' => str_repeat('a', 64),
        'idempotency_key' => 'foreign-leave-decision', 'requested_by' => $requester->id,
        'approval_level' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($approver, 'api')->postJson('/api/hr/workforce/leave/requests/'.$requestId.'/decide', [
        'action' => 'approve', 'reason' => 'Reviewed',
    ])->assertNotFound();
    actingAs($approver, 'api')->postJson('/api/hr/workforce/leave/requests/'.Str::uuid().'/decide', [
        'action' => 'approve', 'reason' => 'Reviewed',
    ])->assertNotFound();
});

it('hides Work Request decisions outside the approver legal entity as not found', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    $approver = User::factory()->create();
    $role = Role::create(['name' => 'work_request_approver_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.work-requests.approve', 'staff.view-legal-entity']);
    $approver->assignRole($role);
    $approverCompany = Company::create(['name' => 'Work Approver Company']);
    $otherCompany = Company::create(['name' => 'Other Work Company']);
    Staff::factory()->create(['user_id' => $approver->id, 'company_id' => $approverCompany->id]);
    $requester = User::factory()->create();
    $subject = Staff::factory()->create(['user_id' => $requester->id, 'company_id' => $otherCompany->id]);
    $policyId = (string) Str::uuid();
    $requestId = (string) Str::uuid();
    DB::table('hr_work_request_policies')->insert([
        'id' => $policyId, 'company_id' => $otherCompany->id, 'request_kind' => 'overtime',
        'code' => 'OT-1', 'version' => 1, 'rules' => '{}', 'effective_from' => now()->toDateString(),
        'status' => 'approved', 'created_by' => $requester->id, 'approved_by' => $requester->id,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_work_requests')->insert([
        'id' => $requestId, 'company_id' => $otherCompany->id, 'staff_id' => $subject->id,
        'policy_id' => $policyId, 'request_kind' => 'overtime', 'starts_at' => '2026-11-02 09:00:00+05:30',
        'ends_at' => '2026-11-02 10:00:00+05:30', 'requested_minutes' => 60, 'status' => 'pending_approval',
        'reason' => 'Overtime', 'request_snapshot' => '{}', 'request_checksum' => str_repeat('b', 64),
        'idempotency_key' => 'foreign-work-request-decision', 'requested_by' => $requester->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAs($approver, 'api')->postJson('/api/hr/workforce/work-requests/'.$requestId.'/decide', [
        'action' => 'approve', 'decision_note' => 'Reviewed',
    ])->assertNotFound();
    actingAs($approver, 'api')->postJson('/api/hr/workforce/work-requests/'.Str::uuid().'/decide', [
        'action' => 'approve', 'decision_note' => 'Reviewed',
    ])->assertNotFound();
});
