<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('allows authorized safety managers to act across locations and preserves one-time verification', function () {
    (new Database\Seeders\AllPermissionsSeeder())->run();
    config(['hr.features.relations_safety' => true]);

    $actor = User::factory()->create();
    $role = Role::create(['name' => 'safety_action_installation_wide_tester', 'guard_name' => 'api']);
    $role->givePermissionTo(['hr.safety.action', 'hr.safety.approve', 'hr.safety.manage']);
    $actor->assignRole($role);

    $actorStaff = Staff::factory()->create(['user_id' => $actor->id]);
    UserContext::create([
        'user_id' => $actor->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id,
        'is_active' => true, 'created_user_id' => $actor->id,
    ]);
    $owner = Staff::factory()->create();
    $anotherOwner = Staff::factory()->create();

    $createAction = function (string $ownerId, string $status): string {
        $id = (string) Str::uuid();
        DB::table('hr_safety_actions')->insert([
            'id' => $id, 'incident_id' => null, 'action_type' => 'review', 'title' => 'Review control evidence',
            'description' => 'Confirm that the recorded control is in place.', 'owner_staff_id' => $ownerId,
            'due_at' => today()->toDateString(), 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };

    $openAction = $createAction($owner->id, 'open');
    $completedAction = $createAction($anotherOwner->id, 'completed_pending_verification');

    $complete = actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$openAction}/complete", [
        'completion_evidence' => ['reference' => 'evidence-2'],
    ])->assertOk();
    actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$openAction}/complete", [
        'completion_evidence' => ['reference' => 'evidence-2'],
    ])->assertStatus(409);
    $verify = actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$completedAction}/verify")->assertOk();
    actingAs($actor, 'api')->postJson("/api/hr/safety/actions/{$completedAction}/verify")->assertStatus(409);
    $this->assertDatabaseHas('hr_safety_actions', ['id' => $openAction, 'status' => 'completed_pending_verification']);
    $this->assertDatabaseHas('hr_safety_actions', ['id' => $completedAction, 'status' => 'verified', 'verified_by' => $actor->id]);
    $completionAudit = DB::table('activity_log')->where('description', 'safety_action_completed')->first();
    $verificationAudit = DB::table('activity_log')->where('description', 'safety_action_verified')->first();
    expect(json_decode($completionAudit->properties, true))->toMatchArray([
        'action_id' => $openAction,
        'owner_staff_id' => $owner->id, 'status' => 'completed_pending_verification',
    ])->and(json_decode($verificationAudit->properties, true))->toMatchArray([
        'action_id' => $completedAction,
        'owner_staff_id' => $anotherOwner->id, 'status' => 'verified',
    ]);
    expect($complete->json('data.status'))->toBe('completed_pending_verification')
        ->and($verify->json('data.status'))->toBe('verified');
});
