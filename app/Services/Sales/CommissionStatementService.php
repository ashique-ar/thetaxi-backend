<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesCommissionHoldRelease;
use App\Models\Sales\SalesCommissionHoldAdjustment;
use App\Models\Sales\SalesCommissionRecoveryCase;
use App\Models\Sales\SalesCommissionRecoveryDecision;
use App\Models\Sales\SalesCommissionStatement;
use App\Models\Sales\SalesCommissionStatementEvent;
use App\Models\Sales\SalesCommissionStatementLine;
use App\Models\Sales\SalesCommissionStatementAdjustment;
use App\Models\Sales\SalesProfile;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionStatementService
{
    public function __construct(
        private readonly CommissionCycleResolver $cycles,
        private readonly CommissionBusinessCalendarService $businessCalendars,
        private readonly DomainEventPublisher $events,
    ) {}

    public function preview(SalesProfile $profile, array $data): array
    {
        return $this->facts($profile, $data, false);
    }

    public function generate(SalesProfile $profile, array $data, string $actorUserId): SalesCommissionStatement
    {
        abort_unless(config('sales.features.statements', false), 409, 'Commission statement generation is not activated.');
        return DB::transaction(function () use ($profile, $data, $actorUserId) {
            $profile = SalesProfile::query()->with('staff')->lockForUpdate()->findOrFail($profile->id);
            $checksum = $this->checksum(['sales_profile_id' => $profile->id, ...$data]);
            $duplicate = SalesCommissionStatement::query()->where('generation_idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->generation_payload_checksum, $checksum), 422,
                    'This statement generation key was already used with different facts.');
                return $duplicate->load('lines');
            }
            $facts = $this->facts($profile, $data, true);
            $existing = SalesCommissionStatement::query()
                ->where('company_id', $profile->company_id)->where('staff_id', $profile->staff_id)
                ->where('cycle_version_id', $facts['cycle']->id)->whereDate('period_start', $facts['period_start'])
                ->whereDate('period_end', $facts['period_end'])->where('status', '!=', 'void')->lockForUpdate()->first();
            abort_if($existing, 422, 'A non-void statement already exists for this Staff/cycle period.');
            $version = (int) SalesCommissionStatement::query()
                ->where('company_id', $profile->company_id)->where('staff_id', $profile->staff_id)
                ->where('cycle_version_id', $facts['cycle']->id)->whereDate('period_start', $facts['period_start'])
                ->whereDate('period_end', $facts['period_end'])->max('version') + 1;
            $statement = SalesCommissionStatement::create([
                'company_id' => $profile->company_id, 'staff_id' => $profile->staff_id, 'sales_profile_id' => $profile->id,
                'cycle_version_id' => $facts['cycle']->id, 'cycle_assignment_id' => $facts['assignment']->id,
                'business_calendar_id' => $facts['calendar']->id,
                'statement_number' => 'SCS-'.$facts['period_end']->format('Ym').'-'.strtoupper(substr((string) Str::uuid(), 0, 8)),
                'version' => $version, 'period_start' => $facts['period_start'], 'period_end' => $facts['period_end'],
                'cutoff_at' => $facts['cutoff_at'], 'timezone' => $facts['cycle']->timezone,
                'finalization_at' => $facts['finalization_at'], 'approval_deadline_at' => $facts['approval_deadline_at'],
                'settlement_at' => $facts['settlement_at'], 'cycle_schedule_snapshot' => $facts['cycle_schedule_snapshot'],
                'cycle_schedule_checksum' => $facts['cycle_schedule_checksum'],
                'payout_currency' => $facts['cycle']->payout_currency,
                'opening_carry_forward_lkr' => $facts['opening_carry_forward_lkr'],
                'gross_earnings_lkr' => $facts['gross_earnings_lkr'],
                'adjustment_credits_lkr' => $facts['adjustment_credits_lkr'],
                'recovery_deductions_lkr' => $facts['recovery_deductions_lkr'],
                'other_deductions_lkr' => $facts['other_deductions_lkr'],
                'contested_hold_lkr' => 0, 'net_payable_lkr' => $facts['net_payable_lkr'],
                'closing_carry_forward_lkr' => $facts['closing_carry_forward_lkr'],
                'status' => 'draft', 'state_version' => 1, 'prepared_by' => $actorUserId, 'prepared_at' => now(),
                'generation_idempotency_key' => $data['idempotency_key'], 'generation_payload_checksum' => $checksum,
            ]);
            foreach ($facts['lines'] as $line) {
                $snapshotJson = json_encode($line['snapshot'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                SalesCommissionStatementLine::create([
                    'statement_id' => $statement->id, 'line_type' => $line['line_type'],
                    'source_type' => $line['source_type'], 'source_id' => $line['source_id'],
                    'description' => $line['description'], 'gross_lkr' => $line['gross_lkr'],
                    'deduction_lkr' => $line['deduction_lkr'], 'net_lkr' => $line['net_lkr'],
                    'line_status' => $line['line_status'], 'hold_code' => $line['hold_code'],
                    'calculation_snapshot' => $line['snapshot'], 'snapshot_checksum' => hash('sha256', $snapshotJson),
                ]);
            }
            $this->recordInitialEvent($statement, $actorUserId, $data['idempotency_key']);
            $this->events->record('sales', $statement->company_id, 'commission_statement', $statement->id,
                'sales.commission.statement_generated', 1, 1, [
                    'staff_id' => $statement->staff_id, 'period_start' => $facts['period_start']->toDateString(),
                    'period_end' => $facts['period_end']->toDateString(), 'net_payable_lkr' => (string) $statement->net_payable_lkr,
                    'closing_carry_forward_lkr' => (string) $statement->closing_carry_forward_lkr,
                ], now(), $data['idempotency_key']);
            return $statement->load('lines');
        });
    }

    public function transition(SalesCommissionStatement $statement, string $toStatus, int $expectedVersion, string $reason, string $key, string $actorUserId): SalesCommissionStatement
    {
        return DB::transaction(function () use ($statement, $toStatus, $expectedVersion, $reason, $key, $actorUserId) {
            $statement = SalesCommissionStatement::query()->lockForUpdate()->findOrFail($statement->id);
            $duplicate = SalesCommissionStatementEvent::query()->where('statement_id', $statement->id)->where('idempotency_key', $key)->first();
            if ($duplicate) return $statement;
            abort_unless($statement->state_version === $expectedVersion, 409, 'Statement changed; refresh before continuing.');
            $allowed = ['draft' => ['pending_approval', 'void'], 'pending_approval' => ['draft', 'approved', 'void'], 'approved' => ['void'], 'partially_paid' => [], 'paid' => [], 'void' => []];
            abort_unless(in_array($toStatus, $allowed[$statement->status] ?? [], true), 422, 'The requested statement transition is not allowed.');
            if ($toStatus === 'pending_approval') {
                abort_unless($statement->finalization_at && now()->gte($statement->finalization_at), 422,
                    'The statement cannot be submitted before its frozen finalization date.');
            }
            if ($toStatus === 'approved') {
                abort_unless($statement->approval_deadline_at && now()->lte($statement->approval_deadline_at), 422,
                    'The frozen approval deadline has passed. Void this statement so its unclaimed sources can enter the next open cycle.');
                abort_if($statement->prepared_by === $actorUserId || $statement->staff_id === $this->staffIdForUser($actorUserId), 403,
                    'A preparer or statement beneficiary cannot approve this statement.');
                abort_if(DB::table('sales_commission_disputes')->where('statement_id', $statement->id)->where('status', 'open')->exists(),
                    422, 'Resolve open statement disputes before approval.');
            }
            $from = $statement->status; $fromVersion = $statement->state_version; $toVersion = $fromVersion + 1;
            $updates = ['status' => $toStatus, 'state_version' => $toVersion];
            if ($toStatus === 'pending_approval') $updates += ['submitted_by' => $actorUserId, 'submitted_at' => now()];
            if ($toStatus === 'approved') $updates += ['approved_by' => $actorUserId, 'approved_at' => now()];
            if ($toStatus === 'void') $updates += ['voided_by' => $actorUserId, 'voided_at' => now(), 'void_reason' => $reason];
            $statement->update($updates);
            SalesCommissionStatementEvent::create([
                'statement_id' => $statement->id, 'from_version' => $fromVersion, 'to_version' => $toVersion,
                'from_status' => $from, 'to_status' => $toStatus, 'reason' => $reason,
                'idempotency_key' => $key, 'actor_user_id' => $actorUserId, 'occurred_at' => now(),
            ]);
            $this->events->record('sales', $statement->company_id, 'commission_statement', $statement->id,
                'sales.commission.statement_'.$toStatus, $toVersion, 1, ['from_status' => $from, 'to_status' => $toStatus], now(), $key);
            return $statement->fresh();
        });
    }

    private function facts(SalesProfile $profile, array $data, bool $lockSources): array
    {
        $profile->loadMissing('staff');
        $periodStart = CarbonImmutable::parse($data['period_start'])->startOfDay();
        $periodEnd = CarbonImmutable::parse($data['period_end'])->endOfDay();
        abort_if($periodEnd->lt($periodStart), 422, 'Statement period is invalid.');
        $resolved = $this->cycles->resolve($profile, $periodEnd);
        $cycle = $resolved['cycle'];
        $periodStart = CarbonImmutable::parse($data['period_start'], $cycle->timezone)->startOfDay();
        $periodEnd = CarbonImmutable::parse($data['period_end'], $cycle->timezone)->endOfDay();
        $schedule = $this->businessCalendars->schedule($cycle, $periodEnd);
        abort_unless($periodStart->isSameDay($schedule['period_start']) && $periodEnd->isSameDay($schedule['period_end']), 422,
            'Statement period must match the resolved commission-cycle earning window.');
        $cutoffAt = Carbon::parse($data['cutoff_at'], $cycle->timezone)->utc();
        abort_unless($cutoffAt->equalTo($schedule['cutoff_at']->utc()), 422,
            'Statement cutoff must match the holiday-adjusted commission-cycle cutoff.');
        if ($lockSources) {
            abort_if(now()->lt($cutoffAt), 422, 'A statement can be previewed before cutoff but cannot be generated until cutoff is reached.');
        }
        $scheduleSnapshot = [
            'cycle_version_id' => $cycle->id, 'cycle_assignment_id' => $resolved['assignment']->id,
            'business_calendar_id' => $schedule['calendar']->id, 'earning_period_rule' => $cycle->earning_period_rule,
            'holiday_rule' => $cycle->holiday_rule, 'timezone' => $cycle->timezone,
            'period_start' => $schedule['period_start']->toDateString(), 'period_end' => $schedule['period_end']->toDateString(),
            'cutoff_at' => $schedule['cutoff_at']->toIso8601String(), 'finalization_at' => $schedule['finalization_at']->toIso8601String(),
            'approval_deadline_at' => $schedule['approval_deadline_at']->toIso8601String(),
            'settlement_at' => $schedule['settlement_at']->toIso8601String(),
        ];
        $scheduleChecksum = hash('sha256', json_encode($scheduleSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $opening = (float) (SalesCommissionStatement::query()->where('company_id', $profile->company_id)
            ->where('staff_id', $profile->staff_id)->whereIn('status', ['approved', 'partially_paid', 'paid'])
            ->whereDate('period_end', '<', $periodStart)->latest('period_end')->value('closing_carry_forward_lkr') ?? 0);
        $decisionQuery = SalesCommissionDecision::query()->where('company_id', $profile->company_id)
            ->where('beneficiary_staff_id', $profile->staff_id)
            ->where('decision_at', '>=', $cycle->effective_from)->where('decision_at', '<=', $cutoffAt)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('sales_commission_statement_lines as line')
                    ->join('sales_commission_statements as claimed_statement', 'claimed_statement.id', '=', 'line.statement_id')
                    ->whereColumn('line.source_id', 'sales_commission_decisions.id')->where('line.source_type', 'commission_decision')
                    ->where('claimed_statement.status', '!=', 'void');
            });
        if ($lockSources) $decisionQuery->lockForUpdate();
        $decisions = $decisionQuery->get();
        $recoveryQuery = SalesCommissionRecoveryDecision::query()
            ->join('sales_commission_recovery_cases as recovery_case', 'recovery_case.id', '=', 'sales_commission_recovery_decisions.recovery_case_id')
            ->where('recovery_case.company_id', $profile->company_id)->where('recovery_case.beneficiary_staff_id', $profile->staff_id)
            ->whereIn('sales_commission_recovery_decisions.decision', ['deduct', 'credit'])
            ->where('sales_commission_recovery_decisions.approved_at', '<=', $cutoffAt)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('sales_commission_statement_lines as line')
                    ->join('sales_commission_statements as claimed_statement', 'claimed_statement.id', '=', 'line.statement_id')
                    ->whereColumn('line.source_id', 'sales_commission_recovery_decisions.id')->where('line.source_type', 'commission_recovery_decision')
                    ->where('claimed_statement.status', '!=', 'void');
            })->select('sales_commission_recovery_decisions.*');
        if ($lockSources) $recoveryQuery->lockForUpdate();
        $recoveries = $recoveryQuery->get();
        $releaseQuery = SalesCommissionHoldRelease::query()
            ->where('company_id', $profile->company_id)->where('beneficiary_staff_id', $profile->staff_id)
            ->where('released_at', '>=', $cycle->effective_from)
            ->where('released_at', '<=', $cutoffAt)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('sales_commission_statement_lines as line')
                    ->join('sales_commission_statements as claimed_statement', 'claimed_statement.id', '=', 'line.statement_id')
                    ->whereColumn('line.source_id', 'sales_commission_hold_releases.id')
                    ->where('line.source_type', 'commission_hold_release')->where('claimed_statement.status', '!=', 'void');
            });
        if ($lockSources) $releaseQuery->lockForUpdate();
        $holdReleases = $releaseQuery->get();
        $holdAdjustmentQuery = SalesCommissionHoldAdjustment::query()
            ->where('company_id', $profile->company_id)->where('beneficiary_staff_id', $profile->staff_id)
            ->where('adjustment_effective_at', '>=', $cycle->effective_from)
            ->where('adjustment_effective_at', '<=', $cutoffAt)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('sales_commission_statement_lines as line')
                    ->join('sales_commission_statements as claimed_statement', 'claimed_statement.id', '=', 'line.statement_id')
                    ->whereColumn('line.source_id', 'sales_commission_hold_adjustments.id')
                    ->where('line.source_type', 'commission_hold_adjustment')->where('claimed_statement.status', '!=', 'void');
            });
        if ($lockSources) $holdAdjustmentQuery->lockForUpdate();
        $holdAdjustments = $holdAdjustmentQuery->get();
        $adjustmentQuery = SalesCommissionStatementAdjustment::query()
            ->where('company_id', $profile->company_id)->where('staff_id', $profile->staff_id)
            ->where('approved_at', '<=', $cutoffAt)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('sales_commission_statement_lines as line')
                    ->join('sales_commission_statements as claimed_statement', 'claimed_statement.id', '=', 'line.statement_id')
                    ->whereColumn('line.source_id', 'sales_commission_statement_adjustments.id')
                    ->where('line.source_type', 'commission_statement_adjustment')->where('claimed_statement.status', '!=', 'void');
            });
        if ($lockSources) $adjustmentQuery->lockForUpdate();
        $statementAdjustments = $adjustmentQuery->get();
        $lines = [];
        if ($opening != 0) $lines[] = $this->line('carry_forward', 'prior_statement_balance', null, 'Opening carry-forward', max(0, $opening), max(0, -$opening), $opening, 'included', null, ['opening_carry_forward_lkr' => $opening]);
        foreach ($decisions as $decision) {
            $included = $decision->status === 'earned'; $amount = $included ? (float) $decision->commission_amount_lkr : 0;
            $lines[] = $this->line($included ? 'earning' : 'hold', 'commission_decision', $decision->id,
                $included ? 'Collection commission earning' : 'Excluded commission hold: '.$decision->hold_code,
                $amount, 0, $amount, $included ? 'included' : 'excluded', $decision->hold_code, $decision->toArray());
        }
        foreach ($recoveries as $recovery) {
            $signed = (float) $recovery->commission_adjustment_lkr;
            $isCredit = $signed > 0;
            $description = match ($recovery->resolution_disposition) {
                'paid_negative_carry_forward' => 'Refund recovery from paid history — negative carry-forward',
                'unpaid_statement_liability_adjustment' => 'Refund recovery against unpaid statement liability',
                'unstatemented_liability_adjustment' => 'Refund recovery before original earning was statemented',
                'post_payment_credit' => 'Approved credit after statement payment',
                default => $isCredit ? 'Approved commission correction credit' : 'Approved commission recovery',
            };
            $lines[] = $this->line($isCredit ? 'adjustment' : 'recovery', 'commission_recovery_decision', $recovery->id,
                $description,
                $isCredit ? $signed : 0, $isCredit ? 0 : abs($signed), $signed, 'included', null, $recovery->toArray());
        }
        foreach ($holdReleases as $release) {
            $amount = (float) $release->commission_amount_lkr;
            $lines[] = $this->line('earning', 'commission_hold_release', $release->id,
                'Released commission hold: '.$release->original_hold_code, $amount, 0, $amount,
                'included', null, $release->toArray());
        }
        foreach ($holdAdjustments as $adjustment) {
            $amount = (float) $adjustment->commission_adjustment_lkr;
            $lines[] = $this->line('adjustment', 'commission_hold_adjustment', $adjustment->id,
                match ($adjustment->adjustment_kind) {
                    'late_finality_policy_entitlement' => 'Approved late finality-policy commission entitlement',
                    'late_acquisition_owner_entitlement' => 'Approved corrected acquisition-owner commission entitlement',
                    default => 'Approved late-attribution commission adjustment',
                },
                $amount, 0, $amount,
                'included', null, $adjustment->toArray());
        }
        foreach ($statementAdjustments as $adjustment) {
            $amount = (float) $adjustment->amount_lkr;
            $lines[] = $this->line('adjustment', 'commission_statement_adjustment', $adjustment->id,
                'Approved commission dispute adjustment', max(0, $amount), max(0, -$amount), $amount,
                'included', null, $adjustment->toArray());
        }
        $gross = collect($lines)->where('line_type', 'earning')->sum('gross_lkr');
        $positiveAdjustments = collect($lines)->where('line_type', 'adjustment')->sum('gross_lkr');
        $deductions = collect($lines)->whereIn('line_type', ['recovery', 'deduction', 'adjustment'])->sum('deduction_lkr');
        $balance = round($opening + $gross + $positiveAdjustments - $deductions, 4);
        return [
            ...$resolved, 'calendar' => $schedule['calendar'], 'period_start' => $periodStart, 'period_end' => $periodEnd,
            'cutoff_at' => $cutoffAt, 'finalization_at' => $schedule['finalization_at']->utc(),
            'approval_deadline_at' => $schedule['approval_deadline_at']->utc(), 'settlement_at' => $schedule['settlement_at']->utc(),
            'cycle_schedule_snapshot' => $scheduleSnapshot, 'cycle_schedule_checksum' => $scheduleChecksum,
            'opening_carry_forward_lkr' => $opening, 'gross_earnings_lkr' => $gross,
            'adjustment_credits_lkr' => $positiveAdjustments,
            'recovery_deductions_lkr' => collect($lines)->where('line_type', 'recovery')->sum('deduction_lkr'),
            'other_deductions_lkr' => collect($lines)->where('line_type', 'adjustment')->sum('deduction_lkr'),
            'net_payable_lkr' => max(0, $balance), 'closing_carry_forward_lkr' => min(0, $balance),
            'lines' => $lines, 'write_performed' => false,
        ];
    }

    private function line(string $type, string $sourceType, ?string $sourceId, string $description, float $gross, float $deduction, float $net, string $status, ?string $hold, array $snapshot): array
    { return ['line_type' => $type, 'source_type' => $sourceType, 'source_id' => $sourceId, 'description' => $description, 'gross_lkr' => round($gross, 4), 'deduction_lkr' => round($deduction, 4), 'net_lkr' => round($net, 4), 'line_status' => $status, 'hold_code' => $hold, 'snapshot' => $snapshot]; }

    private function recordInitialEvent(SalesCommissionStatement $statement, string $actor, string $key): void
    { SalesCommissionStatementEvent::create(['statement_id' => $statement->id, 'from_version' => 0, 'to_version' => 1, 'from_status' => 'none', 'to_status' => 'draft', 'reason' => 'Statement generated from frozen source lines.', 'idempotency_key' => $key, 'actor_user_id' => $actor, 'occurred_at' => now()]); }

    private function staffIdForUser(string $userId): ?string
    { return DB::table('staff')->where('user_id', $userId)->value('id'); }

    private function checksum(array $facts): string
    { return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
}
