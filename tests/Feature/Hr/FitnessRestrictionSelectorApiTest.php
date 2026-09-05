<?php

use App\Models\Company;
use App\Models\Staff;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('gates fitness employee search independently from Safety management and scopes exact hydration', function () {
    [$admin, $company] = hr_seed_admin_actor();
    $actor = Staff::factory()->create(['company_id' => $company->id]);
    UserContext::create(['user_id' => $actor->user_id, 'context_type' => 'staff',
        'context_id' => $actor->id, 'is_active' => true, 'created_user_id' => $admin->id]);
    $actor->user->givePermissionTo('hr.safety.manage');
    $url = '/api/hr/safety/fitness-employee-options?company_id='.$company->id;
    actingAs($actor->user, 'api')->getJson($url)->assertForbidden();
    $actor->user->givePermissionTo('hr.safety.fitness-restricted');
    $actor->user->revokePermissionTo('hr.safety.manage');
    $response = actingAs($actor->user, 'api')->getJson($url.'&selected_id='.$actor->id)->assertOk()
        ->assertJsonPath('data.data.0.value', $actor->id);
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    $other = Company::create(['name' => 'Other fitness tenant']);
    $foreign = Staff::factory()->create(['company_id' => $other->id]);
    actingAs($actor->user, 'api')->getJson($url.'&selected_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data.data');
    actingAs($actor->user, 'api')->getJson('/api/hr/safety/fitness-employee-options?company_id='.$other->id)->assertForbidden();
    actingAs($actor->user, 'api')->getJson($url.'&per_page=51')->assertUnprocessable();
});

it('retains encrypted notes and one audited restriction on retry and rejects changed notes or overlapping periods', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $payload = ['idempotency_key' => (string) Str::uuid(), 'staff_id' => $staff->id,
        'fitness_status' => 'fit_with_restrictions', 'work_restrictions' => ['No lifting'],
        'private_notes' => 'Confidential review note', 'effective_from' => '2026-09-05',
        'effective_until' => null, 'verification_reference' => 'Reviewed certificate'];
    $created = actingAs($admin, 'api')->postJson('/api/hr/safety/fitness-restrictions', $payload)->assertCreated();
    expect($created->json('data'))->not->toHaveKeys(['private_notes', 'encrypted_private_notes']);
    $row = DB::table('hr_fitness_restrictions')->find($payload['idempotency_key']);
    expect($row->encrypted_private_notes)->not->toBe($payload['private_notes']);
    expect(decrypt($row->encrypted_private_notes))->toBe($payload['private_notes']);
    $replay = actingAs($admin, 'api')->postJson('/api/hr/safety/fitness-restrictions', $payload)->assertOk();
    expect($replay->json('data'))->not->toHaveKeys(['private_notes', 'encrypted_private_notes']);
    actingAs($admin, 'api')->postJson('/api/hr/safety/fitness-restrictions', array_replace($payload, ['private_notes' => 'Changed']))->assertStatus(409);
    actingAs($admin, 'api')->postJson('/api/hr/safety/fitness-restrictions', array_replace($payload, ['idempotency_key' => (string) Str::uuid()]))->assertUnprocessable();
    expect(DB::table('hr_fitness_restrictions')->where('staff_id', $staff->id)->count())->toBe(1)
        ->and(DB::table('hr_safety_register_events')->where('register_id', $row->id)->count())->toBe(1);
});

it('rejects ended employees without writing a restriction or audit', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    $staff = Staff::factory()->former()->create(['company_id' => $company->id]);
    $key = (string) Str::uuid();
    actingAs($admin, 'api')->postJson('/api/hr/safety/fitness-restrictions', [
        'idempotency_key' => $key, 'staff_id' => $staff->id, 'fitness_status' => 'review_required',
        'work_restrictions' => ['Review before duties'], 'effective_from' => '2026-09-05',
        'verification_reference' => 'Review',
    ])->assertUnprocessable();
    expect(DB::table('hr_fitness_restrictions')->where('id', $key)->exists())->toBeFalse()
        ->and(DB::table('hr_safety_register_events')->where('register_id', $key)->exists())->toBeFalse();
});
