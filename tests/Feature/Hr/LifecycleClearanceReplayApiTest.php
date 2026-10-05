<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lists only the actor company clearance queue and replays exact completion once', function () {
    config(['hr.features.employee_self_service' => true]);
    Permission::findOrCreate('hr.lifecycle.clearance', 'api');
    $actor = User::factory()->create();
    $actor->givePermissionTo('hr.lifecycle.clearance');
    $company = Company::create(['name' => 'Exit clearance company', 'is_active' => true, 'is_default' => true]);
    $foreign = Company::create(['name' => 'Foreign exit clearance company', 'is_active' => true]);
    Staff::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    $subject = Staff::factory()->create(['company_id' => $company->id]);
    $foreignSubject = Staff::factory()->create(['company_id' => $foreign->id]);
    $otherActor = User::factory()->create();
    $otherActor->givePermissionTo('hr.lifecycle.clearance');
    Staff::factory()->create(['user_id' => $otherActor->id, 'company_id' => $company->id]);

    $makeItem = function (string $companyId, string $staffId) use ($actor): string {
        $exitId = (string) Str::uuid();
        DB::table('hr_exit_cases')->insert([
            'id' => $exitId, 'company_id' => $companyId, 'staff_id' => $staffId, 'exit_type' => 'resignation',
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
    $itemId = $makeItem($company->id, $subject->id);
    $foreignItemId = $makeItem($foreign->id, $foreignSubject->id);
    $url = "/api/hr/lifecycle/clearance/{$itemId}/complete";

    config(['hr.features.employee_self_service' => false]);
    actingAs($actor, 'api')->postJson($url, ['resolution' => 'Handed over files and access checklist.'])->assertConflict();
    config(['hr.features.employee_self_service' => true]);
    $first = actingAs($actor, 'api')->postJson($url, ['resolution' => 'Handed over files and access checklist.'])->assertOk();
    $replay = actingAs($actor, 'api')->postJson($url, ['resolution' => 'Handed over files and access checklist.'])
        ->assertOk()->assertJsonPath('idempotent_replay', true);
    actingAs($actor, 'api')->postJson($url, ['resolution' => 'Changed resolution.'])->assertConflict();
    actingAs($otherActor, 'api')->postJson($url, ['resolution' => 'Handed over files and access checklist.'])->assertConflict();
    actingAs($actor, 'api')->postJson("/api/hr/lifecycle/clearance/{$foreignItemId}/complete", ['resolution' => 'Foreign item'])->assertNotFound();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('hr_exit_clearance_items')->where('id', $itemId)->value('status'))->toBe('completed')
        ->and(DB::table('activity_log')->where('log_name', 'hr-lifecycle')->where('description', 'exit_clearance_completed')->count())->toBe(1);

    actingAs($actor, 'api')->getJson('/api/hr/lifecycle/clearance')->assertOk()
        ->assertJsonFragment(['id' => $itemId, 'title' => 'Complete handover'])
        ->assertJsonMissingPath('data.data.0.resolution')
        ->assertJsonMissingPath('data.data.0.completed_by')
        ->assertJsonMissing(['id' => $foreignItemId]);
});
