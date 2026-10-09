<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('hides foreign safety actions and serializes same-company completion and verification', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    config(['hr.features.relations_safety' => true]);

    $actor = User::factory()->create();
    $role = Role::create(['name' => 'safety_action_company_boundary_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.safety.action', 'hr.safety.approve', 'hr.safety.manage']);
    $actor->assignRole($role);

    $company = Company::create(['name' => 'Safety Action Company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other Safety Action Company', 'is_active' => true, 'is_default' => false]);
    $actorStaff = Staff::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    UserContext::create([
        'user_id' => $actor->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id,
        'is_active' => true, 'created_user_id' => $actor->id,
    ]);
    $sameCompanyOwner = Staff::factory()->create(['company_id' => $company->id]);
    $foreignOwner = Staff::factory()->create(['company_id' => $otherCompany->id]);

    $createAction = function (string $ownerId, string $status): string {
        $id = (string) Str::uuid();
        DB::table('hr_safety_actions')->insert([
            'id' => $id, 'incident_id' => null, 'action_type' => 'review', 'title' => 'Review control evidence',
            'description' => 'Confirm that the recorded control is in place.', 'owner_staff_id' => $ownerId,
            'due_at' => today()->toDateString(), 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    $foreignOpen = $createAction($foreignOwner->id, 'open');
    $foreignCompleted = $createAction($foreignOwner->id, 'completed_pending_verification');
    $sameCompanyOpen = $createAction($sameCompanyOwner->id, 'open');
    $sameCompanyCompleted = $createAction($sameCompanyOwner->id, 'completed_pending_verification');

    actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$foreignOpen}/complete", [
        'completion_evidence' => ['reference' => 'evidence-1'],
    ])->assertNotFound();
    actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$foreignCompleted}/verify")->assertNotFound();
    $this->assertDatabaseHas('hr_safety_actions', ['id' => $foreignOpen, 'status' => 'open']);
    $this->assertDatabaseHas('hr_safety_actions', ['id' => $foreignCompleted, 'status' => 'completed_pending_verification']);

    $complete = actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$sameCompanyOpen}/complete", [
        'completion_evidence' => ['reference' => 'evidence-2'],
    ])->assertOk();
    actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$sameCompanyOpen}/complete", [
        'completion_evidence' => ['reference' => 'evidence-2'],
    ])->assertStatus(409);
    $verify = actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$sameCompanyCompleted}/verify")->assertOk();
    actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$sameCompanyCompleted}/verify")->assertStatus(409);
    $this->assertDatabaseHas('hr_safety_actions', ['id' => $sameCompanyOpen, 'status' => 'completed_pending_verification']);
    $this->assertDatabaseHas('hr_safety_actions', ['id' => $sameCompanyCompleted, 'status' => 'verified', 'verified_by' => $actor->id]);
    $completionAudit = DB::table('activity_log')->where('description', 'safety_action_completed')->first();
    $verificationAudit = DB::table('activity_log')->where('description', 'safety_action_verified')->first();
    expect(json_decode($completionAudit->properties, true))->toMatchArray([
        'action_id' => $sameCompanyOpen, 'company_id' => $company->id,
        'owner_staff_id' => $sameCompanyOwner->id, 'status' => 'completed_pending_verification',
    ])->and(json_decode($verificationAudit->properties, true))->toMatchArray([
        'action_id' => $sameCompanyCompleted, 'company_id' => $company->id,
        'owner_staff_id' => $sameCompanyOwner->id, 'status' => 'verified',
    ]);
    expect($complete->json('data.status'))->toBe('completed_pending_verification')
        ->and($verify->json('data.status'))->toBe('verified');
});
