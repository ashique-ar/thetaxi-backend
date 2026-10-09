<?php

use App\Models\Company;
use App\Models\Hr\PayrollInputFact;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('audits payroll fact type and status without private source or Staff details', function () {
    $company = Company::create(['name' => 'Payroll fact audit company', 'is_active' => true, 'is_default' => true]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $actor = User::factory()->create();
    $sourceId = (string) Str::uuid();
    $privateMarker = 'private-payroll-source-snapshot';

    $fact = PayrollInputFact::create([
        'company_id' => $company->id,
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
        ->and($properties)->not->toContain((string) $company->id)
        ->and($properties)->not->toContain((string) $staff->id)
        ->and($properties)->not->toContain((string) $actor->id)
        ->and($properties)->not->toContain($sourceId)
        ->and($properties)->not->toContain($privateMarker)
        ->and($properties)->not->toContain('private-rate-category')
        ->and($properties)->not->toContain(hash('sha256', $privateMarker))
        ->and($properties)->not->toContain('480');
});

it('refuses to stage a payroll fact for an inactive legal entity', function () {
    $company = Company::create(['name' => 'Inactive payroll fact company', 'is_active' => false, 'is_default' => false]);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $actor = User::factory()->create();

    expect(fn () => PayrollInputFact::create([
        'company_id' => $company->id,
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
    ]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Payroll input facts require an active legal entity.');

    expect(DB::table('hr_payroll_input_facts')->where('company_id', $company->id)->exists())->toBeFalse();
});

it('refuses to stage a payroll fact for Staff owned by another legal entity', function () {
    $company = Company::create(['name' => 'Payroll fact owner company', 'is_active' => true, 'is_default' => true]);
    $otherCompany = Company::create(['name' => 'Other payroll fact company', 'is_active' => true, 'is_default' => false]);
    $staff = Staff::factory()->create(['company_id' => $otherCompany->id]);
    $actor = User::factory()->create();
    $sourceId = (string) Str::uuid();

    expect(fn () => PayrollInputFact::create([
        'company_id' => $company->id,
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
    ]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Payroll fact Staff does not belong to its legal entity.');

    $this->assertDatabaseMissing('hr_payroll_input_facts', ['source_id' => $sourceId]);
});
