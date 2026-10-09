<?php

use App\Models\Company;
use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrEmploymentSpell;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('keeps employee change requests and their approver in the actor legal entity', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();

    $actor = User::factory()->create();
    $role = Role::create(['name' => 'lifecycle_change_company_scope_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.lifecycle.change.request', 'staff.view-all']);
    $actor->assignRole($role);

    $company = Company::create(['name' => 'Change Request Company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other Change Company', 'is_active' => true, 'is_default' => false]);
    $actorStaff = Staff::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id, 'is_active' => true, 'created_user_id' => $actor->id]);
    $subject = Staff::factory()->create(['company_id' => $company->id]);
    $otherSubject = Staff::factory()->create(['company_id' => $otherCompany->id]);
    $validApprover = Staff::factory()->create(['company_id' => $company->id]);
    $formerApprover = Staff::factory()->former()->create(['company_id' => $company->id]);
    $foreignApprover = Staff::factory()->create(['company_id' => $otherCompany->id]);

    $spell = HrEmploymentSpell::factory()->create([
        'staff_id' => $subject->id,
        'company_id' => $company->id,
        'status' => 'active',
    ]);
    HrEmploymentAssignment::factory()->create([
        'employment_spell_id' => $spell->id,
        'staff_id' => $subject->id,
        'company_id' => $company->id,
        'effective_from' => now()->subDay(),
        'effective_until' => null,
    ]);

    $payload = fn (string $approverId) => [
        'change_type' => 'promotion',
        'effective_date' => today()->addDay()->toDateString(),
        'proposed_snapshot' => ['position_title' => 'Sales Manager'],
        'impact_snapshot' => ['payroll' => 'review_required'],
        'reason' => 'Approved role change request.',
        'approver_staff_id' => $approverId,
        'sla_hours' => 24,
        'idempotency_key' => (string) Str::uuid(),
    ];

    actingAs($actor, 'api')->postJson("/api/hr/lifecycle/staff/{$otherSubject->id}/changes", $payload($validApprover->id))
        ->assertForbidden();
    actingAs($actor, 'api')->postJson("/api/hr/lifecycle/staff/{$subject->id}/changes", $payload($foreignApprover->id))
        ->assertUnprocessable();
    actingAs($actor, 'api')->postJson("/api/hr/lifecycle/staff/{$subject->id}/changes", $payload($formerApprover->id))
        ->assertUnprocessable();
    $this->assertDatabaseCount('hr_employee_change_requests', 0);

    $validPayload = $payload($validApprover->id);
    $createdChange = actingAs($actor, 'api')->postJson("/api/hr/lifecycle/staff/{$subject->id}/changes", $validPayload)
        ->assertCreated();
    $this->assertDatabaseHas('hr_request_index', [
        'requester_staff_id' => $subject->id,
        'current_owner_staff_id' => $validApprover->id,
    ]);

    config(['hr.features.people_core' => true]);
    $changeId = (string) $createdChange->json('data.id');
    $lifecycle = app(\App\Services\Hr\Lifecycle\LifecycleService::class);
    $approved = $lifecycle->approveChange($changeId, (string) $validApprover->user_id, (string) $company->id);
    $assignmentCount = DB::table('hr_employment_assignments')->where('staff_id', $subject->id)->count();
    $replayed = $lifecycle->approveChange($changeId, (string) $validApprover->user_id, (string) $company->id);
    expect($replayed->result_assignment_id)->toBe($approved->result_assignment_id)
        ->and(DB::table('hr_employment_assignments')->where('staff_id', $subject->id)->count())->toBe($assignmentCount)
        ->and(fn () => $lifecycle->approveChange($changeId, (string) $foreignApprover->user_id, (string) $company->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    DB::table('companies')->where('id', $company->id)->update(['is_active' => false]);
    expect(fn () => $lifecycle->approveChange($changeId, (string) $validApprover->user_id, (string) $company->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $replayedChange = actingAs($actor, 'api')->postJson("/api/hr/lifecycle/staff/{$subject->id}/changes", $validPayload)
        ->assertCreated();
    expect($replayedChange->json('data.id'))->toBe($createdChange->json('data.id'));
    $this->assertDatabaseCount('hr_employee_change_requests', 1);
});
