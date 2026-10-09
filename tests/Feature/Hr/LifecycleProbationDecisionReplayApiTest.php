<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('replays the same probation extension only for its original actor and company', function () {
    config(['hr.features.employee_self_service' => true]);
    Permission::findOrCreate('hr.lifecycle.approve', 'api');
    $actor = User::factory()->create();
    $actor->givePermissionTo('hr.lifecycle.approve');
    $otherActor = User::factory()->create();
    $otherActor->givePermissionTo('hr.lifecycle.approve');
    $company = Company::create(['name' => 'Probation decision company', 'is_active' => true, 'is_default' => true]);
    $foreign = Company::create(['name' => 'Foreign probation decision company', 'is_active' => true]);
    $actorStaff = Staff::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id, 'is_active' => true, 'created_user_id' => $actor->id]);
    $otherActorStaff = Staff::factory()->create(['user_id' => $otherActor->id, 'company_id' => $company->id]);
    UserContext::create(['user_id' => $otherActor->id, 'context_type' => 'staff', 'context_id' => $otherActorStaff->id, 'is_active' => true, 'created_user_id' => $otherActor->id]);
    $subject = Staff::factory()->create(['company_id' => $company->id]);
    $foreignSubject = Staff::factory()->create(['company_id' => $foreign->id]);

    $makeProbation = function (Staff $staff, string $companyId, User $createdBy) use ($actor): string {
        $spellId = (string) Str::uuid();
        DB::table('hr_employment_spells')->insert([
            'id' => $spellId, 'staff_id' => $staff->id, 'company_id' => $companyId, 'spell_number' => 1,
            'joined_at' => today()->toDateString(), 'service_date' => today()->toDateString(),
            'gratuity_service_start' => today()->toDateString(), 'status' => 'active', 'created_user_id' => $createdBy->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = (string) Str::uuid();
        DB::table('hr_probation_cases')->insert([
            'id' => $id, 'staff_id' => $staff->id, 'employment_spell_id' => $spellId,
            'starts_at' => today()->toDateString(), 'review_due_at' => today()->addDays(30)->toDateString(),
            'current_end_at' => today()->addDays(90)->toDateString(), 'status' => 'active', 'objectives' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    };
    $probationId = $makeProbation($subject, $company->id, $actor);
    $foreignProbationId = $makeProbation($foreignSubject, $foreign->id, $actor);
    $payload = ['outcome' => 'extended', 'new_end_at' => today()->addDays(120)->toDateString(), 'reason' => 'Documented review needs additional time.'];
    $url = "/api/hr/lifecycle/probation/{$probationId}/decide";

    $first = actingAs($actor, 'api')->postJson($url, $payload)->assertOk()->assertJsonPath('idempotent_replay', false);
    $replay = actingAs($actor, 'api')->postJson($url, $payload)->assertOk()->assertJsonPath('idempotent_replay', true);
    actingAs($actor, 'api')->postJson($url, array_replace($payload, ['reason' => 'Changed reason.']))->assertConflict();
    actingAs($otherActor, 'api')->postJson($url, $payload)->assertConflict();
    actingAs($actor, 'api')->postJson("/api/hr/lifecycle/probation/{$foreignProbationId}/decide", $payload)->assertNotFound();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('hr_probation_cases')->where('id', $probationId)->value('current_end_at'))->toBe($payload['new_end_at'])
        ->and(DB::table('activity_log')->where('log_name', 'hr-lifecycle')->where('description', 'probation_decided')->count())->toBe(1);

    actingAs($actor, 'api')->getJson('/api/hr/lifecycle/probation')->assertOk()
        ->assertJsonFragment(['id' => $probationId, 'staff_code' => $subject->code])
        ->assertJsonMissing(['id' => $foreignProbationId])
        ->assertJsonMissingPath('data.data.0.objectives')
        ->assertJsonMissingPath('data.data.0.decision_reason')
        ->assertJsonMissingPath('data.data.0.decided_by');
});

it('replays a final probation decision without writing the confirmation twice', function () {
    config(['hr.features.employee_self_service' => true]);
    Permission::findOrCreate('hr.lifecycle.approve', 'api');
    $actor = User::factory()->create();
    $actor->givePermissionTo('hr.lifecycle.approve');
    $company = Company::create(['name' => 'Probation confirmation company', 'is_active' => true, 'is_default' => true]);
    $actorStaff = Staff::factory()->create(['user_id' => $actor->id, 'company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->id, 'context_type' => 'staff', 'context_id' => $actorStaff->id, 'is_active' => true, 'created_user_id' => $actor->id]);
    $subject = Staff::factory()->create(['company_id' => $company->id]);
    $spellId = (string) Str::uuid();
    DB::table('hr_employment_spells')->insert([
        'id' => $spellId, 'staff_id' => $subject->id, 'company_id' => $company->id, 'spell_number' => 1,
        'joined_at' => today()->toDateString(), 'service_date' => today()->toDateString(),
        'gratuity_service_start' => today()->toDateString(), 'status' => 'active', 'created_user_id' => $actor->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $probationId = (string) Str::uuid();
    DB::table('hr_probation_cases')->insert([
        'id' => $probationId, 'staff_id' => $subject->id, 'employment_spell_id' => $spellId,
        'starts_at' => today()->toDateString(), 'review_due_at' => today()->addDays(30)->toDateString(),
        'current_end_at' => today()->addDays(90)->toDateString(), 'status' => 'active', 'objectives' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $url = "/api/hr/lifecycle/probation/{$probationId}/decide";
    $payload = ['outcome' => 'confirmed', 'reason' => 'Review objectives met.'];

    actingAs($actor, 'api')->postJson($url, $payload)->assertOk()->assertJsonPath('idempotent_replay', false);
    $confirmationDate = DB::table('hr_employment_spells')->where('id', $spellId)->value('confirmation_date');
    actingAs($actor, 'api')->postJson($url, $payload)->assertOk()->assertJsonPath('idempotent_replay', true);

    expect(DB::table('hr_employment_spells')->where('id', $spellId)->value('confirmation_date'))->toBe($confirmationDate)
        ->and(DB::table('activity_log')->where('log_name', 'hr-lifecycle')->where('description', 'probation_decided')->count())->toBe(1);
});
