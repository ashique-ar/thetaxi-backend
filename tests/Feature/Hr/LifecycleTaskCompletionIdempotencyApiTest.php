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

it('lists only company tasks without evidence and safely replays completion', function () {
    config(['hr.features.employee_self_service' => true]);
    Permission::findOrCreate('hr.lifecycle.manage', 'api');
    Permission::findOrCreate('hr.lifecycle.view', 'api');
    $user = User::factory()->create();
    $user->givePermissionTo(['hr.lifecycle.manage', 'hr.lifecycle.view']);
    $company = Company::create(['name' => 'Lifecycle task company', 'is_active' => true, 'is_default' => true]);
    $foreign = Company::create(['name' => 'Foreign lifecycle task company', 'is_active' => true]);
    Staff::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
    $otherActor = User::factory()->create();
    $otherActor->givePermissionTo(['hr.lifecycle.manage', 'hr.lifecycle.view']);
    Staff::factory()->create(['user_id' => $otherActor->id, 'company_id' => $company->id]);

    $createTask = function (string $companyId) use ($user): string {
        $caseId = (string) Str::uuid();
        DB::table('hr_lifecycle_cases')->insert([
            'id' => $caseId, 'company_id' => $companyId, 'case_type' => 'onboarding', 'status' => 'open',
            'effective_date' => today()->toDateString(), 'case_snapshot' => '{}', 'opened_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $taskId = (string) Str::uuid();
        DB::table('hr_lifecycle_tasks')->insert([
            'id' => $taskId, 'case_id' => $caseId, 'task_code' => 'account', 'title' => 'Set up account',
            'owner_kind' => 'hr', 'dependency_codes' => '[]', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $taskId;
    };
    $taskId = $createTask($company->id);
    $foreignTaskId = $createTask($foreign->id);
    $payload = ['evidence' => [['type' => 'checklist', 'value' => 'complete']], 'idempotency_key' => (string) Str::uuid()];

    actingAs($user, 'api')->postJson("/api/hr/lifecycle/tasks/{$taskId}/complete", [
        'evidence' => [], 'idempotency_key' => (string) Str::uuid(),
    ])->assertUnprocessable();
    $first = actingAs($user, 'api')->postJson("/api/hr/lifecycle/tasks/{$taskId}/complete", $payload)->assertOk();
    $replay = actingAs($user, 'api')->postJson("/api/hr/lifecycle/tasks/{$taskId}/complete", $payload)
        ->assertOk()->assertJsonPath('idempotent_replay', true);
    actingAs($user, 'api')->postJson("/api/hr/lifecycle/tasks/{$taskId}/complete", array_replace($payload, [
        'evidence' => [['type' => 'checklist', 'value' => 'changed']],
    ]))->assertConflict();
    actingAs($otherActor, 'api')->postJson("/api/hr/lifecycle/tasks/{$taskId}/complete", $payload)->assertConflict();
    actingAs($user, 'api')->postJson("/api/hr/lifecycle/tasks/{$foreignTaskId}/complete", $payload)->assertNotFound();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('hr_lifecycle_tasks')->where('id', $taskId)->value('status'))->toBe('completed')
        ->and(DB::table('hr_lifecycle_tasks')->whereNotNull('idempotency_key')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('log_name', 'hr-lifecycle')->where('description', 'lifecycle_task_completed')->count())->toBe(1);

    actingAs($user, 'api')->getJson('/api/hr/lifecycle/cases')->assertOk()
        ->assertJsonFragment(['id' => $taskId, 'title' => 'Set up account'])
        ->assertJsonMissing(['id' => $foreignTaskId])
        ->assertJsonMissingPath('data.data.0.tasks.0.evidence')
        ->assertJsonMissingPath('data.data.0.tasks.0.idempotency_key');
});
