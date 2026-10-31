<?php

use App\Models\Staff;
use App\Models\User;
use App\Models\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('searches and hydrates active installation-wide employees with minimal readable output', function () {
    [$admin] = hr_seed_admin_actor();
    $employee = Staff::factory()->create(['code' => 'PPE-CHOICE']);
    $employee->user->update(['first_name' => 'PPE', 'last_name' => 'Employee', 'is_active' => false]);
    $other = Staff::factory()->create(['code' => 'PPE-OTHER']);
    $former = Staff::factory()->former()->create(['code' => 'PPE-FORMER']);
    $deleted = Staff::factory()->create(['code' => 'PPE-DELETED']);
    $deleted->delete();
    $url = '/api/hr/safety/ppe-employee-options';

    $response = actingAs($admin, 'api')->getJson($url.'?search=PPE-')->assertOk();
    $response->assertJsonCount(2, 'data.data')->assertJsonPath('data.data.0.value', $employee->id)
        ->assertJsonPath('data.data.0.label', 'PPE Employee · PPE-CHOICE');
    expect(array_keys($response->json('data.data.0')))->toBe(['value', 'label', 'metadata', 'status']);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$employee->id.'&search=not-a-match')
        ->assertOk()->assertJsonPath('data.data.0.value', $employee->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$other->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $other->id);
    foreach ([$former, $deleted] as $excluded) {
        actingAs($admin, 'api')->getJson($url.'?selected_id='.$excluded->id)
            ->assertOk()->assertJsonCount(0, 'data.data');
    }
});

it('bounds employee search pages and supports an exact selection outside the first page', function () {
    [$admin] = hr_seed_admin_actor();
    $first = Staff::factory()->create(['code' => 'PAGE-PPE-01']);
    $second = Staff::factory()->create(['code' => 'PAGE-PPE-02']);
    $url = '/api/hr/safety/ppe-employee-options';
    actingAs($admin, 'api')->getJson($url.'?search=PAGE-PPE&per_page=1&page=1')
        ->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.data.0.value', $first->id);
    actingAs($admin, 'api')->getJson($url.'?search=PAGE-PPE&per_page=1&page=2')
        ->assertOk()->assertJsonPath('data.data.0.value', $second->id);
    actingAs($admin, 'api')->getJson($url.'?selected_id='.$second->id.'&per_page=1')
        ->assertOk()->assertJsonPath('data.data.0.value', $second->id);
    actingAs($admin, 'api')->getJson($url.'?per_page=51')->assertUnprocessable();
    actingAs($admin, 'api')->getJson($url.'?page=0')->assertUnprocessable();
});

it('rejects a newly terminated selection without writing issuance or audit', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    $employee = Staff::factory()->create();
    actingAs($admin, 'api')->getJson('/api/hr/safety/ppe-employee-options?selected_id='.$employee->id)
        ->assertOk()->assertJsonCount(1, 'data.data');
    DB::table('staff')->where('id', $employee->id)->update(['employment_ended_at' => now()]);
    $key = (string) Str::uuid();
    actingAs($admin, 'api')->postJson('/api/hr/safety/ppe-issuances', [
        'idempotency_key' => $key, 'staff_id' => $employee->id, 'ppe_type' => 'Safety vest',
        'issued_at' => '2026-09-05',
    ])->assertUnprocessable();
    expect(DB::table('hr_ppe_issuances')->where('id', $key)->exists())->toBeFalse()
        ->and(DB::table('hr_safety_register_events')->where('register_id', $key)->exists())->toBeFalse();
});

it('does not grant employee lookup to an internal employee without Safety management permission', function () {
    [$admin] = hr_seed_admin_actor();
    $staff = Staff::factory()->create();
    UserContext::create([
        'user_id' => $staff->user_id, 'context_type' => 'staff', 'context_id' => $staff->id,
        'is_active' => true, 'created_user_id' => $admin->id,
    ]);
    actingAs($staff->user, 'api')->getJson('/api/hr/safety/ppe-employee-options')->assertForbidden();
});

it('shows Safety employee selectors across locations under the selected Staff context', function () {
    [$admin] = hr_seed_admin_actor();
    $firstEmployee = Staff::factory()->create();
    $secondActor = User::factory()->create();
    $secondActor->assignRole('admin');
    $secondActorStaff = Staff::factory()->create(['user_id' => $secondActor->id]);
    $secondEmployee = Staff::factory()->create();
    $context = UserContext::create([
        'user_id' => $secondActor->id, 'context_type' => 'staff', 'context_id' => $secondActorStaff->id,
        'is_active' => true, 'created_user_id' => $secondActor->id,
    ]);

    actingAs($secondActor, 'api')->withHeaders([
        'X-Active-Context-Type' => 'staff', 'X-Active-Context-Id' => $context->id,
    ])->getJson('/api/hr/safety/ppe-employee-options')
        ->assertOk()->assertJsonCount(4, 'data.data')
        ->assertJsonFragment(['value' => $firstEmployee->id])
        ->assertJsonFragment(['value' => $secondEmployee->id]);
});

it('issues PPE to an eligible employee without login access and retains one audited result on retry', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    $employee = Staff::factory()->create();
    $employee->user->update(['is_active' => false]);
    $payload = [
        'idempotency_key' => (string) Str::uuid(), 'staff_id' => $employee->id,
        'ppe_type' => 'Safety vest', 'issued_at' => '2026-09-05',
    ];
    actingAs($admin, 'api')->postJson('/api/hr/safety/ppe-issuances', $payload)->assertCreated()
        ->assertJsonPath('data.staff_id', $employee->id);
    actingAs($admin, 'api')->postJson('/api/hr/safety/ppe-issuances', $payload)->assertOk();
    actingAs($admin, 'api')->postJson('/api/hr/safety/ppe-issuances', array_replace($payload, ['ppe_type' => 'Gloves']))
        ->assertStatus(409);
    expect(DB::table('hr_ppe_issuances')->where('id', $payload['idempotency_key'])->count())->toBe(1)
        ->and(DB::table('hr_safety_register_events')->where('register_id', $payload['idempotency_key'])->count())->toBe(1);
});

it('returns readable historical labels only for Staff referenced by visible register rows', function () {
    [$admin] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    $employee = Staff::factory()->create(['code' => 'PPE-HISTORY']);
    $employee->user->update(['first_name' => 'Historical PPE', 'last_name' => 'Employee']);
    $unrelated = Staff::factory()->create(['code' => 'NOT-IN-REGISTER']);
    $otherUnrelated = Staff::factory()->create(['code' => 'OTHER-NOT-IN-REGISTER']);
    $key = (string) Str::uuid();

    actingAs($admin, 'api')->postJson('/api/hr/safety/ppe-issuances', [
        'idempotency_key' => $key, 'staff_id' => $employee->id,
        'ppe_type' => 'Safety vest', 'issued_at' => '2026-09-05',
    ])->assertCreated();
    DB::table('staff')->where('id', $employee->id)->update(['employment_ended_at' => now()]);

    actingAs($admin, 'api')->getJson('/api/hr/safety/registers')->assertOk()
        ->assertJsonPath('data.staff_labels.'.$employee->id.'.label', 'Historical PPE Employee · PPE-HISTORY')
        ->assertJsonPath('data.staff_labels.'.$employee->id.'.status', 'ended')
        ->assertJsonMissingPath('data.staff_labels.'.$unrelated->id)
        ->assertJsonMissingPath('data.staff_labels.'.$otherUnrelated->id);
});
