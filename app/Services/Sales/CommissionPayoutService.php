<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesCommissionPayout;
use App\Models\Sales\SalesCommissionPayoutAllocation;
use App\Models\Sales\SalesCommissionStatement;
use App\Models\Sales\SalesCommissionStatementEvent;
use App\Models\Sales\SalesCommissionAccountingDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionPayoutService
{
    public function __construct(private readonly DomainEventPublisher $events) {}

    public function pay(array $statementIds, array $data, string $actorUserId): SalesCommissionPayout
    {
        abort_unless(config('sales.features.payouts', false), 409, 'Commission payouts are not activated.');
        return DB::transaction(function () use ($statementIds, $data, $actorUserId) {
            $checksum = $this->checksum(['statement_ids' => array_values($statementIds), ...$data]);
            $duplicate = SalesCommissionPayout::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->request_payload_checksum, $checksum), 422, 'This payout key was already used with different facts.');
                return $duplicate;
            }
            $statements = SalesCommissionStatement::query()->whereIn('id', $statementIds)
                ->orderBy('period_end')->lockForUpdate()->get();
            if ($statements->count() !== count(array_unique($statementIds))) {
                throw ValidationException::withMessages(['statement_ids' => ['One or more statements no longer exist.']]);
            }
            abort_if($statements->pluck('company_id')->unique()->count() !== 1 || $statements->pluck('staff_id')->unique()->count() !== 1,
                422, 'A payout cannot cross legal entities or Staff beneficiaries.');
            abort_if($statements->contains(fn ($statement) => ! in_array($statement->status, ['approved', 'partially_paid'], true)),
                422, 'Only approved or partially paid statements can be paid.');
            abort_if($statements->contains(fn ($statement) => in_array($actorUserId, [$statement->prepared_by, $statement->approved_by], true)),
                403, 'The statement preparer or approver cannot execute its payout.');
            abort_if($statements->contains(fn ($statement) => ! $statement->settlement_at), 422,
                'Every statement must contain a frozen commission-cycle settlement date.');
            $paidAt = CarbonImmutable::parse($data['paid_at']);
            abort_if($statements->contains(fn ($statement) => $paidAt->lt($statement->settlement_at)), 422,
                'A commission payout cannot be recorded before every selected statement settlement date.');
            $available = round((float) $statements->sum(fn ($statement) => max(0,
                (float) $statement->net_payable_lkr - (float) $statement->contested_hold_lkr - (float) $statement->paid_lkr)), 4);
            $amount = round((float) $data['amount_lkr'], 4);
            abort_if($amount > $available, 422, "Payout exceeds the uncontested approved balance of {$available} LKR.");
            if (! empty($data['evidence_file_id'])) {
                $validEvidence = DB::table('domain_evidence_files')->whereKey($data['evidence_file_id'])
                    ->where('domain', 'sales')->where('company_id', $statements->first()->company_id)->whereNull('deleted_at')
                    ->where('subject_type', 'commission_statement')->whereIn('subject_id', $statements->pluck('id'))->exists();
                abort_unless($validEvidence, 422, 'Payout evidence must be bound to one selected statement and its Sales legal entity.');
            }
            $payout = SalesCommissionPayout::create([
                'company_id' => $statements->first()->company_id, 'staff_id' => $statements->first()->staff_id,
                'payout_number' => 'SCP-'.now()->format('YmdHis').'-'.strtoupper(substr((string) Str::uuid(), 0, 6)),
                'amount_lkr' => $amount, 'payment_method' => $data['payment_method'],
                'payment_account_snapshot' => $data['payment_account_snapshot'],
                'payment_reference' => $data['payment_reference'], 'paid_at' => $data['paid_at'],
                'evidence_file_id' => $data['evidence_file_id'] ?? null, 'status' => 'confirmed',
                'accounting_status' => 'pending_delivery', 'paid_by' => $actorUserId,
                'idempotency_key' => $data['idempotency_key'], 'reason' => $data['reason'] ?? null,
                'request_payload_checksum' => $checksum,
            ]);
            $remaining = $amount;
            foreach ($statements as $statement) {
                if ($remaining <= 0) break;
                $statementAvailable = max(0, round((float) $statement->net_payable_lkr
                    - (float) $statement->contested_hold_lkr - (float) $statement->paid_lkr, 4));
                $allocated = min($remaining, $statementAvailable);
                if ($allocated <= 0) continue;
                SalesCommissionPayoutAllocation::create(['payout_id' => $payout->id, 'statement_id' => $statement->id, 'amount_lkr' => $allocated]);
                $newPaid = round((float) $statement->paid_lkr + $allocated, 4);
                $uncontestedPayable = max(0, round((float) $statement->net_payable_lkr - (float) $statement->contested_hold_lkr, 4));
                $fromStatus = $statement->status; $fromVersion = $statement->state_version;
                $toStatus = $newPaid >= $uncontestedPayable ? 'paid' : 'partially_paid';
                $statement->update(['paid_lkr' => $newPaid, 'status' => $toStatus, 'state_version' => $fromVersion + 1]);
                SalesCommissionStatementEvent::create([
                    'statement_id' => $statement->id, 'from_version' => $fromVersion, 'to_version' => $fromVersion + 1,
                    'from_status' => $fromStatus, 'to_status' => $toStatus,
                    'reason' => 'Payout allocation '.$payout->id, 'idempotency_key' => 'payout:'.$payout->id,
                    'actor_user_id' => $actorUserId, 'occurred_at' => now(),
                ]);
                $remaining = round($remaining - $allocated, 4);
            }
            $this->events->record('sales', $payout->company_id, 'commission_payout', $payout->id,
                'sales.commission.paid', 1, 1, [
                    'staff_id' => $payout->staff_id, 'amount_lkr' => (string) $payout->amount_lkr,
                    'payment_reference' => $payout->payment_reference,
                    'allocations' => SalesCommissionPayoutAllocation::query()->where('payout_id', $payout->id)->get()->toArray(),
                    'accounting_delivery_required' => true, 'payroll_adapter_used' => false,
                ], $payout->paid_at, $data['idempotency_key']);
            return $payout;
        });
    }

    public function reverse(SalesCommissionPayout $original, array $data, string $actorUserId): SalesCommissionPayout
    {
        abort_unless(config('sales.features.payouts', false), 409, 'Commission payouts are not activated.');
        return DB::transaction(function () use ($original, $data, $actorUserId) {
            $original = SalesCommissionPayout::query()->lockForUpdate()->findOrFail($original->id);
            $checksum = $this->checksum(['original_payout_id' => $original->id, ...$data]);
            $duplicate = SalesCommissionPayout::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->request_payload_checksum, $checksum), 422, 'This payout reversal key was already used with different facts.');
                return $duplicate;
            }
            abort_unless($original->status === 'confirmed' && ! $original->reverses_payout_id, 422, 'Only a confirmed original payout can be reversed once.');
            abort_if($original->paid_by === $actorUserId, 403, 'The original payer cannot approve their own payout reversal.');
            if (! empty($data['evidence_file_id'])) {
                abort_unless(DB::table('domain_evidence_files')->whereKey($data['evidence_file_id'])
                    ->where('domain', 'sales')->where('company_id', $original->company_id)->whereNull('deleted_at')
                    ->where('subject_type', 'commission_payout')->where('subject_id', $original->id)->exists(), 422,
                    'Payout-reversal evidence must be bound to the original payout and Sales legal entity.');
            }
            $allocations = SalesCommissionPayoutAllocation::query()->where('payout_id', $original->id)->lockForUpdate()->get();
            $reversal = SalesCommissionPayout::create([
                'company_id' => $original->company_id, 'staff_id' => $original->staff_id,
                'payout_number' => 'SCR-'.now()->format('YmdHis').'-'.strtoupper(substr((string) Str::uuid(), 0, 6)),
                'amount_lkr' => $original->amount_lkr, 'payment_method' => $data['payment_method'],
                'payment_account_snapshot' => $original->payment_account_snapshot,
                'payment_reference' => $data['payment_reference'], 'paid_at' => $data['reversed_at'],
                'evidence_file_id' => $data['evidence_file_id'] ?? null, 'status' => 'reversal',
                'accounting_status' => 'pending_delivery', 'paid_by' => $actorUserId,
                'idempotency_key' => $data['idempotency_key'], 'reverses_payout_id' => $original->id, 'reason' => $data['reason'],
                'request_payload_checksum' => $checksum,
            ]);
            foreach ($allocations as $allocation) {
                $statement = SalesCommissionStatement::query()->lockForUpdate()->findOrFail($allocation->statement_id);
                $fromStatus = $statement->status; $fromVersion = $statement->state_version;
                $newPaid = max(0, round((float) $statement->paid_lkr - (float) $allocation->amount_lkr, 4));
                $available = max(0, round((float) $statement->net_payable_lkr - (float) $statement->contested_hold_lkr, 4));
                $toStatus = $newPaid <= 0 ? 'approved' : ($newPaid >= $available ? 'paid' : 'partially_paid');
                SalesCommissionPayoutAllocation::create(['payout_id' => $reversal->id, 'statement_id' => $statement->id, 'amount_lkr' => -abs((float) $allocation->amount_lkr)]);
                $statement->update(['paid_lkr' => $newPaid, 'status' => $toStatus, 'state_version' => $fromVersion + 1]);
                SalesCommissionStatementEvent::create([
                    'statement_id' => $statement->id, 'from_version' => $fromVersion, 'to_version' => $fromVersion + 1,
                    'from_status' => $fromStatus, 'to_status' => $toStatus, 'reason' => 'Payout reversal '.$reversal->id,
                    'idempotency_key' => 'payout-reversal:'.$reversal->id, 'actor_user_id' => $actorUserId, 'occurred_at' => now(),
                ]);
            }
            $original->update(['status' => 'reversed', 'reason' => trim(($original->reason ? $original->reason."\n" : '').'Reversed by '.$reversal->id.': '.$data['reason'])]);
            $this->events->record('sales', $reversal->company_id, 'commission_payout', $reversal->id,
                'sales.commission.payout_reversed', 1, 1, [
                    'original_payout_id' => $original->id, 'amount_lkr' => '-'.(string) $reversal->amount_lkr,
                    'payment_reference' => $reversal->payment_reference, 'accounting_delivery_required' => true,
                ], $reversal->paid_at, $data['idempotency_key']);
            return $reversal;
        });
    }

    public function recordAccountingDelivery(SalesCommissionPayout $payout, array $data, string $actorUserId): SalesCommissionAccountingDelivery
    {
        return DB::transaction(function () use ($payout, $data, $actorUserId) {
            $payout = SalesCommissionPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $duplicate = SalesCommissionAccountingDelivery::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) return $duplicate;
            $delivery = SalesCommissionAccountingDelivery::create([
                'company_id' => $payout->company_id, 'payout_id' => $payout->id,
                'event_type' => $payout->status === 'reversal' ? 'payout_reversal' : 'payout',
                'status' => $data['status'], 'external_reference' => $data['external_reference'] ?? null,
                'message' => $data['message'] ?? null, 'idempotency_key' => $data['idempotency_key'],
                'recorded_by' => $actorUserId, 'recorded_at' => now(),
            ]);
            $payout->update(['accounting_status' => $data['status'] === 'accepted' ? 'delivered' : 'delivery_failed']);
            return $delivery;
        });
    }

    private function checksum(array $facts): string
    { return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}
