<?php

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Company;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use App\Services\Sales\CommissionStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('returns readable statement preview facts without source identifiers or snapshots', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Statement Preview Company']);
    $admin->givePermissionTo(['sales.commission-statements.generate', 'sales.commission-statements.view-all']);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $profile = SalesProfile::query()->create([
        'id' => (string) Str::uuid(), 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'PREVIEW-PRIVACY', 'status' => 'active', 'effective_from' => now()->subDay(),
        'staff_category_snapshot' => 'sales', 'reporting_currency' => 'LKR', 'commission_eligible' => true,
    ]);
    $sourceId = (string) Str::uuid();
    $statementService = Mockery::mock(CommissionStatementService::class);
    $statementService->shouldReceive('preview')->twice()->andReturn([
        'period_start' => CarbonImmutable::parse('2026-01-01'),
        'period_end' => CarbonImmutable::parse('2026-01-31'),
        'cutoff_at' => CarbonImmutable::parse('2026-02-01T00:00:00Z'),
        'finalization_at' => CarbonImmutable::parse('2026-02-03T00:00:00Z'),
        'approval_deadline_at' => CarbonImmutable::parse('2026-02-05T00:00:00Z'),
        'settlement_at' => CarbonImmutable::parse('2026-02-10T00:00:00Z'),
        'opening_carry_forward_lkr' => 0,
        'gross_earnings_lkr' => 1250,
        'adjustment_credits_lkr' => 0,
        'recovery_deductions_lkr' => 0,
        'other_deductions_lkr' => 0,
        'net_payable_lkr' => 1250,
        'closing_carry_forward_lkr' => 0,
        'cycle_schedule_snapshot' => ['cycle_version_id' => (string) Str::uuid()],
        'cycle_schedule_checksum' => str_repeat('a', 64),
        'lines' => [[
            'line_type' => 'earning', 'source_type' => 'commission_decision', 'source_id' => $sourceId,
            'description' => 'Collection commission earning', 'gross_lkr' => 1250, 'deduction_lkr' => 0,
            'net_lkr' => 1250, 'line_status' => 'included', 'hold_code' => null,
            'snapshot' => ['staff_id' => (string) $staff->id, 'company_id' => (string) $company->id],
        ]],
        'write_performed' => false,
    ]);
    app()->instance(CommissionStatementService::class, $statementService);

    $foreignCompany = Company::create(['name' => 'Different Statement Entity']);
    actingAs($admin, 'api')->postJson('/api/sales/commission-statements/preview', [
        'sales_profile_id' => $profile->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'cutoff_at' => '2026-02-01T00:00:00Z',
    ])->assertOk();
    actingAs($admin, 'api')->postJson('/api/sales/commission-statements/preview', [
        'company_id' => $foreignCompany->id, 'sales_profile_id' => $profile->id,
        'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'cutoff_at' => '2026-02-01T00:00:00Z',
    ])->assertUnprocessable();

    $response = actingAs($admin, 'api')->postJson('/api/sales/commission-statements/preview', [
        'company_id' => $company->id,
        'sales_profile_id' => $profile->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'cutoff_at' => '2026-02-01T00:00:00Z',
    ])->assertOk()
        ->assertJsonPath('data.write_performed', false)
        ->assertJsonPath('data.lines.0.description', 'Collection commission earning')
        ->assertJsonMissingPath('data.lines.0.source_id')
        ->assertJsonMissingPath('data.lines.0.snapshot')
        ->assertJsonMissingPath('data.cycle_schedule_snapshot')
        ->assertJsonMissingPath('data.cycle_schedule_checksum');

    expect($response->getContent())->not->toContain($sourceId)
        ->and($response->getContent())->not->toContain((string) $staff->id)
        ->and($response->getContent())->not->toContain((string) $company->id);
});

