<?php

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns readable reporting-line labels without Staff or company UUIDs', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $manager = Staff::factory()->create(['company_id' => $company->id, 'code' => 'MGR-001']);
    $member = Staff::factory()->create(['company_id' => $company->id, 'code' => 'MEM-001']);
    $lineId = (string) Str::uuid();
    $now = now();

    DB::table('hr_reporting_lines')->insert([
        'id' => $lineId,
        'company_id' => $company->id,
        'manager_staff_id' => $manager->id,
        'member_staff_id' => $member->id,
        'line_type' => 'primary',
        'effective_from' => $now->toDateString(),
        'created_user_id' => $admin->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    actingAs($admin, 'api')->getJson('/api/hr/organization/reporting-lines')->assertOk()
        ->assertJsonPath('data.data.0.id', $lineId)
        ->assertJsonPath('data.data.0.manager_employee_number', 'MGR-001')
        ->assertJsonPath('data.data.0.member_employee_number', 'MEM-001')
        ->assertJsonMissingPath('data.data.0.company_id')
        ->assertJsonMissingPath('data.data.0.manager_staff_id')
        ->assertJsonMissingPath('data.data.0.member_staff_id');
});

it('omits internal identifiers and reasons from create and end responses while retaining audit snapshots', function () {
    [$admin, $company] = hr_seed_admin_actor();
    config(['hr.features.people_core' => true]);
    $manager = Staff::factory()->create(['company_id' => $company->id]);
    $member = Staff::factory()->create(['company_id' => $company->id]);
    $now = now();

    foreach ([$manager, $member] as $staff) {
        DB::table('hr_employment_spells')->insert([
            'id' => (string) Str::uuid(),
            'staff_id' => $staff->id,
            'company_id' => $company->id,
            'spell_number' => 1,
            'joined_at' => '2020-01-01',
            'service_date' => '2020-01-01',
            'gratuity_service_start' => '2020-01-01',
            'status' => 'active',
            'created_user_id' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $created = actingAs($admin, 'api')->postJson('/api/hr/organization/reporting-lines', [
        'manager_staff_id' => $manager->id,
        'member_staff_id' => $member->id,
        'line_type' => 'primary',
        'effective_from' => $now->toDateString(),
        'reason' => 'Private reporting change reason',
        'idempotency_key' => 'reporting-line-create-private',
    ])->assertCreated();
    $line = $created->json('data');

    expect(array_keys($line))->toEqualCanonicalizing(['id', 'line_type', 'status', 'effective_from', 'effective_until', 'version'])
        ->and($line['status'])->toBe('active');

    $ended = actingAs($admin, 'api')->postJson('/api/hr/organization/reporting-lines/'.$line['id'].'/end', [
        'effective_until' => $now->copy()->addDay()->toDateString(),
        'reason' => 'Private reporting end reason',
        'expected_version' => 1,
        'idempotency_key' => 'reporting-line-end-private',
    ])->assertOk();
    $endedLine = $ended->json('data');

    expect(array_keys($endedLine))->toEqualCanonicalizing(['id', 'line_type', 'status', 'effective_from', 'effective_until', 'version'])
        ->and($endedLine['status'])->toBe('ended')
        ->and($endedLine['version'])->toBe(2);
    $createdEvent = DB::table('hr_reporting_line_events')->where('reporting_line_id', $line['id'])->where('event_type', 'created')->first();
    expect($createdEvent)->not->toBeNull()
        ->and(json_decode($createdEvent->after_snapshot, true))->toMatchArray([
        'manager_staff_id' => $manager->id,
        'member_staff_id' => $member->id,
        'reason' => 'Private reporting change reason',
    ]);
    $this->assertDatabaseHas('hr_reporting_line_events', [
        'reporting_line_id' => $line['id'],
        'event_type' => 'created',
    ]);
    $this->assertDatabaseHas('hr_reporting_line_events', [
        'reporting_line_id' => $line['id'],
        'event_type' => 'ended',
        'reason' => 'Private reporting end reason',
    ]);
});
