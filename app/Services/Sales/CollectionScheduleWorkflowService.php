<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingCollectionSubmission;
use App\Models\Booking\BookingCollectionWorkItem;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentSchedule;
use App\Models\Booking\BookingPaymentScheduleRule;
use App\Models\Booking\BookingPaymentScheduleRevision;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use App\Services\BookingPaymentLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CollectionScheduleWorkflowService
{
    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly BookingPaymentLedgerService $ledger,
    ) {}

    public function previewFutureUnpaidRevision(Booking $booking, array $data): array
    {
        $booking = Booking::query()->findOrFail($booking->id);
        $allSchedules = BookingPaymentSchedule::query()
            ->where('booking_id', $booking->id)
            ->whereNull('superseded_at')->where('status', '!=', 'superseded')
            ->withSum('allocations', 'amount')
            ->orderBy('sequence')
            ->get();
        $reconciliation = $this->reconciliationFor($booking, $data, $allSchedules);

        return [
            ...$reconciliation['summary'],
            'preview_checksum' => $this->previewChecksum($booking, $data, $allSchedules),
            'write_performed' => false,
            'locked_allocated_lines' => $reconciliation['blocked']->map(fn ($row) => $this->scheduleFacts($row))->values(),
            'replaceable_lines' => $reconciliation['replaceable']->map(fn ($row) => $this->scheduleFacts($row))->values(),
        ];
    }

    public function reviseFutureUnpaid(Booking $booking, array $data, string $actorUserId): BookingPaymentScheduleRevision
    {
        return DB::transaction(function () use ($booking, $data, $actorUserId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $checksum = $this->checksum($booking->id, $data);
            $duplicate = BookingPaymentScheduleRevision::query()
                ->where('booking_id', $booking->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->request_payload_checksum, $checksum), 422, 'This revision key was already used with different facts.');
                return $duplicate;
            }

            SalesBookingAttribution::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            if (Schema::hasTable('booking_payment_schedule_rules')) {
                BookingPaymentScheduleRule::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            }

            $allSchedules = BookingPaymentSchedule::query()
                ->where('booking_id', $booking->id)
                ->whereNull('superseded_at')->where('status', '!=', 'superseded')
                ->withSum('allocations', 'amount')
                ->lockForUpdate()
                ->orderBy('sequence')
                ->get();
            $expectedPreviewChecksum = $this->previewChecksum($booking, $data, $allSchedules);
            abort_unless(hash_equals((string) $data['preview_checksum'], $expectedPreviewChecksum), 409, 'The fixed schedule preview is stale; preview the exact proposed revision again.');
            $reconciliation = $this->reconciliationFor($booking, $data, $allSchedules);
            $effectiveAt = $reconciliation['effective_at'];
            $replaceable = $reconciliation['replaceable'];

            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
            $effectiveCollectionProfileId = $attribution
                ? $this->collectionProfileAt($attribution, $effectiveAt)
                : null;
            $effectiveCollectionProfile = $effectiveCollectionProfileId
                ? SalesProfile::query()->whereKey($effectiveCollectionProfileId)
                    ->where('company_id', $attribution?->company_id)
                    ->eligibleAt('collection', $effectiveAt)
                    ->first()
                : null;
            abort_unless($effectiveCollectionProfile, 422, 'A current configured collection-eligible handler is required at the revision boundary.');
            $revisionNumber = (int) BookingPaymentScheduleRevision::query()->where('booking_id', $booking->id)->max('revision_number') + 1;
            $before = $replaceable->map(fn ($row) => $this->scheduleFacts($row))->values()->all();
            $revision = BookingPaymentScheduleRevision::create([
                'company_id' => $attribution?->company_id,
                'booking_id' => $booking->id,
                'revision_number' => $revisionNumber,
                'effective_at' => $effectiveAt,
                'contract_basis' => $reconciliation['summary']['contract_basis'],
                'reconciliation_rule' => $reconciliation['summary']['reconciliation_rule'],
                'contractual_source_amount' => $reconciliation['summary']['contractual_source_amount'],
                'source_currency' => $reconciliation['summary']['source_currency'],
                'retained_source_amount' => $reconciliation['summary']['retained_source_amount'],
                'replacement_source_amount' => $reconciliation['summary']['replacement_source_amount'],
                'reconciliation_amount' => $reconciliation['summary']['reconciliation_amount'],
                'contractual_lkr_amount' => $reconciliation['summary']['contractual_lkr_amount'],
                'retained_lkr_amount' => $reconciliation['summary']['retained_lkr_amount'],
                'replacement_lkr_amount' => $reconciliation['summary']['replacement_lkr_amount'],
                'preview_checksum' => $data['preview_checksum'],
                'before_snapshot' => $before,
                'after_snapshot' => [],
                'reason' => $data['reason'],
                'idempotency_key' => $data['idempotency_key'],
                'request_payload_checksum' => $checksum,
                'approved_by' => $actorUserId,
                'approved_at' => now(),
            ]);

            foreach ($replaceable as $oldSchedule) {
                $oldSchedule->update([
                    'status' => 'superseded',
                    'superseded_at' => now(),
                    'superseded_by_revision_id' => $revision->id,
                ]);
                BookingCollectionWorkItem::query()
                    ->where('booking_payment_schedule_id', $oldSchedule->id)
                    ->whereIn('status', ['open', 'upcoming', 'due', 'overdue'])
                    ->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
                $oldSchedule->delete();
            }

            $nextSequence = (int) BookingPaymentSchedule::withTrashed()->where('booking_id', $booking->id)->max('sequence') + 1;
            $created = collect();
            foreach ($data['items'] as $item) {
                $sourceAmount = round((float) $item['source_amount'], 4);
                $currency = strtoupper((string) $item['source_currency']);
                if ($currency !== 'LKR' && empty($item['lkr_amount'])) {
                    throw ValidationException::withMessages([
                        'items' => ['Every non-LKR installment requires an approved LKR amount.'],
                    ]);
                }
                $lkrAmount = $currency === 'LKR' ? $sourceAmount : round((float) $item['lkr_amount'], 4);
                $schedule = BookingPaymentSchedule::create([
                    'booking_id' => $booking->id,
                    'company_id' => $attribution?->company_id,
                    'sequence' => $nextSequence++,
                    'label' => $item['label'] ?? null,
                    'period_start' => $item['period_start'] ?? null,
                    'period_end' => $item['period_end'] ?? null,
                    'due_date' => $item['due_date'],
                    'amount' => round($sourceAmount, 2),
                    'source_amount' => $sourceAmount,
                    'source_currency' => $currency,
                    'lkr_amount' => $lkrAmount,
                    'schedule_kind' => $item['schedule_kind'],
                    'reconciliation_role' => $item['reconciliation_role'] ?? null,
                    'is_collection_target_eligible' => (bool) $item['is_collection_target_eligible'],
                    'collection_sales_profile_id' => $effectiveCollectionProfileId,
                    'revision_number' => $revisionNumber,
                    'notes' => $item['notes'] ?? null,
                    'created_user_id' => $actorUserId,
                ]);
                $created->push($schedule);
                $this->ensureWorkItem($schedule, (int) $item['reminder_offset_days']);
            }

            DB::table('booking_payment_schedule_revisions')->where('id', $revision->id)->update([
                'after_snapshot' => json_encode($created->map(fn ($row) => $this->scheduleFacts($row))->values()->all(), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $revision->refresh();
            $this->ledger->allocateConfirmedReceiptsToSchedules($booking, $actorUserId);
            $this->events->record('sales', $attribution?->company_id, 'booking_payment_schedule', $booking->id,
                'sales.collection.schedule.revised', $revisionNumber, 1, [
                    'booking_id' => $booking->id, 'revision_id' => $revision->id,
                    'replaced_count' => $replaceable->count(), 'created_count' => $created->count(),
                    'reconciliation' => $reconciliation['summary'],
                ], now(), $data['idempotency_key']);

            return $revision;
        });
    }

    public function submit(Booking $booking, SalesProfile $profile, array $data): BookingCollectionSubmission
    {
        return DB::transaction(function () use ($booking, $profile, $data) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->firstOrFail();
            abort_unless($this->collectionProfileAt($attribution, now()) === $profile->id, 403, 'This booking is outside your current collection portfolio.');
            abort_unless($attribution->company_id === $profile->company_id, 403, 'The booking belongs to another legal entity.');

            $checksum = $this->checksum($booking->id, $data);
            $duplicate = BookingCollectionSubmission::query()
                ->where('booking_id', $booking->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->request_payload_checksum, $checksum), 422, 'This submission key was already used with different facts.');
                return $duplicate;
            }

            if (! empty($data['booking_payment_schedule_id'])) {
                $validSchedule = BookingPaymentSchedule::query()
                    ->where('booking_id', $booking->id)->whereKey($data['booking_payment_schedule_id'])->exists();
                abort_unless($validSchedule, 422, 'The selected installment belongs to another booking or is no longer active.');
            }
            if (! empty($data['evidence_file_id'])) {
                $validEvidence = DB::table('domain_evidence_files')->whereKey($data['evidence_file_id'])
                    ->where('domain', 'sales')->where('company_id', $profile->company_id)->whereNull('deleted_at')
                    ->where('subject_type', 'booking')->where('subject_id', $booking->id)->exists();
                abort_unless($validEvidence, 422, 'Collection evidence must be bound to this booking and Sales legal entity.');
            }

            $submission = BookingCollectionSubmission::create([
                ...$data,
                'company_id' => $profile->company_id,
                'booking_id' => $booking->id,
                'submitted_by_sales_profile_id' => $profile->id,
                'source_currency' => strtoupper((string) $data['source_currency']),
                'status' => 'submitted',
                'request_payload_checksum' => $checksum,
            ]);
            $this->events->record('sales', $profile->company_id, 'booking_collection_submission', $submission->id,
                'sales.collection.submitted', 1, 1, ['booking_id' => $booking->id, 'submission_id' => $submission->id],
                $submission->received_at, $data['idempotency_key']);

            return $submission;
        });
    }

    public function verify(BookingCollectionSubmission $submission, array $data, string $actorUserId): BookingCollectionSubmission
    {
        return DB::transaction(function () use ($submission, $data, $actorUserId) {
            $submission = BookingCollectionSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($submission->status === 'verified') {
                return $submission;
            }
            abort_unless($submission->status === 'submitted', 422, 'Only submitted collection evidence can be verified.');
            $submitterUserId = DB::table('sales_profiles as profile')->join('staff', 'staff.id', '=', 'profile.staff_id')
                ->where('profile.id', $submission->submitted_by_sales_profile_id)->value('staff.user_id');
            abort_if($submitterUserId === $actorUserId, 409, 'Collection evidence must be decided by a user other than its submitter.');

            if ($data['decision'] === 'reject') {
                $submission->update([
                    'status' => 'rejected', 'verification_notes' => $data['verification_notes'],
                    'verified_by' => $actorUserId, 'verified_at' => now(),
                ]);
                return $submission;
            }

            $booking = Booking::query()->findOrFail($submission->booking_id);
            if ($submission->source_currency !== 'LKR' && empty($data['fx_rate_to_lkr'])) {
                throw ValidationException::withMessages([
                    'fx_rate_to_lkr' => ['Accounts must provide the approved LKR rate for a non-LKR collection.'],
                ]);
            }
            $this->ledger->receive($booking, [
                'amount' => round((float) $submission->source_amount, 2),
                'source_amount' => (float) $submission->source_amount,
                'source_currency' => $submission->source_currency,
                'fx_rate_to_lkr' => $data['fx_rate_to_lkr'] ?? null,
                'fx_rate_at' => $data['fx_rate_at'] ?? $submission->received_at,
                'fx_source' => $data['fx_source'] ?? null,
                'payment_method' => $submission->payment_method,
                'payment_stage' => 'account_payment',
                'payment_purpose' => 'booking_payment',
                'reference' => $submission->reference,
                'idempotency_key' => 'collection-submission:'.$submission->id,
                'received_at' => $submission->received_at,
                'received_via' => 'company',
                'notes' => trim('Verified collection submission. '.($submission->staff_notes ?? '')),
            ], $actorUserId);
            $receipt = BookingPaymentReceipt::query()->where('idempotency_key', 'collection-submission:'.$submission->id)->firstOrFail();
            $submission->update([
                'status' => 'verified', 'verification_notes' => $data['verification_notes'] ?? null,
                'verified_by' => $actorUserId, 'verified_at' => now(), 'booking_payment_receipt_id' => $receipt->id,
            ]);
            if ($submission->booking_payment_schedule_id) {
                $scheduleStatus = BookingPaymentSchedule::query()
                    ->whereKey($submission->booking_payment_schedule_id)
                    ->value('status');
                BookingCollectionWorkItem::query()->where('booking_payment_schedule_id', $submission->booking_payment_schedule_id)
                    ->whereIn('status', ['open', 'upcoming', 'due', 'overdue'])
                    ->update($scheduleStatus === 'paid'
                        ? ['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]
                        : ['completed_at' => null, 'updated_at' => now()]);
            }

            return $submission;
        });
    }

    private function ensureWorkItem(BookingPaymentSchedule $schedule, int $reminderDays): void
    {
        BookingCollectionWorkItem::firstOrCreate([
            'idempotency_key' => 'schedule-collection:'.$schedule->id,
        ], [
            'company_id' => $schedule->company_id,
            'booking_id' => $schedule->booking_id,
            'booking_payment_schedule_id' => $schedule->id,
            'assigned_sales_profile_id' => $schedule->collection_sales_profile_id,
            'work_type' => 'collect_installment',
            'status' => 'open',
            'due_at' => $schedule->due_date->endOfDay(),
            'reminder_offset_days' => $reminderDays,
        ]);
    }

    private function scheduleFacts(BookingPaymentSchedule $schedule): array
    {
        return [
            'id' => $schedule->id, 'sequence' => $schedule->sequence, 'label' => $schedule->label,
            'period_start' => $schedule->period_start?->toDateString(), 'period_end' => $schedule->period_end?->toDateString(),
            'due_date' => $schedule->due_date->toDateString(), 'source_amount' => (string) ($schedule->source_amount ?? $schedule->amount),
            'source_currency' => $schedule->source_currency, 'lkr_amount' => $schedule->lkr_amount,
            'schedule_kind' => $schedule->schedule_kind, 'reconciliation_role' => $schedule->reconciliation_role,
            'is_collection_target_eligible' => $schedule->is_collection_target_eligible,
            'collection_sales_profile_id' => $schedule->collection_sales_profile_id,
        ];
    }

    private function checksum(string $bookingId, array $data): string
    {
        $payload = $data;
        unset($payload['idempotency_key'], $payload['preview_checksum']);
        return hash('sha256', json_encode(['booking_id' => $bookingId, 'facts' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function previewChecksum(Booking $booking, array $data, $allSchedules): string
    {
        $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
        $rollingRule = Schema::hasTable('booking_payment_schedule_rules')
            ? BookingPaymentScheduleRule::query()->where('booking_id', $booking->id)->first()
            : null;
        $state = [
            'request_checksum' => $this->checksum($booking->id, $data),
            'attribution' => $attribution ? [
                'id' => $attribution->id, 'version' => $attribution->version, 'status' => $attribution->status,
                'company_id' => $attribution->company_id, 'collection_sales_profile_id' => $attribution->collection_sales_profile_id,
                'contract_value_source' => (string) $attribution->contract_value_source,
                'source_currency' => $attribution->source_currency, 'contract_value_lkr' => (string) $attribution->contract_value_lkr,
            ] : null,
            'rolling_rule' => $rollingRule ? [
                'id' => $rollingRule->id, 'version' => $rollingRule->version, 'status' => $rollingRule->status,
                'last_generated_occurrence' => $rollingRule->last_generated_occurrence,
            ] : null,
            'schedules' => $allSchedules->map(fn (BookingPaymentSchedule $schedule) => [
                'id' => $schedule->id, 'updated_at' => $schedule->updated_at?->toISOString(),
                'due_date' => $schedule->due_date?->toDateString(), 'source_amount' => (string) ($schedule->source_amount ?? $schedule->amount),
                'source_currency' => $schedule->source_currency, 'lkr_amount' => (string) $schedule->lkr_amount,
                'status' => $schedule->status, 'allocated_amount' => (string) ($schedule->allocations_sum_amount ?? 0),
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function reconciliationFor(Booking $booking, array $data, $allSchedules): array
    {
        $effectiveAt = Carbon::parse($data['effective_at'])->startOfDay();
        $replaceable = $allSchedules->filter(fn (BookingPaymentSchedule $schedule) =>
            $schedule->due_date->gte($effectiveAt) && (float) ($schedule->allocations_sum_amount ?? 0) === 0.0
        );
        $blocked = $allSchedules->filter(fn (BookingPaymentSchedule $schedule) =>
            $schedule->due_date->gte($effectiveAt) && (float) ($schedule->allocations_sum_amount ?? 0) > 0.0
        );
        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'effective_at' => ['The selected boundary includes allocated installments. Move the boundary after every allocated line; allocations are never moved by a schedule revision.'],
            ]);
        }
        if ($replaceable->isEmpty()) {
            throw ValidationException::withMessages(['effective_at' => ['No future unpaid schedule lines are available at this boundary.']]);
        }

        $rollingRuleIds = Schema::hasColumn('booking_payment_schedules', 'booking_payment_schedule_rule_id')
            ? $replaceable->pluck('booking_payment_schedule_rule_id')->filter()->unique()->values()->all()
            : [];
        $rollingRules = $rollingRuleIds
            ? BookingPaymentScheduleRule::query()->whereIn('id', $rollingRuleIds)->get()
            : collect();
        if ($rollingRules->contains(fn (BookingPaymentScheduleRule $rule) => $rule->status !== 'ended')) {
            throw ValidationException::withMessages([
                'effective_at' => ['Rule-generated rolling installments cannot be replaced as ad hoc rows. Transition the recurring rule through its governed lifecycle.'],
            ]);
        }

        $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
        $effectiveCollectionProfileId = $attribution ? $this->collectionProfileAt($attribution, $effectiveAt) : null;
        $effectiveCollectionProfile = $effectiveCollectionProfileId
            ? SalesProfile::query()->whereKey($effectiveCollectionProfileId)
                ->where('company_id', $attribution?->company_id)
                ->eligibleAt('collection', $effectiveAt)
                ->first()
            : null;
        abort_unless($effectiveCollectionProfile, 422, 'A current configured collection-eligible handler is required at the revision boundary.');

        $rule = (string) $data['reconciliation_rule'];
        $items = collect($data['items']);
        $replacementSource = round((float) $items->sum('source_amount'), 4);
        if ($rollingRules->isNotEmpty()) {
            abort_unless($data['contract_basis'] === 'open_ended_ended_rule', 422, 'The revision contract basis must identify the ended open-ended rule.');
            $rollingRule = $rollingRules->first();
            $currency = strtoupper((string) $rollingRule->source_currency);
            foreach ($items as $item) {
                abort_unless(strtoupper((string) $item['source_currency']) === $currency, 422, 'Every ended-rule replacement line must retain the rule source currency.');
                abort_unless($currency === 'LKR' || ! empty($item['lkr_amount']), 422, 'Every non-LKR ended-rule replacement line requires its approved LKR amount.');
            }
            $replacedSource = round((float) $replaceable->sum(fn ($row) => (float) ($row->source_amount ?? $row->amount)), 4);
            $replacedLkr = round((float) $replaceable->sum(fn ($row) => (float) ($row->lkr_amount ?? $row->source_amount ?? $row->amount)), 4);
            $replacementLkr = round((float) $items->sum(fn ($item) => $currency === 'LKR' ? (float) $item['source_amount'] : (float) $item['lkr_amount']), 4);
            if ($rule !== 'exact' || $items->contains(fn ($item) => ! empty($item['reconciliation_role']))) {
                throw ValidationException::withMessages(['reconciliation_rule' => ['Ended rolling-rule replacement must use exact reconciliation without a fixed-contract opening, balloon, or residual role.']]);
            }
            if (abs($replacedSource - $replacementSource) > 0.00005) {
                throw ValidationException::withMessages(['items' => ['Replacement rows for an ended rolling rule must reconcile exactly to the future unpaid source amount being replaced.']]);
            }
            if (abs($replacedLkr - $replacementLkr) > 0.00005) {
                throw ValidationException::withMessages(['items' => ['Replacement rows for an ended rolling rule must reconcile exactly to the future unpaid governed LKR amount being replaced.']]);
            }

            return [
                'effective_at' => $effectiveAt,
                'replaceable' => $replaceable,
                'blocked' => $blocked,
                'summary' => [
                    'contract_basis' => 'open_ended_ended_rule', 'reconciliation_rule' => 'exact',
                    'contractual_source_amount' => $replacedSource, 'source_currency' => $currency,
                    'retained_source_amount' => 0.0, 'replacement_source_amount' => $replacementSource,
                    'contractual_lkr_amount' => $replacedLkr, 'retained_lkr_amount' => 0.0,
                    'replacement_lkr_amount' => $replacementLkr,
                    'reconciliation_amount' => 0.0, 'variance_source_amount' => 0.0,
                    'allocations_moved' => false, 'collection_sales_profile_id' => $effectiveCollectionProfile->id,
                ],
            ];
        }

        abort_unless($data['contract_basis'] === 'fixed_term', 422, 'A schedule without an ended rolling rule requires an explicitly reviewed fixed-term contract basis.');
        abort_unless($attribution?->status === 'active', 422, 'An active reviewed Sales attribution is required before revising a fixed-term schedule.');
        abort_unless($attribution->contract_value_source !== null && $attribution->source_currency, 422, 'The frozen contractual source amount and currency are required before revising a fixed-term schedule.');
        $currency = strtoupper((string) $attribution->source_currency);
        $contractualSource = round((float) $attribution->contract_value_source, 4);
        $contractualLkr = round((float) $attribution->contract_value_lkr, 4);
        abort_unless($contractualSource > 0, 422, 'The frozen contractual source amount must be positive.');
        abort_unless($currency === 'LKR' || $contractualLkr > 0, 422, 'The frozen contractual LKR amount is required for a non-LKR fixed-term schedule.');

        $retained = $allSchedules->diff($replaceable);
        foreach ($retained as $schedule) {
            abort_unless(strtoupper((string) $schedule->source_currency) === $currency, 422, 'A retained schedule line has missing or mismatched frozen source currency and requires controlled reconciliation.');
            abort_unless($currency === 'LKR' || $schedule->lkr_amount !== null, 422, 'A retained non-LKR schedule line lacks its frozen LKR amount and requires controlled reconciliation.');
        }
        foreach ($items as $item) {
            abort_unless(strtoupper((string) $item['source_currency']) === $currency, 422, 'Every replacement line must use the frozen contractual source currency.');
            abort_unless($currency === 'LKR' || ! empty($item['lkr_amount']), 422, 'Every non-LKR replacement line requires an approved LKR amount.');
        }

        $retainedSource = round((float) $retained->sum(fn ($row) => (float) ($row->source_amount ?? $row->amount)), 4);
        $retainedLkr = round((float) $retained->sum(fn ($row) => (float) ($row->lkr_amount ?? $row->source_amount ?? $row->amount)), 4);

        // A commercial-value adjustment (§5.28) may reconcile the fixed-term schedule to a
        // revised total rather than the still-frozen attribution snapshot, or — for a full
        // future-value cancellation — to exactly whatever remains retained. Absent an override
        // this reproduces the original frozen-attribution reconciliation byte-for-byte.
        $overrideMode = $data['contractual_override_mode'] ?? null;
        if ($overrideMode === 'fixed') {
            $contractualSource = round((float) $data['contractual_source_override'], 4);
            $contractualLkr = $currency === 'LKR' ? $contractualSource : round((float) $data['contractual_lkr_override'], 4);
            abort_unless($contractualSource > 0, 422, 'The revised contractual source amount must be positive.');
        } elseif ($overrideMode === 'retained_only') {
            $contractualSource = $retainedSource;
            $contractualLkr = $retainedLkr;
        }

        $replacementLkr = round((float) $items->sum(fn ($item) => $currency === 'LKR' ? (float) $item['source_amount'] : (float) $item['lkr_amount']), 4);
        $finalSource = round($retainedSource + $replacementSource, 4);
        $finalLkr = round($retainedLkr + $replacementLkr, 4);
        if (abs($finalSource - $contractualSource) > 0.00005) {
            throw ValidationException::withMessages(['items' => ['Retained plus replacement schedule source amounts must equal the frozen contractual source amount exactly.']]);
        }
        $expectedLkr = $currency === 'LKR' ? $contractualSource : $contractualLkr;
        if (abs($finalLkr - $expectedLkr) > 0.00005) {
            throw ValidationException::withMessages(['items' => ['Retained plus replacement schedule LKR amounts must equal the frozen contractual LKR amount exactly.']]);
        }

        $roleItems = $items->filter(fn ($item) => ! empty($item['reconciliation_role']))->values();
        if ($rule === 'exact') {
            abort_unless($roleItems->isEmpty(), 422, 'Exact reconciliation cannot contain an opening, balloon, or residual line.');
            $reconciliationAmount = 0.0;
        } else {
            abort_unless($roleItems->count() === 1 && $roleItems->first()['reconciliation_role'] === $rule, 422, 'The selected reconciliation rule requires exactly one matching explicitly labelled line.');
            $roleDate = Carbon::parse($roleItems->first()['due_date'])->toDateString();
            $allDates = $retained->pluck('due_date')->map(fn ($date) => Carbon::parse($date)->toDateString())
                ->merge($items->pluck('due_date')->map(fn ($date) => Carbon::parse($date)->toDateString()));
            if ($rule === 'opening') {
                abort_unless($retained->isEmpty() && $roleDate === $allDates->min(), 422, 'An opening line must be the earliest line and cannot be introduced after retained schedule history.');
            } else {
                abort_unless($roleDate === $allDates->max(), 422, 'A balloon or residual line must be the latest dated schedule line.');
            }
            $reconciliationAmount = round((float) $roleItems->first()['source_amount'], 4);
        }

        $confirmedNet = round((float) BookingPaymentReceipt::query()
            ->where('booking_id', $booking->id)->where('finality_status', 'confirmed')
            ->whereIn('payment_purpose', ['booking_payment', 'service_deposit'])
            ->get()->sum(fn ($receipt) => (float) $receipt->amount - (float) $receipt->refunded_amount), 4);
        $allocated = round((float) DB::table('booking_payment_schedule_allocations as allocation')
            ->join('booking_payment_schedules as schedule', 'schedule.id', '=', 'allocation.booking_payment_schedule_id')
            ->where('schedule.booking_id', $booking->id)->whereNull('schedule.deleted_at')
            ->whereNull('allocation.deleted_at')->sum('allocation.amount'), 4);

        return [
            'effective_at' => $effectiveAt,
            'replaceable' => $replaceable,
            'blocked' => $blocked,
            'summary' => [
                'contract_basis' => 'fixed_term', 'reconciliation_rule' => $rule,
                'contractual_source_amount' => $contractualSource, 'source_currency' => $currency,
                'contractual_lkr_amount' => $expectedLkr, 'retained_source_amount' => $retainedSource,
                'replacement_source_amount' => $replacementSource, 'final_source_amount' => $finalSource,
                'retained_lkr_amount' => $retainedLkr, 'replacement_lkr_amount' => $replacementLkr,
                'final_lkr_amount' => $finalLkr, 'reconciliation_amount' => $reconciliationAmount,
                'variance_source_amount' => round($contractualSource - $finalSource, 4),
                'confirmed_net_receipts' => $confirmedNet, 'allocated_receipts' => $allocated,
                'unallocated_receipts' => max(0, round($confirmedNet - $allocated, 4)),
                'allocations_moved' => false, 'collection_sales_profile_id' => $effectiveCollectionProfile->id,
            ],
        ];
    }

    private function collectionProfileAt(SalesBookingAttribution $attribution, Carbon $at): ?string
    {
        $initial = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)->where('event_type', 'confirmed')
            ->orderBy('effective_at')->value('to_sales_profile_id');
        $latest = DB::table('sales_booking_attribution_events')
            ->where('attribution_id', $attribution->id)
            ->where('field_name', 'collection_sales_profile_id')
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')->orderByDesc('version')->first();

        return $latest ? $latest->to_sales_profile_id : ($initial ?: $attribution->collection_sales_profile_id);
    }
}
