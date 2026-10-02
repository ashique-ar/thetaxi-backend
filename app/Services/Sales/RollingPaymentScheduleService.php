<?php

namespace App\Services\Sales;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingCollectionWorkItem;
use App\Models\Booking\BookingPaymentSchedule;
use App\Models\Booking\BookingPaymentScheduleRule;
use App\Models\Booking\BookingPaymentScheduleRuleEvent;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesProfile;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RollingPaymentScheduleService
{
    private const HORIZON_MONTHS = 12;

    public function __construct(private readonly SalesPolicySettingsService $policySettings) {}

    public function createRule(Booking $booking, array $data, string $actorUserId): array
    {
        $companyId = SalesBookingAttribution::query()->where('booking_id', $booking->id)->value('company_id');
        abort_unless($companyId, 422, 'Resolve Sales attribution and legal entity before creating a rolling rule.');
        $this->assertEnabled((string) $companyId);

        return DB::transaction(function () use ($booking, $data, $actorUserId): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $checksum = $this->checksum($booking->id, $data);
            $duplicate = BookingPaymentScheduleRule::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($duplicate) {
                abort_unless(
                    (string) $duplicate->booking_id === (string) $booking->id
                    && hash_equals($duplicate->request_payload_checksum, $checksum),
                    422,
                    'This rolling-rule idempotency key was already used with different facts.',
                );

                return $this->result($duplicate, 0);
            }
            abort_if(
                BookingPaymentScheduleRule::query()->where('booking_id', $booking->id)->exists(),
                409,
                'This booking already has a rolling payment schedule rule.',
            );
            abort_if(
                BookingPaymentSchedule::withTrashed()->where('booking_id', $booking->id)
                    ->where('schedule_kind', 'rolling')->whereNull('booking_payment_schedule_rule_id')->exists(),
                409,
                'Unlinked rolling schedule rows require reviewed reconciliation before a rule can be created.',
            );
            abort_if(
                BookingPaymentSchedule::withTrashed()->where('booking_id', $booking->id)
                    ->where('schedule_kind', '!=', 'initial')->exists(),
                409,
                'Existing non-initial schedule rows require reviewed reconciliation before an open-ended rule can be created.',
            );

            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            abort_unless($attribution?->company_id, 422, 'Resolve Sales attribution and legal entity before creating a rolling rule.');
            abort_unless($attribution->status === 'active', 422, 'The booking attribution must be active before creating a rolling rule.');
            abort_unless($attribution->commission_category === 'long_term', 422, 'Rolling rules are limited to reviewed long-term bookings.');
            abort_unless($attribution->collection_sales_profile_id, 422, 'Assign the current collection handler before creating a rolling rule.');

            $currency = strtoupper((string) $data['source_currency']);
            $monthlyAmount = round((float) $data['monthly_source_amount'], 4);
            abort_unless($currency === strtoupper((string) $attribution->source_currency), 422, 'The rule currency must match the frozen booking attribution currency.');
            abort_unless(abs($monthlyAmount - (float) $attribution->contract_value_source) < 0.0001, 422, 'The monthly run rate must match the reviewed open-ended attribution value.');
            if ($currency !== 'LKR') {
                abort_unless($attribution->contract_value_lkr !== null, 422, 'The reviewed LKR monthly value is required before a non-LKR rolling rule can be created.');
            }

            $handler = SalesProfile::query()->whereKey($attribution->collection_sales_profile_id)
                ->where('company_id', $attribution->company_id)
                ->eligibleAt('collection', now())
                ->first();
            abort_unless($handler, 422, 'The current handler is not an active configured collection-eligible Sales Profile.');

            $rule = BookingPaymentScheduleRule::create([
                'company_id' => $attribution->company_id,
                'booking_id' => $booking->id,
                'contract_basis' => 'open_ended',
                'frequency' => 'monthly',
                'frequency_months' => 1,
                'anchor_date' => $data['anchor_date'],
                'source_amount' => $monthlyAmount,
                'source_currency' => $currency,
                'lkr_amount' => $currency === 'LKR' ? $monthlyAmount : $attribution->contract_value_lkr,
                'is_collection_target_eligible' => (bool) $data['is_collection_target_eligible'],
                'reminder_offset_days' => (int) $data['reminder_offset_days'],
                'horizon_months' => self::HORIZON_MONTHS,
                'status' => 'active',
                'version' => 1,
                'last_generated_occurrence' => 0,
                'reason' => $data['reason'],
                'idempotency_key' => $data['idempotency_key'],
                'request_payload_checksum' => $checksum,
                'created_by' => $actorUserId,
            ]);
            $this->recordEvent($rule, 'created', null, 'active', Carbon::parse($data['anchor_date']), $data['reason'], [
                'contract_basis' => 'open_ended',
                'frequency' => 'monthly',
                'horizon_months' => self::HORIZON_MONTHS,
            ], 'rolling-rule-created:'.$rule->id, $actorUserId, 1);
            $generated = $this->extendLocked($rule, now(), $handler->id, $actorUserId);

            return $this->result($rule->refresh(), $generated);
        });
    }

    public function extendRule(BookingPaymentScheduleRule $rule, CarbonInterface $asOf, ?string $actorUserId = null, bool $dryRun = false): array
    {
        $this->assertEnabled((string) $rule->company_id);

        return DB::transaction(function () use ($rule, $asOf, $actorUserId, $dryRun): array {
            $rule = BookingPaymentScheduleRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($rule->status !== 'active') {
                return $this->result($rule, 0);
            }
            Booking::query()->whereKey($rule->booking_id)->lockForUpdate()->firstOrFail();
            $attribution = SalesBookingAttribution::query()->where('booking_id', $rule->booking_id)->first();
            $handler = $attribution?->collection_sales_profile_id
                ? SalesProfile::query()->whereKey($attribution->collection_sales_profile_id)
                    ->where('company_id', $rule->company_id)->eligibleAt('collection', $asOf)->first()
                : null;
            if (! $handler) {
                throw ValidationException::withMessages([
                    'collection_handler' => ['A current configured collection-eligible handler is required to extend the rolling horizon.'],
                ]);
            }

            $targetOccurrence = $this->targetOccurrenceFor($rule, $asOf);
            $generated = max(0, $targetOccurrence - (int) $rule->last_generated_occurrence);
            if ($dryRun || $generated === 0) {
                return [...$this->result($rule, $generated), 'dry_run' => $dryRun];
            }

            $actual = $this->extendLocked($rule, $asOf, $handler->id, $actorUserId);

            return $this->result($rule->refresh(), $actual);
        });
    }

    public function transitionRule(Booking $booking, array $data, string $actorUserId): array
    {
        $companyId = SalesBookingAttribution::query()->where('booking_id', $booking->id)->value('company_id');
        abort_unless($companyId, 422, 'Resolve Sales attribution and legal entity before changing a rolling rule.');
        $this->assertEnabled((string) $companyId);

        return DB::transaction(function () use ($booking, $data, $actorUserId): array {
            Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $rule = BookingPaymentScheduleRule::query()->where('booking_id', $booking->id)->lockForUpdate()->firstOrFail();
            $requestChecksum = $this->checksum($booking->id, $data);
            $duplicate = BookingPaymentScheduleRuleEvent::query()
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->request_payload_checksum, $requestChecksum), 422, 'This rule-transition key was already used with different facts.');

                return $this->result($rule, 0);
            }
            abort_unless((int) $data['expected_version'] === (int) $rule->version, 409, 'The rolling rule changed; reload before recording this transition.');
            $toStatus = match ($data['action']) {
                'pause' => 'paused',
                'resume' => 'active',
                'end' => 'ended',
            };
            $allowed = match ($rule->status) {
                'active' => ['paused', 'ended'],
                'paused' => ['active', 'ended'],
                default => [],
            };
            abort_unless(in_array($toStatus, $allowed, true), 422, 'The requested rolling-rule transition is not allowed from its current state.');

            $fromStatus = $rule->status;
            $nextVersion = (int) $rule->version + 1;
            $effectiveAt = Carbon::parse($data['effective_at']);
            $rule->update(['status' => $toStatus, 'version' => $nextVersion, 'updated_by' => $actorUserId]);
            $this->recordEvent(
                $rule,
                'rule_'.$data['action'],
                $fromStatus,
                $toStatus,
                $effectiveAt,
                $data['reason'],
                ['future_generated_installments_retained' => true],
                $data['idempotency_key'],
                $actorUserId,
                $nextVersion,
                $requestChecksum,
            );

            $generated = 0;
            if ($toStatus === 'active') {
                $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->first();
                $handler = $attribution?->collection_sales_profile_id
                    ? SalesProfile::query()->whereKey($attribution->collection_sales_profile_id)
                        ->where('company_id', $rule->company_id)->eligibleAt('collection', now())->first()
                    : null;
                abort_unless($handler, 422, 'A current configured collection-eligible handler is required to resume this rule.');
                $generated = $this->extendLocked($rule->refresh(), now(), $handler->id, $actorUserId);
            }

            return $this->result($rule->refresh(), $generated);
        });
    }

    private function extendLocked(
        BookingPaymentScheduleRule $rule,
        CarbonInterface $asOf,
        string $handlerProfileId,
        ?string $actorUserId,
    ): int {
        $targetOccurrence = $this->targetOccurrenceFor($rule, $asOf);
        $nextOccurrence = (int) $rule->last_generated_occurrence + 1;
        if ($nextOccurrence > $targetOccurrence) {
            return 0;
        }

        $nextSequence = (int) BookingPaymentSchedule::withTrashed()
            ->where('booking_id', $rule->booking_id)->max('sequence') + 1;
        $generated = 0;
        $lastDueDate = null;
        for ($occurrence = $nextOccurrence; $occurrence <= $targetOccurrence; $occurrence++) {
            $dueDate = $rule->anchor_date->copy()->addMonthsNoOverflow($occurrence - 1);
            if (BookingPaymentSchedule::withTrashed()
                ->where('booking_payment_schedule_rule_id', $rule->id)
                ->where('rule_occurrence_number', $occurrence)->exists()) {
                throw ValidationException::withMessages([
                    'rolling_rule' => ["Occurrence {$occurrence} already exists outside the rule projection state; reconcile it before retrying."],
                ]);
            }

            $schedule = BookingPaymentSchedule::create([
                'booking_id' => $rule->booking_id,
                'company_id' => $rule->company_id,
                'sequence' => $nextSequence++,
                'label' => "Rolling installment {$occurrence}",
                'due_date' => $dueDate->toDateString(),
                'amount' => round((float) $rule->source_amount, 2),
                'source_amount' => $rule->source_amount,
                'source_currency' => $rule->source_currency,
                'lkr_amount' => $rule->lkr_amount,
                'schedule_kind' => 'rolling',
                'is_collection_target_eligible' => $rule->is_collection_target_eligible,
                'collection_sales_profile_id' => $handlerProfileId,
                'revision_number' => 1,
                'booking_payment_schedule_rule_id' => $rule->id,
                'rule_occurrence_number' => $occurrence,
                'created_user_id' => $actorUserId,
            ]);
            BookingCollectionWorkItem::firstOrCreate([
                'idempotency_key' => 'schedule-collection:'.$schedule->id,
            ], [
                'company_id' => $rule->company_id,
                'booking_id' => $rule->booking_id,
                'booking_payment_schedule_id' => $schedule->id,
                'assigned_sales_profile_id' => $handlerProfileId,
                'work_type' => 'collect_installment',
                'status' => 'open',
                'due_at' => $dueDate->copy()->endOfDay(),
                'reminder_offset_days' => $rule->reminder_offset_days,
            ]);
            $generated++;
            $lastDueDate = $dueDate;
        }

        $nextVersion = (int) $rule->version + 1;
        $eventKey = 'rolling-horizon:'.$rule->id.':'.$targetOccurrence;
        $rule->update([
            'version' => $nextVersion,
            'last_generated_occurrence' => $targetOccurrence,
            'last_generated_through' => $lastDueDate,
            'updated_by' => $actorUserId,
        ]);
        $this->recordEvent($rule, 'horizon_extended', 'active', 'active', $asOf, null, [
            'from_occurrence' => $nextOccurrence,
            'through_occurrence' => $targetOccurrence,
            'generated_count' => $generated,
            'generated_through' => $lastDueDate?->toDateString(),
        ], $eventKey, $actorUserId, $nextVersion);

        return $generated;
    }

    public function targetOccurrenceFor(BookingPaymentScheduleRule $rule, CarbonInterface $asOf): int
    {
        $anchor = $rule->anchor_date->copy()->startOfDay();
        $today = $asOf->copy()->startOfDay();
        if ($today->lte($anchor)) {
            return self::HORIZON_MONTHS;
        }
        $elapsed = max(0, (int) $anchor->diffInMonths($today));
        $firstCurrent = $anchor->copy()->addMonthsNoOverflow($elapsed);
        if ($firstCurrent->lt($today)) {
            $elapsed++;
        }

        return $elapsed + self::HORIZON_MONTHS;
    }

    private function recordEvent(
        BookingPaymentScheduleRule $rule,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        CarbonInterface $effectiveAt,
        ?string $reason,
        array $metadata,
        string $idempotencyKey,
        ?string $actorUserId,
        int $version,
        ?string $requestChecksum = null,
    ): void {
        $facts = [
            'rule_id' => $rule->id, 'version' => $version, 'event_type' => $eventType,
            'from_status' => $fromStatus, 'to_status' => $toStatus,
            'effective_at' => $effectiveAt->toISOString(), 'reason' => $reason, 'metadata' => $metadata,
        ];
        BookingPaymentScheduleRuleEvent::create([
            'booking_payment_schedule_rule_id' => $rule->id,
            'version' => $version,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'effective_at' => $effectiveAt,
            'reason' => $reason,
            'metadata' => $metadata,
            'idempotency_key' => $idempotencyKey,
            'request_payload_checksum' => $requestChecksum ?? hash('sha256', json_encode(
                ['idempotency_key' => $idempotencyKey, 'facts' => $facts],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
            'event_checksum' => hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'actor_type' => $actorUserId ? 'staff_user' : 'system_scheduler',
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function result(BookingPaymentScheduleRule $rule, int $generated): array
    {
        return [
            'id' => $rule->id,
            'booking_id' => $rule->booking_id,
            'company_id' => $rule->company_id,
            'contract_basis' => $rule->contract_basis,
            'frequency' => $rule->frequency,
            'anchor_date' => $rule->anchor_date?->toDateString(),
            'monthly_source_amount' => $rule->source_amount,
            'source_currency' => $rule->source_currency,
            'monthly_lkr_amount' => $rule->lkr_amount,
            'is_collection_target_eligible' => $rule->is_collection_target_eligible,
            'reminder_offset_days' => $rule->reminder_offset_days,
            'horizon_months' => $rule->horizon_months,
            'status' => $rule->status,
            'version' => $rule->version,
            'last_generated_occurrence' => $rule->last_generated_occurrence,
            'last_generated_through' => $rule->last_generated_through?->toDateString(),
            'generated_count' => $generated,
            'lifetime_contract_value' => null,
            'lifetime_contract_value_state' => 'not_applicable_open_ended',
        ];
    }

    private function checksum(string $bookingId, array $data): string
    {
        unset($data['idempotency_key']);

        return hash('sha256', json_encode(
            ['booking_id' => $bookingId, 'facts' => $data],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function assertEnabled(string $companyId): void
    {
        abort_unless($this->policySettings->featureEnabled($companyId, 'rolling_payment_schedules'), 503,
            'Rolling payment schedules are not enabled for this legal entity.');
        abort_unless(
            Schema::hasTable('booking_payment_schedule_rules')
            && Schema::hasColumn('booking_payment_schedules', 'booking_payment_schedule_rule_id'),
            503,
            'Rolling payment schedule schema is not deployed.',
        );
    }
}
