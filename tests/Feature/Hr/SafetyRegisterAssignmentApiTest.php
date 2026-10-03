<?php

use App\Models\Company;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('retains one hazard and audit on retry and rejects a changed owner', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    safety_seed_location($admin, $company, Staff::query()->where('user_id', $admin->id)->firstOrFail(), 'WORKSHOP');
    $owner = Staff::factory()->create(['company_id' => $company->id]);
    $payload = ['idempotency_key' => (string) Str::uuid(), 'location_code' => 'WORKSHOP',
        'category' => 'trip', 'title' => 'Obstructed walkway', 'description' => 'Equipment blocks access.',
        'likelihood' => 'possible', 'impact' => 'moderate', 'risk_rating' => 'medium',
        'controls' => ['Clear walkway'], 'owner_staff_id' => $owner->id];
    actingAs($admin, 'api')->postJson('/api/hr/safety/hazards', $payload)->assertCreated();
    actingAs($admin, 'api')->postJson('/api/hr/safety/hazards', $payload)->assertOk();
    actingAs($admin, 'api')->postJson('/api/hr/safety/hazards', array_replace($payload, ['owner_staff_id' => null]))->assertStatus(409);
    expect(DB::table('hr_hazards')->where('id', $payload['idempotency_key'])->count())->toBe(1)
        ->and(DB::table('hr_safety_register_events')->where('register_id', $payload['idempotency_key'])->count())->toBe(1);
});

it('rejects foreign and ended hazard owners without a partial record', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    safety_seed_location($admin, $company, Staff::query()->where('user_id', $admin->id)->firstOrFail(), 'WORKSHOP');
    $other = Company::create(['name' => 'Other safety tenant']);
    $foreign = Staff::factory()->create(['company_id' => $other->id]);
    $ended = Staff::factory()->former()->create(['company_id' => $company->id]);
    foreach ([$foreign, $ended] as $owner) {
        $key = (string) Str::uuid();
        actingAs($admin, 'api')->postJson('/api/hr/safety/hazards', [
            'idempotency_key' => $key, 'location_code' => 'WORKSHOP', 'category' => 'trip',
            'title' => 'Walkway', 'description' => 'Obstruction', 'likelihood' => 'possible',
            'impact' => 'moderate', 'risk_rating' => 'medium', 'controls' => ['Clear'],
            'owner_staff_id' => $owner->id,
        ])->assertUnprocessable();
        expect(DB::table('hr_hazards')->where('id', $key)->exists())->toBeFalse()
            ->and(DB::table('hr_safety_register_events')->where('register_id', $key)->exists())->toBeFalse();
    }
});

it('revalidates an inspection lead login and permission after selection and audits successful retries once', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    safety_seed_location($admin, $company, Staff::query()->where('user_id', $admin->id)->firstOrFail(), 'WORKSHOP');
    $lead = Staff::factory()->create(['company_id' => $company->id]);
    $lead->user->givePermissionTo('hr.safety.investigate');
    $lead->user->update(['is_active' => true]);
    actingAs($admin, 'api')->getJson('/api/hr/safety/handler-candidates?record_type=investigator&selected_id='.$lead->id)
        ->assertOk()->assertJsonPath('data.data.0.value', $lead->id);
    $payload = ['idempotency_key' => (string) Str::uuid(), 'location_code' => 'WORKSHOP',
        'inspection_type' => 'Routine', 'scheduled_for' => now()->addDay()->toDateString(),
        'checklist_snapshot' => ['Check walkway'], 'lead_staff_id' => $lead->id];
    $lead->user->update(['is_active' => false]);
    actingAs($admin, 'api')->postJson('/api/hr/safety/inspections', $payload)->assertUnprocessable();
    $lead->user->update(['is_active' => true]);
    $lead->user->revokePermissionTo('hr.safety.investigate');
    actingAs($admin, 'api')->postJson('/api/hr/safety/inspections', $payload)->assertUnprocessable();
    expect(DB::table('hr_safety_inspections')->where('id', $payload['idempotency_key'])->exists())->toBeFalse();
    $lead->user->givePermissionTo('hr.safety.investigate');
    actingAs($admin, 'api')->postJson('/api/hr/safety/inspections', $payload)->assertCreated();
    actingAs($admin, 'api')->postJson('/api/hr/safety/inspections', $payload)->assertOk();
    actingAs($admin, 'api')->postJson('/api/hr/safety/inspections', array_replace($payload, ['inspection_type' => 'Changed']))->assertStatus(409);
    expect(DB::table('hr_safety_register_events')->where('register_id', $payload['idempotency_key'])->count())->toBe(1);
});

it('returns bounded exact-hydrated Safety locations only from the actor legal entity', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    $staff = Staff::query()->where('user_id', $admin->id)->firstOrFail();
    safety_seed_location($admin, $company, $staff, 'WORKSHOP');
    $other = Company::create(['name' => 'Other safety tenant']);
    safety_seed_location($admin, $other, Staff::factory()->create(['company_id' => $other->id]), 'SECRET SITE');

    actingAs($admin, 'api')->getJson('/api/hr/safety/location-options?search=WORK')->assertOk()
        ->assertJsonPath('data.data.0.value', 'WORKSHOP');
    actingAs($admin, 'api')->getJson('/api/hr/safety/location-options?selected_id=WORKSHOP')->assertOk()
        ->assertJsonPath('data.data.0.label', 'WORKSHOP');
    actingAs($admin, 'api')->getJson('/api/hr/safety/location-options?selected_id=SECRET%20SITE')->assertOk()
        ->assertJsonPath('data.total', 0);
});

it('rejects an unconfigured location before creating a Safety record or audit event', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.relations_safety' => true]);
    $key = (string) Str::uuid();

    actingAs($admin, 'api')->postJson('/api/hr/safety/hazards', [
        'idempotency_key' => $key, 'location_code' => 'UNCONFIGURED', 'category' => 'trip',
        'title' => 'Walkway', 'description' => 'Obstruction', 'likelihood' => 'possible',
        'impact' => 'moderate', 'risk_rating' => 'medium', 'controls' => ['Clear'],
    ])->assertUnprocessable();
    expect(DB::table('hr_hazards')->where('id', $key)->exists())->toBeFalse()
        ->and(DB::table('hr_safety_register_events')->where('register_id', $key)->exists())->toBeFalse();
});

function safety_seed_location($user, Company $company, Staff $staff, string $code): void
{
    $today = today()->toDateString();
    $spell = (string) Str::uuid();
    DB::table('hr_employment_spells')->insert([
        'id' => $spell, 'staff_id' => $staff->id, 'company_id' => $company->id, 'spell_number' => 1,
        'joined_at' => $today, 'service_date' => $today, 'gratuity_service_start' => $today,
        'status' => 'active', 'created_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_employment_assignments')->insert([
        'id' => (string) Str::uuid(), 'employment_spell_id' => $spell, 'staff_id' => $staff->id,
        'company_id' => $company->id, 'location_code' => $code, 'effective_from' => $today,
        'change_reason' => 'test_setup', 'snapshot' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
