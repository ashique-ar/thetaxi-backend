<?php

use App\Models\Hr\PayrollInputFact;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('audits payroll fact type and status without private source or Staff details', function () {
    $staff = Staff::factory()->create();
    $actor = User::factory()->create();
    $sourceId = (string) Str::uuid();
    $privateMarker = 'private-payroll-source-snapshot';

    $fact = PayrollInputFact::create([
        'staff_id' => $staff->id,
        'fact_kind' => 'approved_leave_minutes',
        'effective_date' => '2026-01-15',
        'quantity_minutes' => 480,
        'rate_category' => 'private-rate-category',
        'source_type' => 'leave_request',
        'source_id' => $sourceId,
        'status' => 'staged',
        'source_snapshot' => ['private' => $privateMarker],
        'fact_checksum' => hash('sha256', $privateMarker),
        'created_by' => $actor->id,
    ]);

    $properties = DB::table('activity_log')
        ->where('subject_type', PayrollInputFact::class)
        ->where('subject_id', $fact->id)
        ->value('attribute_changes');

    expect($properties)->not->toBeNull()
        ->and($properties)->toContain('approved_leave_minutes')
        ->and($properties)->toContain('staged')
        ->and($properties)->not->toContain((string) $staff->id)
        ->and($properties)->not->toContain((string) $actor->id)
        ->and($properties)->not->toContain($sourceId)
        ->and($properties)->not->toContain($privateMarker)
        ->and($properties)->not->toContain('private-rate-category')
        ->and($properties)->not->toContain(hash('sha256', $privateMarker))
        ->and($properties)->not->toContain('480');
});

it('stages payroll facts for active Staff without a company owner field', function () {
    $staff = Staff::factory()->create();
    $actor = User::factory()->create();

    $fact = PayrollInputFact::create([
        'staff_id' => $staff->id,
        'fact_kind' => 'approved_leave_minutes',
        'effective_date' => '2026-01-15',
        'quantity_minutes' => 480,
        'source_type' => 'leave_request',
        'source_id' => (string) Str::uuid(),
        'status' => 'staged',
        'source_snapshot' => [],
        'fact_checksum' => str_repeat('a', 64),
        'created_by' => $actor->id,
    ]);

    expect(DB::table('hr_payroll_input_facts')->where('id', $fact->id)->exists())->toBeTrue()
        ->and($fact->toArray())->not->toHaveKey('company_id');
});

it('keeps payroll facts attached to their Staff record without a tenant relationship', function () {
    $staff = Staff::factory()->create();
    $actor = User::factory()->create();
    $sourceId = (string) Str::uuid();

    $fact = PayrollInputFact::create([
        'staff_id' => $staff->id,
        'fact_kind' => 'approved_leave_minutes',
        'effective_date' => '2026-01-15',
        'quantity_minutes' => 480,
        'source_type' => 'leave_request',
        'source_id' => $sourceId,
        'status' => 'staged',
        'source_snapshot' => [],
        'fact_checksum' => str_repeat('b', 64),
        'created_by' => $actor->id,
    ]);

    expect($fact->staff_id)->toBe($staff->id)
        ->and($fact->toArray())->not->toHaveKey('company_id')
        ->and(DB::table('hr_payroll_input_facts')->where('source_id', $sourceId)->exists())->toBeTrue();
});
