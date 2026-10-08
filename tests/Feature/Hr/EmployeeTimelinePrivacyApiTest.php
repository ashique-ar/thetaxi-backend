<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('hides source identifiers, private summaries and idempotency keys from timeline API rows', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $staff = \App\Models\Staff::factory()->create(['company_id' => $company->id]);
    $spellId = (string) Str::uuid();
    $eventId = (string) Str::uuid();
    $sourceId = (string) Str::uuid();
    $privateSummary = 'Private timeline summary marker';
    $idempotencyKey = 'private-timeline-api-key';
    $now = now();

    DB::table('hr_employment_spells')->insert([
        'id' => $spellId, 'staff_id' => $staff->id, 'company_id' => $company->id, 'spell_number' => 1,
        'joined_at' => '2026-01-01', 'service_date' => '2026-01-01', 'gratuity_service_start' => '2026-01-01',
        'status' => 'active', 'created_user_id' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('hr_employee_timeline_events')->insert([
        'id' => $eventId, 'staff_id' => $staff->id, 'employment_spell_id' => $spellId,
        'domain' => 'people', 'event_type' => 'profile_versioned', 'source_type' => 'profile_version',
        'source_id' => $sourceId, 'title' => 'Employee profile updated',
        'safe_summary' => json_encode(['private' => $privateSummary], JSON_THROW_ON_ERROR),
        'confidentiality' => 'hr_private', 'effective_at' => $now, 'recorded_at' => $now,
        'idempotency_key' => $idempotencyKey, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $response = actingAs($admin, 'api')->getJson('/api/hr/employees/'.$staff->id.'/timeline')->assertOk();

    expect($response->json('data.data.0.id'))->toBe($eventId)
        ->and($response->json('data.data.0.event_type'))->toBe('profile_versioned')
        ->and($response->json('data.data.0'))->not->toHaveKey('source_id')
        ->not->toHaveKey('safe_summary')
        ->not->toHaveKey('idempotency_key')
        ->and($response->getContent())->not->toContain($sourceId, $privateSummary, $idempotencyKey);
});
