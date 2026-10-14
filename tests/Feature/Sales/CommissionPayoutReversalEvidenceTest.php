<?php

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Company;
use App\Models\Sales\SalesCommissionPayout;
use App\Models\Staff;
use App\Models\User;
use App\Services\Foundation\DomainOutboxService;
use App\Services\Sales\CommissionAccountingOutboxReconciliationService;
use App\Services\Sales\CommissionPayoutService;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\Sales\VerifiedCommissionAccountingDeliveryService;
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

    return [$service, $payout, $actor, $events, $policy];
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

it('rejects payout reversal when its legal entity is inactive', function () {
    [$service, $payout, $actor] = payout_reversal_fixture();
    Company::query()->whereKey($payout->company_id)->update(['is_active' => false]);

    expect(fn () => $service->reverse($payout, [
        'payment_method' => 'bank', 'payment_reference' => 'reversal-'.Str::uuid(),
        'reversed_at' => now()->toIso8601String(), 'evidence_file_id' => (string) Str::uuid(),
        'reason' => 'Finance-authorized reversal', 'idempotency_key' => (string) Str::uuid(),
    ], (string) $actor->id))->toThrow(HttpException::class, 'Select an active legal entity.');
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
    $events->shouldReceive('record')->twice()->andReturn((string) Str::uuid());

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

it('writes an immutable, causally linked accounting-ready event for a payout reversal', function () {
    [, $payout, $actor, , $policy] = payout_reversal_fixture();
    $evidenceId = payout_reversal_evidence($payout, $payout->company_id, $actor);
    $service = new CommissionPayoutService(new DomainOutboxService(), $policy);

    $reversal = $service->reverse($payout, [
        'payment_method' => 'bank', 'payment_reference' => 'reversal-'.Str::uuid(),
        'reversed_at' => now()->toIso8601String(), 'evidence_file_id' => $evidenceId,
        'reason' => 'Reverse the original payout with supporting evidence',
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id);

    $events = DB::table('domain_outbox_events')->where('aggregate_type', 'commission_payout')
        ->where('aggregate_id', $reversal->id)->orderBy('event_version')->get();
    $accountingEvent = $events->last();
    $payload = json_decode($accountingEvent->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($events)->toHaveCount(2)
        ->and(config('foundation.outbox_subscribers.sales'))->not->toHaveKey('sales.commission.accounting_ready')
        ->and($accountingEvent->event_type)->toBe('sales.commission.accounting_ready')
        ->and($accountingEvent->event_version)->toBe(2)
        ->and($accountingEvent->causation_id)->toBe($events->first()->id)
        ->and(hash('sha256', $accountingEvent->payload))->toBe($accountingEvent->payload_checksum)
        ->and($payload)->toMatchArray([
            'document_type' => 'payout_reversal', 'document_id' => $reversal->id,
            'document_number' => $reversal->payout_number, 'amount_lkr' => '-1000.0000',
            'currency' => 'LKR', 'payment_reference' => $reversal->payment_reference,
            'reverses_document_id' => $payout->id, 'evidence_file_id' => $evidenceId,
            'accounting_status' => 'pending_delivery',
        ])
        ->and($payload['statement_allocations'])->toBe([
            ['amount_lkr' => '-1000.0000', 'statement_number' => DB::table('sales_commission_statements')->where('id',
                DB::table('sales_commission_payout_allocations')->where('payout_id', $reversal->id)->value('statement_id')
            )->value('statement_number')],
        ]);

    $reconciliation = (new CommissionAccountingOutboxReconciliationService())->preview($payout->company_id);
    $reconciledReversal = $reconciliation['data']->getCollection()
        ->firstWhere('payout_number', $reversal->payout_number);
    expect($reconciledReversal['issues'])->toBe([])
        ->and($reconciledReversal['integration_status'])->toBe('awaiting_subscriber');
});

it('writes an immutable accounting-ready event when a commission payout is confirmed', function () {
    [, $existingPayout, $actor, , $policy] = payout_reversal_fixture('pending_delivery');
    $statementId = DB::table('sales_commission_payout_allocations')->value('statement_id');
    DB::table('sales_commission_payout_allocations')->where('payout_id', $existingPayout->id)->delete();
    DB::table('sales_commission_payouts')->where('id', $existingPayout->id)->delete();
    DB::table('sales_commission_statements')->where('id', $statementId)->update([
        'status' => 'approved', 'paid_lkr' => 0, 'settlement_at' => now()->subMinute(),
    ]);
    $service = new CommissionPayoutService(new DomainOutboxService(), $policy);

    $payout = $service->pay([$statementId], [
        'amount_lkr' => 1000, 'payment_method' => 'bank',
        'payment_account_snapshot' => ['holder' => 'Approved payee snapshot'],
        'payment_reference' => 'payout-'.Str::uuid(), 'paid_at' => now()->toIso8601String(),
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id);

    $events = DB::table('domain_outbox_events')->where('aggregate_type', 'commission_payout')
        ->where('aggregate_id', $payout->id)->orderBy('event_version')->get();
    $accountingEvent = $events->last();
    $payload = json_decode($accountingEvent->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($events)->toHaveCount(2)
        ->and(config('foundation.outbox_subscribers.sales'))->not->toHaveKey('sales.commission.accounting_ready')
        ->and($accountingEvent->event_type)->toBe('sales.commission.accounting_ready')
        ->and($accountingEvent->event_version)->toBe(2)
        ->and($accountingEvent->causation_id)->toBe($events->first()->id)
        ->and(hash('sha256', $accountingEvent->payload))->toBe($accountingEvent->payload_checksum)
        ->and($payload)->toMatchArray([
            'document_type' => 'payout', 'document_id' => $payout->id,
            'document_number' => $payout->payout_number, 'amount_lkr' => '1000.0000',
            'currency' => 'LKR', 'payment_reference' => $payout->payment_reference,
            'reverses_document_id' => null, 'accounting_status' => 'pending_delivery',
        ]);

    $reconciliation = (new CommissionAccountingOutboxReconciliationService())->preview($payout->company_id);
    expect($reconciliation['summary']['page_issue_count'])->toBe(0)
        ->and($reconciliation['data']->getCollection()->first()['accounting_event_checksum_valid'])->toBeTrue();

    $foreignCompany = Company::create(['name' => 'Foreign allocation statement company']);
    DB::table('sales_commission_statements')->where('id', $statementId)->update(['company_id' => $foreignCompany->id]);
    $corruptedReconciliation = (new CommissionAccountingOutboxReconciliationService())->preview($payout->company_id);
    $corruptedIssues = $corruptedReconciliation['data']->getCollection()->first()['issues'];
    expect($corruptedIssues)->toContain('payout_allocations_missing', 'payout_allocation_total_mismatch', 'accounting_event_facts_mismatch');
    DB::table('sales_commission_statements')->where('id', $statementId)->update(['company_id' => $payout->company_id]);

    $verifier = new VerifiedCommissionAccountingDeliveryService(
        new DomainOutboxService(), new CommissionAccountingOutboxReconciliationService(),
    );
    $deliveryKey = (string) Str::uuid();
    $delivery = $verifier->recordQuickBooksAcceptance(
        $payout, (string) $accountingEvent->id, 'QB-TX-'.Str::uuid(), str_repeat('d', 64), $deliveryKey,
    );
    $retry = $verifier->recordQuickBooksAcceptance(
        $payout, (string) $accountingEvent->id, $delivery->provider_transaction_id,
        $delivery->provider_response_checksum, $deliveryKey,
    );

    expect($payout->fresh()->accounting_status)->toBe('delivered')
        ->and($delivery->verification_source)->toBe('quickbooks')
        ->and($delivery->source_outbox_event_id)->toBe($accountingEvent->id)
        ->and($delivery->recorded_by)->toBeNull()
        ->and($retry->id)->toBe($delivery->id)
        ->and(DB::table('sales_commission_accounting_deliveries')->where('payout_id', $payout->id)->count())->toBe(1)
        ->and(fn () => $verifier->recordQuickBooksAcceptance(
            $payout, (string) $accountingEvent->id, $delivery->provider_transaction_id,
            str_repeat('e', 64), $deliveryKey,
        ))->toThrow(HttpException::class, 'This accounting-delivery key is already bound to different provider evidence.');
});

it('binds manual accounting failure retries to exact facts without claiming delivery', function () {
    [$service, $payout, $actor, $events] = payout_reversal_fixture('pending_delivery');
    $events->shouldReceive('record')->once()->andReturn((string) Str::uuid());
    $failed = [
        'status' => 'failed',
        'message' => 'Accounting system did not acknowledge the payout',
        'idempotency_key' => (string) Str::uuid(),
    ];

    $stalePayout = clone $payout;
    $stalePayout->status = 'reversal';
    $failedDelivery = $service->recordAccountingDelivery($stalePayout, $failed, $actor->id);
    $failedRetry = $service->recordAccountingDelivery($payout, $failed, $actor->id);
    expect($failedRetry->id)->toBe($failedDelivery->id)
        ->and($failedDelivery->event_type)->toBe('payout')
        ->and($failedDelivery->verification_source)->toBe('manual')
        ->and($payout->fresh()->accounting_status)->toBe('delivery_failed')
        ->and(DB::table('sales_commission_accounting_deliveries')->where('payout_id', $payout->id)->count())->toBe(1)
        ->and(fn () => $service->recordAccountingDelivery($payout, [
            'status' => 'accepted',
            'external_reference' => 'ERP-PAYOUT-REFERENCE-1',
            'idempotency_key' => (string) Str::uuid(),
        ], $actor->id))
        ->toThrow(HttpException::class, 'A manual reference cannot verify accounting delivery; successful delivery requires a verified QuickBooks response.');
});

it('rejects accounting acknowledgements for payouts that have already been reversed', function () {
    [$service, $payout, $actor] = payout_reversal_fixture('pending_delivery');
    $payout->update(['status' => 'reversed']);

    expect(fn () => $service->recordAccountingDelivery($payout, [
        'status' => 'failed',
        'message' => 'Attempt against reversed payout',
        'idempotency_key' => (string) Str::uuid(),
    ], $actor->id))->toThrow(HttpException::class,
        'Accounting delivery can only be recorded for a confirmed payout or its reversal.')
        ->and(DB::table('sales_commission_accounting_deliveries')->where('payout_id', $payout->id)->exists())->toBeFalse()
        ->and($payout->fresh()->accounting_status)->toBe('pending_delivery');
});
