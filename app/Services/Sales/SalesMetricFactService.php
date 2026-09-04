<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesMetricFact;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentReceiptComponent;
use App\Models\Booking\BookingPaymentAdjustment;
use App\Models\Booking\BookingCommercialValueAdjustment;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesCommissionHoldRelease;
use App\Models\Sales\SalesCommissionHoldAdjustment;
use App\Models\Sales\SalesCommissionRecoveryCase;
use App\Models\Sales\SalesCommissionRecoveryDecision;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

class SalesMetricFactService
{
    public function record(array $fact): ?SalesMetricFact
    {
        if (empty($fact['company_id']) || empty($fact['sales_profile_id'])) {
            return null;
        }
        $payload = [
            'company_id' => $fact['company_id'], 'sales_profile_id' => $fact['sales_profile_id'],
            'metric_type' => $fact['metric_type'], 'business_classification' => $fact['business_classification'] ?? null,
            'quantity' => $fact['quantity'] ?? 0, 'amount_lkr' => $fact['amount_lkr'] ?? 0,
            'occurred_on' => $fact['occurred_on'], 'occurred_at' => $fact['occurred_at'],
            'source_type' => $fact['source_type'], 'source_id' => $fact['source_id'],
            'source_event' => $fact['source_event'], 'dimensions' => $fact['dimensions'] ?? null,
        ];
        $payload['fact_checksum'] = hash('sha256', CanonicalJson::encode($payload));

        return DB::transaction(function () use ($payload) {
            DB::table('companies')->whereKey($payload['company_id'])->lockForUpdate()->firstOrFail();
            $existing = SalesMetricFact::query()->where([
                'source_type' => $payload['source_type'], 'source_id' => $payload['source_id'],
                'source_event' => $payload['source_event'], 'sales_profile_id' => $payload['sales_profile_id'],
            ])->first();
            abort_if($existing && ! hash_equals($existing->fact_checksum, $payload['fact_checksum']), 409,
                'The metric source was already projected with different facts.');
            if ($existing) return $existing;

            $timezone = config('sales.business_timezone');
            $timezoneIsApproved = is_string($timezone) && $timezone !== ''
                && in_array($timezone, DateTimeZone::listIdentifiers(), true);
            if (! $timezoneIsApproved) {
                abort_if(DB::table('domain_period_locks')->where('domain', 'sales')
                    ->where('company_id', $payload['company_id'])->where('state', 'locked')->exists(), 409,
                    'An approved Sales business timezone is required before writing facts after a period close.');
            } else {
                $occurredAt = CarbonImmutable::parse($payload['occurred_on'], $timezone)->startOfDay()->addHours(12)->utc();
                abort_if(DB::table('domain_period_locks')->where('domain', 'sales')
                    ->where('company_id', $payload['company_id'])->where('state', 'locked')
                    ->where('period_start', '<=', $occurredAt)->where('period_end', '>', $occurredAt)->exists(), 409,
                    'The Sales performance period is locked; use the governed reopen and rebuild workflow.');
            }
            return SalesMetricFact::create($payload);
        }, 3);
    }

    public function projectBookingConfirmation(SalesBookingAttribution $attribution): void
    {
        if ($attribution->status !== 'active' || $attribution->business_classification !== 'new_business') return;
        $this->record([
            'company_id' => $attribution->company_id,
            'sales_profile_id' => $attribution->acquisition_sales_profile_id,
            'metric_type' => 'new_sales', 'business_classification' => 'new_business',
            'quantity' => 1, 'amount_lkr' => $attribution->contract_value_lkr ?? 0,
            'occurred_on' => $attribution->secured_at->toDateString(), 'occurred_at' => $attribution->secured_at,
            'source_type' => 'booking_attribution', 'source_id' => $attribution->id, 'source_event' => 'confirmed',
            'dimensions' => ['booking_id' => $attribution->booking_id, 'new_customer_status' => $attribution->new_customer_status],
        ]);
        if ($attribution->new_customer_status === 'new') {
            $this->record([
                'company_id' => $attribution->company_id, 'sales_profile_id' => $attribution->acquisition_sales_profile_id,
                'metric_type' => 'new_customer', 'business_classification' => 'new_business', 'quantity' => 1,
                'amount_lkr' => 0, 'occurred_on' => $attribution->secured_at->toDateString(), 'occurred_at' => $attribution->secured_at,
                'source_type' => 'booking_attribution', 'source_id' => $attribution->id, 'source_event' => 'new_customer',
                'dimensions' => ['booking_id' => $attribution->booking_id, 'customer_id' => $attribution->customer_id],
            ]);
        }
    }

