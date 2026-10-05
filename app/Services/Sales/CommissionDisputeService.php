<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesCommissionDispute;
use App\Models\Sales\SalesCommissionStatement;
use App\Models\Sales\SalesCommissionStatementLine;
use App\Models\Sales\SalesCommissionStatementAdjustment;
use App\Models\Sales\SalesCommissionStatementEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CommissionDisputeService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function raise(SalesCommissionStatementLine $line, string $staffId, array $data): SalesCommissionDispute
    {
        try {
            return DB::transaction(function () use ($line, $staffId, $data) {
            $statement = SalesCommissionStatement::query()->lockForUpdate()->findOrFail($line->statement_id);
            abort_unless($statement->staff_id === $staffId, 403, 'Only the statement beneficiary may dispute this line.');
            abort_unless(DB::table('staff')->where('id', $staffId)->where('company_id', $statement->company_id)->exists(), 409,
                'The statement beneficiary Staff record does not match its legal entity.');
            $line = SalesCommissionStatementLine::query()->whereKey($line->id)
                ->where('statement_id', $statement->id)->lockForUpdate()->first();
            abort_unless($line, 409, 'The disputed line does not belong to this statement.');
            $requestChecksum = hash('sha256', json_encode([
                'statement_id' => (string) $statement->id,
                'statement_line_id' => (string) $line->id,
                'raised_by_staff_id' => $staffId,
                'category' => $data['category'],
                'reason' => $data['reason'],
                'evidence_file_id' => $data['evidence_file_id'] ?? null,
                'contested_amount_lkr' => (string) $data['contested_amount_lkr'],
                'idempotency_key' => $data['idempotency_key'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $duplicate = SalesCommissionDispute::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($duplicate) {
                abort_unless($duplicate->request_payload_checksum
                    && hash_equals($duplicate->request_payload_checksum, $requestChecksum), 409,
                    'This dispute key is already bound to different or unverified facts.');
                return $duplicate;
            }
            $responseDays = $this->policySettings->disputeResponseDays((string) $statement->company_id);
            abort_unless($responseDays !== null, 409,
                'An approved commission-dispute response-window policy is required before a dispute can be raised.');
            abort_if(in_array($statement->status, ['paid', 'void'], true), 422, 'Paid or void statements cannot accept a new dispute.');
            abort_if(SalesCommissionDispute::query()->where('statement_line_id', $line->id)->where('status', 'open')->exists(), 422, 'This line already has an open dispute.');
            $amount = round((float) $data['contested_amount_lkr'], 4);
            abort_if($amount > max(0, (float) $line->net_lkr), 422, 'Contested amount exceeds the positive statement-line amount.');
            if (! empty($data['evidence_file_id'])) {
                abort_unless(DB::table('domain_evidence_files')->whereKey($data['evidence_file_id'])
                    ->where('domain', 'sales')->where('company_id', $statement->company_id)->whereNull('deleted_at')
                    ->where('subject_type', 'commission_statement_line')->where('subject_id', $line->id)->exists(), 422,
                    'Dispute evidence must be bound to this statement line and Sales legal entity.');
            }
            $dispute = SalesCommissionDispute::create([
                'company_id' => $statement->company_id, 'statement_id' => $statement->id,
                'statement_line_id' => $line->id, 'raised_by_staff_id' => $staffId,
                'category' => $data['category'], 'reason' => $data['reason'],
                'evidence_file_id' => $data['evidence_file_id'] ?? null, 'contested_amount_lkr' => $amount,
                'status' => 'open', 'raised_at' => now(), 'response_due_at' => now()->addDays($responseDays),
                'idempotency_key' => $data['idempotency_key'], 'request_payload_checksum' => $requestChecksum,
            ]);
            $fromVersion = $statement->state_version;
            $statement->update(['contested_hold_lkr' => round((float) $statement->contested_hold_lkr + $amount, 4), 'state_version' => $fromVersion + 1]);
            SalesCommissionStatementEvent::create([
                'statement_id' => $statement->id, 'from_version' => $fromVersion, 'to_version' => $fromVersion + 1,
                'from_status' => $statement->status, 'to_status' => $statement->status,
                'reason' => 'Contested payout hold opened for dispute '.$dispute->id,
                'idempotency_key' => 'dispute-open:'.$dispute->id, 'actor_user_id' => DB::table('staff')->where('id', $staffId)->value('user_id'), 'occurred_at' => now(),
            ]);
            $this->events->record('sales', $statement->company_id, 'commission_dispute', $dispute->id,
                'sales.commission.dispute_raised', 1, 1, ['statement_id' => $statement->id, 'line_id' => $line->id, 'contested_amount_lkr' => (string) $amount], now(), $data['idempotency_key']);
            return $dispute;
            });
        } catch (QueryException $exception) {
            $duplicate = SalesCommissionDispute::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if (! $duplicate) {
                throw $exception;
            }
            $requestChecksum = hash('sha256', json_encode([
                'statement_id' => (string) $line->statement_id,
                'statement_line_id' => (string) $line->id,
                'raised_by_staff_id' => $staffId,
                'category' => $data['category'],
                'reason' => $data['reason'],
                'evidence_file_id' => $data['evidence_file_id'] ?? null,
                'contested_amount_lkr' => (string) $data['contested_amount_lkr'],
                'idempotency_key' => $data['idempotency_key'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            abort_unless($duplicate->request_payload_checksum
                && hash_equals($duplicate->request_payload_checksum, $requestChecksum), 409,
                'This dispute key is already bound to different or unverified facts.');

            return $duplicate;
        }
    }

    public function resolve(SalesCommissionDispute $dispute, array $data, string $actorUserId): SalesCommissionDispute
    {
        try {
            return DB::transaction(function () use ($dispute, $data, $actorUserId) {
            $statement = SalesCommissionStatement::query()->lockForUpdate()->findOrFail($dispute->statement_id);
            $dispute = SalesCommissionDispute::query()->lockForUpdate()->findOrFail($dispute->id);
            abort_unless($dispute->statement_id === $statement->id && $dispute->company_id === $statement->company_id, 409,
                'The dispute statement does not match its legal entity.');
            $line = SalesCommissionStatementLine::query()->whereKey($dispute->statement_line_id)
                ->where('statement_id', $statement->id)->lockForUpdate()->first();
            abort_unless($line && $dispute->raised_by_staff_id === $statement->staff_id
                && DB::table('staff')->where('id', $statement->staff_id)->where('company_id', $statement->company_id)->exists(), 409,
                'The dispute line or claimant Staff record does not match its statement and legal entity.');
            $checksum = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($dispute->resolution_idempotency_key === $data['idempotency_key']) {
                abort_unless(hash_equals((string) $dispute->resolution_payload_checksum, $checksum), 422,
                    'This dispute-resolution key was already used with different facts.');
                return $dispute;
            }
            abort_unless($dispute->status === 'open', 422, 'Only an open dispute can be resolved.');
            abort_if(DB::table('staff')->where('user_id', $actorUserId)->where('id', $dispute->raised_by_staff_id)->exists(), 403,
                'A claimant cannot resolve their own dispute.');
            abort_unless((float) $statement->contested_hold_lkr >= (float) $dispute->contested_amount_lkr, 409,
                'The statement contested hold does not reconcile to this dispute.');
            $dispute->update([
                'status' => 'resolved', 'reviewed_by' => $actorUserId, 'resolved_at' => now(),
                'resolution' => $data['resolution'], 'resolution_reason' => $data['resolution_reason'],
                'resolution_idempotency_key' => $data['idempotency_key'], 'resolution_payload_checksum' => $checksum,
            ]);
            if ($data['resolution'] === 'uphold_adjustment') {
                SalesCommissionStatementAdjustment::create([
                    'company_id' => $statement->company_id, 'staff_id' => $statement->staff_id,
                    'dispute_id' => $dispute->id, 'amount_lkr' => $data['adjustment_lkr'],
                    'reason' => $data['resolution_reason'], 'approved_by' => $actorUserId,
                    'approved_at' => now(), 'idempotency_key' => $data['idempotency_key'],
                ]);
            }
            $fromVersion = $statement->state_version;
            $newHold = max(0, round((float) $statement->contested_hold_lkr - (float) $dispute->contested_amount_lkr, 4));
            $newStatus = $statement->status;
            if (in_array($statement->status, ['approved', 'partially_paid', 'paid'], true)) {
                $uncontested = max(0, round((float) $statement->net_payable_lkr - $newHold, 4));
                $newStatus = (float) $statement->paid_lkr <= 0 ? 'approved'
                    : ((float) $statement->paid_lkr >= $uncontested ? 'paid' : 'partially_paid');
            }
            $fromStatus = $statement->status;
            $statement->update(['contested_hold_lkr' => $newHold, 'status' => $newStatus, 'state_version' => $fromVersion + 1]);
            SalesCommissionStatementEvent::create([
                'statement_id' => $statement->id, 'from_version' => $fromVersion, 'to_version' => $fromVersion + 1,
                'from_status' => $fromStatus, 'to_status' => $newStatus,
                'reason' => 'Contested payout hold resolved for dispute '.$dispute->id,
                'idempotency_key' => 'dispute-resolve:'.$data['idempotency_key'], 'actor_user_id' => $actorUserId, 'occurred_at' => now(),
            ]);
            $this->events->record('sales', $statement->company_id, 'commission_dispute', $dispute->id,
                'sales.commission.dispute_resolved', 2, 1, ['resolution' => $data['resolution'], 'statement_id' => $statement->id], now(), $data['idempotency_key']);
            return $dispute;
            });
        } catch (QueryException $exception) {
            $duplicate = SalesCommissionDispute::query()
                ->where('resolution_idempotency_key', $data['idempotency_key'])->first();
            if (! $duplicate) {
                throw $exception;
            }
            $checksum = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            abort_unless($duplicate->id === $dispute->id
                && hash_equals((string) $duplicate->resolution_payload_checksum, $checksum), 409,
                'This dispute-resolution key is already bound to different facts or another dispute.');

            return $duplicate;
        }
    }
}
