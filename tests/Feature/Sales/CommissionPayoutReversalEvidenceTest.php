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
    $policy = Mockery::mock(SalesPolicySettingsService::class);
    $policy->shouldReceive('featureEnabled')->once()->with($company->id, 'payouts')->andReturn(true);
    $events = Mockery::mock(DomainEventPublisher::class);
    $service = new CommissionPayoutService($events, $policy);

    return [$service, $payout, $actor, $events];
}

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

    $failedDelivery = $service->recordAccountingDelivery($payout, $failed, $actor->id);
    $failedRetry = $service->recordAccountingDelivery($payout, $failed, $actor->id);
    $accepted = [
        'status' => 'accepted',
        'external_reference' => 'ERP-PAYOUT-REFERENCE-1',
        'idempotency_key' => (string) Str::uuid(),
    ];
    $acceptedDelivery = $service->recordAccountingDelivery($payout, $accepted, $actor->id);
    $changedRetry = [...$accepted, 'external_reference' => 'ERP-PAYOUT-REFERENCE-2'];

    expect($failedRetry->id)->toBe($failedDelivery->id)
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