    public function projectConfirmedCollection(BookingPaymentReceipt $receipt, BookingPaymentReceiptComponent $component): void
    {
        if ($receipt->finality_status !== 'confirmed' || ! $component->is_collection_target_eligible) return;
        $attribution = SalesBookingAttribution::query()->where('booking_id', $receipt->booking_id)->first();
        if (! $attribution || $component->lkr_amount === null) return;
        $classification = $attribution->business_classification === 'new_business' ? 'new_business' : 'recurring_business';
        $this->record([
            'company_id' => $attribution->company_id, 'sales_profile_id' => $attribution->collection_sales_profile_id,
            'metric_type' => 'eligible_collection', 'business_classification' => $classification,
            'quantity' => 1, 'amount_lkr' => $component->lkr_amount,
            'occurred_on' => $receipt->received_at->toDateString(), 'occurred_at' => $receipt->received_at,
            'source_type' => 'receipt_component', 'source_id' => $component->id, 'source_event' => 'confirmed',
            'dimensions' => ['booking_id' => $receipt->booking_id, 'receipt_id' => $receipt->id, 'payment_purpose' => $receipt->payment_purpose],
        ]);
    }

    public function projectCollectionAdjustment(BookingPaymentAdjustment $adjustment): void
    {
        if ($adjustment->impact_dimension !== 'cash_receipt' || $adjustment->lkr_amount === null) return;
        $attribution = SalesBookingAttribution::query()->where('booking_id', $adjustment->booking_id)->first();
        if (! $attribution) return;
        $classification = $attribution->business_classification === 'new_business' ? 'new_business' : 'recurring_business';
        $sign = $adjustment->direction === 'decrease' ? -1 : 1;
        // §20: "Genuine later event is a signed flow in its occurrence period and uses
        // original receipt-credit handler" — credit/debit whoever was actually recorded
        // as the collection handler when the receipt was confirmed, not the attribution's
        // current handler, which a later portfolio transfer may have since changed.
        $originalHandlerId = $adjustment->receipt_component_id
            ? SalesMetricFact::query()
                ->where('source_type', 'receipt_component')
                ->where('source_id', $adjustment->receipt_component_id)
                ->where('source_event', 'confirmed')
                ->value('sales_profile_id')
            : null;
        $this->record([
            'company_id' => $attribution->company_id, 'sales_profile_id' => $originalHandlerId ?? $attribution->collection_sales_profile_id,
            'metric_type' => 'eligible_collection', 'business_classification' => $classification,
            'quantity' => 0, 'amount_lkr' => $sign * (float) $adjustment->lkr_amount,
            'occurred_on' => $adjustment->adjustment_effective_at->toDateString(), 'occurred_at' => $adjustment->adjustment_effective_at,
            'source_type' => 'payment_adjustment', 'source_id' => $adjustment->id, 'source_event' => $adjustment->direction,
            'dimensions' => ['booking_id' => $adjustment->booking_id, 'receipt_id' => $adjustment->receipt_id, 'adjustment_type' => $adjustment->adjustment_type, 'original_handler_resolved' => $originalHandlerId !== null],
        ]);
    }

