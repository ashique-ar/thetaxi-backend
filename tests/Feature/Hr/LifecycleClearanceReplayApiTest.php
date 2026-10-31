<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists the installation-wide clearance queue and replays exact completion once', function () {
    config(['hr.features.employee_self_service' => true]);
    Permission::findOrCreate('hr.lifecycle.clearance', 'api');
    $actor = User::factory()->create();
    $actor->givePermissionTo('hr.lifecycle.clearance');
    $actorStaff = Staff::factory()->create(['user_id' => $actor->id]);
    UserContext::create(['user_id' => $actor->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id, 'is_active' => true, 'created_user_id' => $actor->id]);
    $subject = Staff::factory()->create();
    $otherActor = User::factory()->create();
    $otherActor->givePermissionTo('hr.lifecycle.clearance');
    $otherActorStaff = Staff::factory()->create(['user_id' => $otherActor->id]);
    UserContext::create(['user_id' => $otherActor->id, 'context_type' => 'staff', 'context_id' => $otherActorStaff->id, 'is_active' => true, 'created_user_id' => $otherActor->id]);

    $makeItem = function (string $staffId) use ($actor): string {
        $exitId = (string) Str::uuid();
        DB::table('hr_exit_cases')->insert([
            'id' => $exitId, 'staff_id' => $staffId, 'exit_type' => 'resignation',
            'proposed_last_working_date' => today()->toDateString(), 'reason_code' => 'resignation',
            'status' => 'clearance', 'impact_snapshot' => '{}', 'opened_by' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('hr_exit_clearance_items')->insert([
            'id' => $id, 'exit_case_id' => $exitId, 'clearance_type' => 'handover', 'title' => 'Complete handover',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    };
    $itemId = $makeItem($subject->id);
    $url = "/api/hr/lifecycle/clearance/{$itemId}/complete";

    $first = actingAs($actor, 'api')->postJson($url, ['resolution' => 'Handed over files and access checklist.'])->assertOk();
    $replay = actingAs($actor, 'api')->postJson($url, ['resolution' => 'Handed over files and access checklist.'])
        ->assertOk()->assertJsonPath('idempotent_replay', true);
    actingAs($actor, 'api')->postJson($url, ['resolution' => 'Changed resolution.'])->assertConflict();
    actingAs($otherActor, 'api')->postJson($url, ['resolution' => 'Handed over files and access checklist.'])->assertConflict();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('hr_exit_clearance_items')->where('id', $itemId)->value('status'))->toBe('completed')
        ->and(DB::table('activity_log')->where('log_name', 'hr-lifecycle')->where('description', 'exit_clearance_completed')->count())->toBe(1);

    actingAs($actor, 'api')->getJson('/api/hr/lifecycle/clearance')->assertOk()
        ->assertJsonFragment(['id' => $itemId, 'title' => 'Complete handover'])
        ->assertJsonMissingPath('data.data.0.resolution')
        ->assertJsonMissingPath('data.data.0.completed_by');
});
