<?php

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Company;
use App\Models\Sales\SalesCommissionPayout;
use App\Models\Staff;
use App\Models\User;
use App\Services\Sales\CommissionPayoutService;
use App\Services\Sales\SalesPolicySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function payout_reversal_fixture(string $accountingStatus = 'delivered'): array
{
    $company = Company::create(['name' => 'Payout reversal company']);
    $staff = Staff::factory()->create(['company_id' => $company->id]);
    $payer = User::factory()->create();
    $actor = User::factory()->create();
    $payout = SalesCommissionPayout::create([
        'company_id' => $company->id,
        'staff_id' => $staff->id,
        'payout_number' => 'SCP-'.Str::uuid(),
        'amount_lkr' => 1000,
        'payment_method' => 'bank',
        'payment_account_snapshot' => ['holder' => 'Test payee'],
        'payment_reference' => 'original-'.Str::uuid(),
        'paid_at' => now(),
        'status' => 'confirmed',
        'accounting_status' => $accountingStatus,
        'paid_by' => $payer->id,
        'idempotency_key' => (string) Str::uuid(),
        'request_payload_checksum' => str_repeat('a', 64),
    ]);
    $profileId = (string) Str::uuid();
    $cycleVersionId = (string) Str::uuid();
    $cycleAssignmentId = (string) Str::uuid();
    $statementId = (string) Str::uuid();
    $now = now();
    DB::table('sales_profiles')->insert([
        'id' => $profileId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_code' => 'PAYOUT-'.Str::upper(Str::random(8)), 'effective_from' => $now->copy()->subYear(),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_cycle_versions')->insert([
        'id' => $cycleVersionId, 'company_id' => $company->id, 'code' => 'PAYOUT-CYCLE', 'version' => 1,
        'timezone' => 'Asia/Colombo', 'earning_period_rule' => 'monthly', 'cutoff_day' => 25,
        'finalization_day' => 26, 'approval_deadline_day' => 27, 'settlement_day' => 28,
        'holiday_rule' => 'next_business_day', 'effective_from' => $now->copy()->subYear(),
        'status' => 'approved', 'created_by' => $payer->id, 'approved_by' => $payer->id,
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_cycle_assignments')->insert([
        'id' => $cycleAssignmentId, 'company_id' => $company->id, 'cycle_version_id' => $cycleVersionId,
        'scope_type' => 'company', 'precedence' => 1, 'effective_from' => $now->copy()->subYear(),
        'status' => 'approved', 'created_by' => $payer->id, 'approved_by' => $payer->id,
        'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_statements')->insert([
        'id' => $statementId, 'company_id' => $company->id, 'staff_id' => $staff->id,
        'sales_profile_id' => $profileId, 'cycle_version_id' => $cycleVersionId,
        'cycle_assignment_id' => $cycleAssignmentId, 'statement_number' => 'SCS-'.Str::uuid(),
        'period_start' => $now->toDateString(), 'period_end' => $now->toDateString(), 'cutoff_at' => $now,
        'timezone' => 'Asia/Colombo', 'net_payable_lkr' => 1000, 'paid_lkr' => 1000,
        'status' => 'paid', 'state_version' => 2, 'prepared_by' => $payer->id, 'prepared_at' => $now,
        'generation_idempotency_key' => (string) Str::uuid(), 'generation_payload_checksum' => str_repeat('c', 64),
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('sales_commission_payout_allocations')->insert([
        'id' => (string) Str::uuid(), 'payout_id' => $payout->id, 'statement_id' => $statementId,
        'amount_lkr' => 1000, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $policy = Mockery::mock(SalesPolicySettingsService::class);
    $policy->shouldReceive('featureEnabled')->zeroOrMoreTimes()->with($company->id, 'payouts')->andReturn(true);
    $events = Mockery::mock(DomainEventPublisher::class);
    $service = new CommissionPayoutService($events, $policy);

    return [$service, $payout, $actor, $events];
}

it('rejects payout execution when a statement profile belongs to another company', function () {
    [$service, $payout, $actor] = payout_reversal_fixture();
    $statementId = DB::table('sales_commission_payout_allocations')->where('payout_id', $payout->id)->value('statement_id');
    $profileId = DB::table('sales_commission_statements')->where('id', $statementId)->value('sales_profile_id');
    $foreignCompany = Company::create(['name' => 'Foreign payout profile company']);
    DB::table('sales_profiles')->where('id', $profileId)->update(['company_id' => $foreignCompany->id]);

    expect(fn () => $service->pay([$statementId], [
        'amount_lkr' => 1000,
        'payment_method' => 'bank',
        'payment_account_snapshot' => ['holder' => 'Test payee'],
        'payment_reference' => 'new-'.Str::uuid(),
        'paid_at' => now()->toIso8601String(),
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id))->toThrow(HttpException::class, 'Every statement must match its frozen Sales Profile company and Staff beneficiary.');
});

it('rejects payout reversal when allocations do not reconcile to the original amount', function () {
    [$service, $payout, $actor] = payout_reversal_fixture();
    $evidenceId = payout_reversal_evidence($payout, $payout->company_id, $actor);
    DB::table('sales_commission_payout_allocations')->where('payout_id', $payout->id)->update(['amount_lkr' => 900]);

    expect(fn () => $service->reverse($payout, [
        'payment_method' => 'bank',
        'payment_reference' => 'reversal-'.Str::uuid(),
        'reversed_at' => now()->toIso8601String(),
        'evidence_file_id' => $evidenceId,
        'reason' => 'Reverse payout after verifying its allocation ledger',
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id))->toThrow(HttpException::class, 'Payout reversal allocations must reconcile to the original payout amount.');
});

it('rejects payout reversal when an allocation exceeds the current statement paid balance', function () {
    [$service, $payout, $actor] = payout_reversal_fixture();
    $evidenceId = payout_reversal_evidence($payout, $payout->company_id, $actor);
    $statementId = DB::table('sales_commission_payout_allocations')->where('payout_id', $payout->id)->value('statement_id');
    DB::table('sales_commission_statements')->where('id', $statementId)->update(['paid_lkr' => 500]);

    expect(fn () => $service->reverse($payout, [
        'payment_method' => 'bank',
        'payment_reference' => 'reversal-'.Str::uuid(),
        'reversed_at' => now()->toIso8601String(),
        'evidence_file_id' => $evidenceId,
        'reason' => 'Reverse payout after verifying its allocation ledger',
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id))->toThrow(HttpException::class, 'Payout reversal allocation exceeds its statement paid balance.');
});

function payout_reversal_evidence(SalesCommissionPayout $payout, string $companyId, User $actor): string
{
    $id = (string) Str::uuid();
    DB::table('domain_evidence_files')->insert([
        'id' => $id,
        'domain' => 'sales',
        'company_id' => $companyId,
        'subject_type' => 'commission_payout',
        'subject_id' => $payout->id,
        'evidence_type' => 'payout_reversal',
        'classification' => 'restricted',
        'disk' => 'private',
        'path' => 'sales/test/payout-reversal.pdf',
        'file_name' => 'payout-reversal.pdf',
        'file_size' => 1,
        'file_checksum' => str_repeat('b', 64),
        'uploaded_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('rejects payout reversal calls that bypass the controller without evidence', function () {
    [$service, $payout, $actor] = payout_reversal_fixture();

    expect(fn () => $service->reverse($payout, [
        'payment_method' => 'bank',
        'payment_reference' => 'reversal-'.Str::uuid(),
        'reversed_at' => now()->toIso8601String(),
        'reason' => 'Reverse the original payout with supporting evidence',
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id))->toThrow(HttpException::class, 'Payout-reversal evidence is required.');
});

it('rejects payout evidence attached to a different legal entity', function () {
    [$service, $payout, $actor] = payout_reversal_fixture();
    $foreignCompany = Company::create(['name' => 'Foreign payout evidence company']);
    $evidenceId = payout_reversal_evidence($payout, $foreignCompany->id, $actor);

    expect(fn () => $service->reverse($payout, [
        'payment_method' => 'bank',
        'payment_reference' => 'reversal-'.Str::uuid(),
        'reversed_at' => now()->toIso8601String(),
        'evidence_file_id' => $evidenceId,
        'reason' => 'Reverse the original payout with supporting evidence',
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id))->toThrow(HttpException::class, 'Payout-reversal evidence must be bound to the original payout and Sales legal entity.');
});

it('reverses the payout only with evidence bound to that payout and company', function () {
    [$service, $payout, $actor, $events] = payout_reversal_fixture();
    $evidenceId = payout_reversal_evidence($payout, $payout->company_id, $actor);
    $events->shouldReceive('record')->once()->andReturn((string) Str::uuid());

    $reversal = $service->reverse($payout, [
        'payment_method' => 'bank',
        'payment_reference' => 'reversal-'.Str::uuid(),
        'reversed_at' => now()->toIso8601String(),
        'evidence_file_id' => $evidenceId,
        'reason' => 'Reverse the original payout with supporting evidence',
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id);

    expect($reversal->status)->toBe('reversal')
        ->and($reversal->reverses_payout_id)->toBe($payout->id)
        ->and($reversal->evidence_file_id)->toBe($evidenceId)
        ->and($payout->fresh()->status)->toBe('reversed');
});

it('binds accounting delivery retries to exact facts and preserves each result immutably', function () {
    [$service, $payout, $actor, $events] = payout_reversal_fixture('pending_delivery');
    $events->shouldReceive('record')->twice()->andReturn((string) Str::uuid());
    $failed = [
        'status' => 'failed',
        'message' => 'Accounting system did not acknowledge the payout',
        'idempotency_key' => (string) Str::uuid(),
    ];

    $stalePayout = clone $payout;
    $stalePayout->status = 'reversal';
    $failedDelivery = $service->recordAccountingDelivery($stalePayout, $failed, $actor->id);
    $failedRetry = $service->recordAccountingDelivery($payout, $failed, $actor->id);
    $accepted = [
        'status' => 'accepted',
        'external_reference' => 'ERP-PAYOUT-REFERENCE-1',
        'idempotency_key' => (string) Str::uuid(),
    ];
    $acceptedDelivery = $service->recordAccountingDelivery($payout, $accepted, $actor->id);
    $changedRetry = [...$accepted, 'external_reference' => 'ERP-PAYOUT-REFERENCE-2'];

    expect($failedRetry->id)->toBe($failedDelivery->id)
        ->and($failedDelivery->event_type)->toBe('payout')
        ->and($acceptedDelivery->id)->not->toBe($failedDelivery->id)
        ->and($payout->fresh()->accounting_status)->toBe('delivered')
        ->and(DB::table('sales_commission_accounting_deliveries')->where('payout_id', $payout->id)->count())->toBe(2)
        ->and(fn () => $service->recordAccountingDelivery($payout, $changedRetry, $actor->id))
        ->toThrow(HttpException::class, 'This accounting-delivery key is already bound to different or unverified facts.')
        ->and(fn () => $service->recordAccountingDelivery($payout, [
            'status' => 'failed',
            'message' => 'Attempt to overwrite accepted delivery',
            'idempotency_key' => (string) Str::uuid(),
        ], $actor->id))
        ->toThrow(HttpException::class, 'Accounting delivery is already accepted; its status cannot be overwritten.');
});