it('returns minimized commission and accounting views without source or owner identifiers', function () {
    [$admin, $company] = hr_seed_admin_actor(['name' => 'Statement Detail Company']);
    $admin->givePermissionTo('sales.commission-statements.view-all');
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $profileId = (string) Str::uuid();
    $cycleId = (string) Str::uuid();
    $assignmentId = (string) Str::uuid();
    $statementId = (string) Str::uuid();
    $lineId = (string) Str::uuid();
    $sourceId = (string) Str::uuid();
    $now = now();

    DB::table('sales_profiles')->insert([
        'id' => $profileId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'DETAIL-PRIVACY', 'effective_from' => $now->copy()->subYear(),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_cycle_versions')->insert([
        'id' => $cycleId, 'company_id' => $company->id, 'code' => 'DETAIL-CYCLE', 'version' => 1,
        'timezone' => 'Asia/Colombo', 'earning_period_rule' => 'monthly', 'cutoff_day' => 25,
        'finalization_day' => 26, 'approval_deadline_day' => 27, 'settlement_day' => 28,
        'holiday_rule' => 'next_business_day', 'effective_from' => $now->copy()->subYear(),
        'status' => 'approved', 'created_by' => $admin->id, 'approved_by' => $admin->id,
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_cycle_assignments')->insert([
        'id' => $assignmentId, 'company_id' => $company->id, 'cycle_version_id' => $cycleId,
        'scope_type' => 'company', 'precedence' => 1, 'effective_from' => $now->copy()->subYear(),
        'status' => 'approved', 'created_by' => $admin->id, 'approved_by' => $admin->id,
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_statements')->insert([
        'id' => $statementId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_profile_id' => $profileId, 'cycle_version_id' => $cycleId, 'cycle_assignment_id' => $assignmentId,
        'statement_number' => 'SCS-DETAIL-PRIVACY', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'cutoff_at' => $now, 'timezone' => 'Asia/Colombo', 'status' => 'approved', 'prepared_by' => $admin->id,
        'prepared_at' => $now, 'generation_idempotency_key' => (string) Str::uuid(),
        'generation_payload_checksum' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_statement_lines')->insert([
        'id' => $lineId, 'statement_id' => $statementId, 'line_type' => 'earning',
        'source_type' => 'commission_decision', 'source_id' => $sourceId, 'description' => 'Collection commission earning',
        'gross_lkr' => 1250, 'deduction_lkr' => 0, 'net_lkr' => 1250, 'line_status' => 'included',
        'calculation_snapshot' => json_encode(['company_id' => $company->id, 'staff_id' => $staff->id]),
        'snapshot_checksum' => str_repeat('b', 64), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $disputeId = (string) Str::uuid();
    DB::table('sales_commission_disputes')->insert([
        'id' => $disputeId, 'company_id' => $company->id, 'statement_id' => $statementId,
        'statement_line_id' => $lineId, 'raised_by_staff_id' => $staff->id, 'category' => 'earning',
        'reason' => 'The frozen earning amount needs review.', 'contested_amount_lkr' => 250,
        'status' => 'open', 'raised_at' => $now, 'response_due_at' => $now->copy()->addDays(5),
        'idempotency_key' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    $payoutId = (string) Str::uuid();
    DB::table('sales_commission_payouts')->insert([
        'id' => $payoutId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'payout_number' => 'SCP-DETAIL-PRIVACY', 'amount_lkr' => 500, 'payment_method' => 'bank',
        'payment_account_snapshot' => 'confidential account snapshot', 'payment_reference' => 'PAY-DETAIL-PRIVACY',
        'paid_at' => $now, 'status' => 'confirmed', 'accounting_status' => 'pending_delivery',
        'paid_by' => $admin->id, 'idempotency_key' => (string) Str::uuid(),
        'request_payload_checksum' => str_repeat('c', 64), 'reason' => 'confidential payout reason',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $events = Mockery::mock(DomainEventPublisher::class);
    $events->shouldReceive('record')->once()->andReturn((string) Str::uuid());
    app()->instance(DomainEventPublisher::class, $events);

    actingAs($admin, 'api')->getJson('/api/sales/commission-statements')->assertOk();
    actingAs($admin, 'api')->getJson('/api/sales/commission-disputes')->assertOk();
    actingAs($admin, 'api')->getJson('/api/sales/commission-payouts')->assertOk();

    $index = actingAs($admin, 'api')->getJson('/api/sales/commission-statements?company_id='.$company->id)
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $statementId)
        ->assertJsonPath('data.data.0.statement_number', 'SCS-DETAIL-PRIVACY')
        ->assertJsonPath('data.data.0.state_version', 1)
        ->assertJsonPath('data.data.0.opening_carry_forward_lkr', '0.0000')
        ->assertJsonMissingPath('data.data.0.company_id')
        ->assertJsonMissingPath('data.data.0.staff_id')
        ->assertJsonMissingPath('data.data.0.sales_profile_id')
        ->assertJsonMissingPath('data.data.0.cycle_version_id');

    $response = actingAs($admin, 'api')->getJson('/api/sales/commission-statements/'.$statementId)
        ->assertOk()
        ->assertJsonPath('data.statement_number', 'SCS-DETAIL-PRIVACY')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.lines.0.id', $lineId)
        ->assertJsonPath('data.lines.0.description', 'Collection commission earning')
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.company_id')
        ->assertJsonMissingPath('data.staff_id')
        ->assertJsonMissingPath('data.sales_profile_id')
        ->assertJsonMissingPath('data.cycle_version_id')
        ->assertJsonMissingPath('data.cycle_assignment_id')
        ->assertJsonMissingPath('data.cycle_schedule_snapshot')
        ->assertJsonMissingPath('data.generation_idempotency_key')
        ->assertJsonMissingPath('data.generation_payload_checksum')
        ->assertJsonMissingPath('data.prepared_by')
        ->assertJsonMissingPath('data.lines.0.source_id')
        ->assertJsonMissingPath('data.lines.0.source_type')
        ->assertJsonMissingPath('data.lines.0.calculation_snapshot')
        ->assertJsonMissingPath('data.lines.0.snapshot_checksum');

    $disputes = actingAs($admin, 'api')->getJson('/api/sales/commission-disputes?company_id='.$company->id.'&status=open')
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $disputeId)
        ->assertJsonPath('data.data.0.category', 'earning')
        ->assertJsonPath('data.data.0.reason', 'The frozen earning amount needs review.')
        ->assertJsonMissingPath('data.data.0.company_id')
        ->assertJsonMissingPath('data.data.0.statement_id')
        ->assertJsonMissingPath('data.data.0.statement_line_id')
        ->assertJsonMissingPath('data.data.0.raised_by_staff_id')
        ->assertJsonMissingPath('data.data.0.idempotency_key');

    $payouts = actingAs($admin, 'api')->getJson('/api/sales/commission-payouts?company_id='.$company->id)
        ->assertOk()
        ->assertJsonPath('data.data.0.id', $payoutId)
        ->assertJsonPath('data.data.0.payout_number', 'SCP-DETAIL-PRIVACY')
        ->assertJsonPath('data.data.0.amount_lkr', '500.0000')
        ->assertJsonPath('data.data.0.payment_reference', 'PAY-DETAIL-PRIVACY')
        ->assertJsonPath('data.data.0.accounting_status', 'pending_delivery')
        ->assertJsonMissingPath('data.data.0.company_id')
        ->assertJsonMissingPath('data.data.0.staff_id')
        ->assertJsonMissingPath('data.data.0.paid_by')
        ->assertJsonMissingPath('data.data.0.idempotency_key')
        ->assertJsonMissingPath('data.data.0.request_payload_checksum')
        ->assertJsonMissingPath('data.data.0.reason');

    $deliveryKey = 'delivery-privacy-'.Str::uuid();
    $delivery = actingAs($admin, 'api')->postJson('/api/sales/commission-payouts/'.$payoutId.'/accounting-delivery', [
        'status' => 'failed', 'message' => 'PRIVATE-FAILURE-DETAIL', 'idempotency_key' => $deliveryKey,
    ])->assertCreated()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.event_type', 'payout')
        ->assertJsonStructure(['data' => ['status', 'event_type', 'recorded_at']])
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.company_id')
        ->assertJsonMissingPath('data.payout_id')
        ->assertJsonMissingPath('data.recorded_by')
        ->assertJsonMissingPath('data.idempotency_key')
        ->assertJsonMissingPath('data.request_payload_checksum')
        ->assertJsonMissingPath('data.external_reference')
        ->assertJsonMissingPath('data.message');
    expect(DB::table('sales_commission_payouts')->where('id', $payoutId)->value('accounting_status'))
        ->toBe('delivery_failed');

    expect($index->getContent())->not->toContain(
        (string) $company->id, (string) $staff->id, $profileId, $cycleId, $assignmentId,
    )->and($response->getContent())->not->toContain(
        $sourceId, (string) $company->id, (string) $staff->id, (string) $admin->id, $profileId, $cycleId, $assignmentId,
        str_repeat('a', 64), str_repeat('b', 64),
    )->and($disputes->getContent())->not->toContain(
        (string) $company->id, (string) $staff->id, $statementId, $lineId,
    )->and($payouts->getContent())->not->toContain(
        (string) $company->id, (string) $staff->id, (string) $admin->id,
        'confidential account snapshot', str_repeat('c', 64), 'confidential payout reason',
    )->and($delivery->getContent())->not->toContain(
        (string) $company->id, (string) $staff->id, (string) $admin->id, $payoutId,
        $deliveryKey, 'PRIVATE-FAILURE-DETAIL',
    );
});