    public function projectCommission(SalesCommissionDecision $decision): void
    {
        if (! in_array($decision->status, ['earned', 'shadow_earned'], true) || ! $decision->beneficiary_sales_profile_id) return;
        $attribution = SalesBookingAttribution::query()->find($decision->booking_attribution_id);
        $classification = $attribution?->business_classification === 'new_business' ? 'new_business' : 'recurring_business';
        $this->record([
            'company_id' => $decision->company_id, 'sales_profile_id' => $decision->beneficiary_sales_profile_id,
            'metric_type' => 'commission_earned', 'business_classification' => $classification,
            'quantity' => 1, 'amount_lkr' => $decision->commission_amount_lkr,
            'occurred_on' => ($decision->earned_at ?? $decision->decision_at)->toDateString(), 'occurred_at' => $decision->earned_at ?? $decision->decision_at,
            'source_type' => 'commission_decision', 'source_id' => $decision->id, 'source_event' => 'earned',
            'dimensions' => ['booking_id' => $decision->booking_id, 'receipt_id' => $decision->receipt_id,
                'collection_cohort' => $decision->collection_cohort, 'commission_category' => $decision->commission_category],
        ]);
    }

    public function projectCommissionHoldRelease(SalesCommissionDecision $decision, SalesCommissionHoldRelease $release): void
    {
        $attribution = SalesBookingAttribution::query()->find($decision->booking_attribution_id);
        $this->record([
            'company_id' => $release->company_id, 'sales_profile_id' => $release->beneficiary_sales_profile_id,
            'metric_type' => 'commission_earned',
            'business_classification' => $attribution?->business_classification === 'new_business' ? 'new_business' : 'recurring_business',
            'quantity' => 1, 'amount_lkr' => $release->commission_amount_lkr,
            'occurred_on' => $release->released_at->toDateString(), 'occurred_at' => $release->released_at,
            'source_type' => 'commission_hold_release', 'source_id' => $release->id, 'source_event' => 'released',
            'dimensions' => ['commission_decision_id' => $decision->id, 'booking_id' => $decision->booking_id,
                'receipt_id' => $decision->receipt_id, 'original_hold_code' => $release->original_hold_code,
                'collection_cohort' => $decision->collection_cohort, 'commission_category' => $decision->commission_category],
        ]);
    }

    public function projectCommissionHoldAdjustment(SalesCommissionDecision $decision, SalesCommissionHoldAdjustment $adjustment): void
    {
        $attribution = SalesBookingAttribution::query()->find($adjustment->source_attribution_id);
        $this->record([
            'company_id' => $adjustment->company_id,
            'sales_profile_id' => $adjustment->beneficiary_sales_profile_id,
            'metric_type' => 'commission_earned',
            'business_classification' => $attribution?->business_classification === 'new_business' ? 'new_business' : 'recurring_business',
            'quantity' => 0,
            'amount_lkr' => $adjustment->commission_adjustment_lkr,
            'occurred_on' => $adjustment->adjustment_effective_at->toDateString(),
            'occurred_at' => $adjustment->adjustment_effective_at,
            'source_type' => 'commission_hold_adjustment',
            'source_id' => $adjustment->id,
            'source_event' => $adjustment->adjustment_kind.'_approved',
            'dimensions' => ['commission_decision_id' => $decision->id, 'booking_id' => $decision->booking_id,
                'source_attribution_event_id' => $adjustment->source_attribution_event_id,
                'beneficiary_attribution_event_id' => $adjustment->beneficiary_attribution_event_id,
                'finality_policy_id' => $adjustment->finality_policy_id,
                'receipt_finality_event_id' => $adjustment->receipt_finality_event_id,
                'collection_cohort' => $decision->collection_cohort, 'commission_category' => $decision->commission_category,
                'original_hold_code' => $adjustment->original_hold_code],
        ]);
    }

    public function projectCommissionRecovery(SalesCommissionRecoveryCase $case, SalesCommissionRecoveryDecision $recovery): void
    {
        if (! in_array($recovery->decision, ['deduct', 'credit'], true)) return;
        $earning = SalesCommissionDecision::query()->findOrFail($case->commission_decision_id);
        $attributionId = $earning->booking_attribution_id;
        if ($case->entitlement_source_type === 'commission_hold_adjustment') {
            $attributionId = SalesCommissionHoldAdjustment::query()
                ->whereKey($case->entitlement_source_id)
                ->value('source_attribution_id');
        }
        $attribution = SalesBookingAttribution::query()->find($attributionId);
        $adjustment = BookingPaymentAdjustment::query()->findOrFail($case->payment_adjustment_id);
        $this->record([
            'company_id' => $case->company_id, 'sales_profile_id' => $case->beneficiary_sales_profile_id,
            'metric_type' => 'commission_earned', 'business_classification' => $attribution?->business_classification === 'new_business' ? 'new_business' : 'recurring_business',
            'quantity' => 0, 'amount_lkr' => $recovery->commission_adjustment_lkr,
            'occurred_on' => $adjustment->adjustment_effective_at->toDateString(), 'occurred_at' => $adjustment->adjustment_effective_at,
            'source_type' => 'commission_recovery', 'source_id' => $recovery->id, 'source_event' => "{$recovery->decision}_approved",
              'dimensions' => ['commission_decision_id' => $earning->id, 'booking_id' => $earning->booking_id,
                  'payment_adjustment_id' => $adjustment->id, 'recovery_kind' => $case->recovery_kind,
                  'entitlement_source_type' => $case->entitlement_source_type,
                  'entitlement_source_id' => $case->entitlement_source_id,
                  'resolution_disposition' => $recovery->resolution_disposition,
                  'collection_cohort' => $earning->collection_cohort, 'commission_category' => $earning->commission_category,
                  'source_statement_id' => $recovery->source_statement_id],
          ]);
    }

    public function projectCommercialValueAdjustment(BookingCommercialValueAdjustment $adjustment): void
    {
        // §5.28: "Cancellations and reductions after confirmation remain visible as dated
        // New Sales adjustments" — a signed fact against the frozen acquisition owner that
        // never edits the immutable gross snapshot on sales_booking_attributions itself.
        if (! $adjustment->counts_as_new_sales_adjustment || ! $adjustment->acquisition_sales_profile_id) return;
        abort_if($adjustment->delta_lkr_amount === null, 409,
            'A governed LKR value is required before a commercial adjustment can affect New Sales.');
        $this->record([
            'company_id' => $adjustment->company_id, 'sales_profile_id' => $adjustment->acquisition_sales_profile_id,
            'metric_type' => 'new_sales_adjustment', 'business_classification' => 'new_business',
            'quantity' => 0, 'amount_lkr' => (float) $adjustment->delta_lkr_amount,
            'occurred_on' => $adjustment->effective_at->toDateString(), 'occurred_at' => $adjustment->effective_at,
            'source_type' => 'commercial_value_adjustment', 'source_id' => $adjustment->id,
            'source_event' => $adjustment->adjustment_type,
            'dimensions' => ['booking_id' => $adjustment->booking_id, 'attribution_id' => $adjustment->attribution_id,
                'delta_source_amount' => (string) $adjustment->delta_source_amount, 'source_currency' => $adjustment->source_currency,
                'schedule_revision_id' => $adjustment->schedule_revision_id],
        ]);
    }

    public function projectAcquisitionOwnerCorrection(SalesBookingAttribution $attribution, string $fromProfileId, string $toProfileId, int $version): void
    {
        if ($attribution->business_classification !== 'new_business') return;
        $base = ['company_id' => $attribution->company_id, 'metric_type' => 'new_sales', 'business_classification' => 'new_business',
            'occurred_on' => $attribution->secured_at->toDateString(), 'occurred_at' => now(), 'source_type' => 'attribution_correction',
            'source_id' => $attribution->id, 'dimensions' => ['booking_id' => $attribution->booking_id, 'attribution_version' => $version]];
        $this->record($base + ['sales_profile_id' => $fromProfileId, 'quantity' => -1, 'amount_lkr' => -(float) $attribution->contract_value_lkr, 'source_event' => "owner_debit_v{$version}"]);
        $this->record($base + ['sales_profile_id' => $toProfileId, 'quantity' => 1, 'amount_lkr' => (float) $attribution->contract_value_lkr, 'source_event' => "owner_credit_v{$version}"]);
        if ($attribution->new_customer_status === 'new') {
            $customerBase = array_merge($base, ['metric_type' => 'new_customer', 'amount_lkr' => 0]);
            $this->record($customerBase + ['sales_profile_id' => $fromProfileId, 'quantity' => -1, 'source_event' => "customer_debit_v{$version}"]);
            $this->record($customerBase + ['sales_profile_id' => $toProfileId, 'quantity' => 1, 'source_event' => "customer_credit_v{$version}"]);
        }
    }
}
