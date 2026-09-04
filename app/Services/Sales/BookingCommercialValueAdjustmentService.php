<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingCommercialValueAdjustment;
use App\Models\Sales\SalesBookingAttribution;
use App\Support\Foundation\CanonicalJson;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * §5.28: same-contract increases/decreases/extensions, cancellation fees, and
 * cancellations create dated commercial-value adjustments attributed to the
 * original (frozen) acquisition owner. This never edits the frozen New Sales
 * gross snapshot on `sales_booking_attributions`; it appends an immutable
 * ledger row and reuses CollectionScheduleWorkflowService to revise or retire
 * future-unpaid schedule lines against the resulting effective total.
 */
class BookingCommercialValueAdjustmentService
{
    private const NEW_SALES_ADJUSTMENT_TYPES = ['increase', 'decrease', 'cancellation'];
    private const VALID_TYPES = ['increase', 'decrease', 'extension', 'cancellation_fee', 'cancellation'];

    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesMetricFactService $metricFacts,
        private readonly CollectionScheduleWorkflowService $scheduleWorkflow,
    ) {}

    public function preview(Booking $booking, array $data): array
    {
        $booking = Booking::query()->findOrFail($booking->id);
        $context = $this->resolveContext($booking, $data);
        $scheduleData = $this->scheduleRevisionPayload($data, $context);
        $schedulePreview = $this->scheduleWorkflow->previewFutureUnpaidRevision($booking, $scheduleData);

        return $this->previewResponse($data, $context, $schedulePreview);
    }

    public function apply(Booking $booking, array $data, string $actorUserId): BookingCommercialValueAdjustment
    {
        return DB::transaction(function () use ($booking, $data, $actorUserId) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $checksum = $this->payloadChecksum($booking->id, $data);
            $duplicate = BookingCommercialValueAdjustment::query()
                ->where('booking_id', $booking->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($duplicate) {
                if (! hash_equals((string) $duplicate->request_payload_checksum, $checksum)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This commercial value adjustment key was already used with different facts.'],
                    ]);
                }

                return $duplicate;
            }

            $attribution = SalesBookingAttribution::query()
                ->where('booking_id', $booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($attribution->status === 'active', 422,
                'An active reviewed Sales attribution is required before a commercial value adjustment.');
            abort_unless($attribution->acquisition_sales_profile_id, 422,
                'A frozen acquisition owner is required before a commercial value adjustment.');

            $context = $this->resolveContext($booking, $data, $attribution);
            $scheduleData = $this->scheduleRevisionPayload($data, $context);
            $revision = $this->scheduleWorkflow->reviseFutureUnpaid($booking, [
                ...$scheduleData,
                'preview_checksum' => (string) $data['preview_checksum'],
                'idempotency_key' => "cva:{$data['idempotency_key']}",
            ], $actorUserId);

            $resultingSource = round((float) $revision->contractual_source_amount, 4);
            $resultingLkr = $revision->contractual_lkr_amount !== null
                ? round((float) $revision->contractual_lkr_amount, 4) : null;
            $countsAsNewSales = in_array($data['adjustment_type'], self::NEW_SALES_ADJUSTMENT_TYPES, true)
                && $attribution->business_classification === 'new_business';
            abort_if($countsAsNewSales && ($context['previous_lkr'] === null || $resultingLkr === null), 409,
                'A governed LKR value is required before a commercial adjustment can affect New Sales.');

            $adjustment = BookingCommercialValueAdjustment::create([
                'company_id' => $attribution->company_id,
                'booking_id' => $booking->id,
                'attribution_id' => $attribution->id,
                'acquisition_sales_profile_id' => $attribution->acquisition_sales_profile_id,
                'adjustment_type' => $data['adjustment_type'],
                'delta_source_amount' => round($resultingSource - $context['previous_source'], 4),
                'source_currency' => $context['currency'],
                'delta_lkr_amount' => ($resultingLkr !== null && $context['previous_lkr'] !== null)
                    ? round($resultingLkr - $context['previous_lkr'], 4) : null,
                'fx_rate_to_lkr' => $data['fx_rate_to_lkr'] ?? null,
                'fx_rate_at' => $data['fx_rate_at'] ?? null,
                'previous_effective_source_amount' => $context['previous_source'],
                'previous_effective_lkr_amount' => $context['previous_lkr'],
                'resulting_effective_source_amount' => $resultingSource,
                'resulting_effective_lkr_amount' => $resultingLkr,
                'counts_as_new_sales_adjustment' => $countsAsNewSales,
                'schedule_revision_id' => $revision->id,
                'effective_at' => $context['effective_at'],
                'reason' => $data['reason'],
                'idempotency_key' => $data['idempotency_key'],
                'request_payload_checksum' => $checksum,
                'approved_by' => $actorUserId,
                'approved_at' => now(),
                'created_user_id' => $actorUserId,
            ]);

            $this->events->record(
                'sales',
                $attribution->company_id,
                'booking_commercial_value_adjustment',
                $adjustment->id,
                "sales.attribution.commercial_value.{$data['adjustment_type']}",
                1,
                1,
                [
                    'booking_id' => $booking->id,
                    'attribution_id' => $attribution->id,
                    'delta_source_amount' => (string) $adjustment->delta_source_amount,
                    'resulting_effective_source_amount' => (string) $resultingSource,
                    'schedule_revision_id' => $revision->id,
                ],
                $context['effective_at'],
                $data['idempotency_key'],
            );

            if ($countsAsNewSales) {
                $this->metricFacts->projectCommercialValueAdjustment($adjustment);
            }

            return $adjustment;
        });
    }

    /**
     * @return array{attribution: SalesBookingAttribution, currency: string, previous_source: float, previous_lkr: ?float, effective_at: Carbon}
     */
    private function resolveContext(Booking $booking, array $data, ?SalesBookingAttribution $attribution = null): array
    {
        abort_unless(in_array($data['adjustment_type'], self::VALID_TYPES, true), 422, 'Unsupported commercial value adjustment type.');
        $attribution ??= SalesBookingAttribution::query()->where('booking_id', $booking->id)->firstOrFail();
        abort_unless($attribution->contract_value_source !== null && $attribution->source_currency, 422,
            'The frozen contractual source amount and currency are required before a commercial value adjustment.');
        $currency = strtoupper((string) $attribution->source_currency);
        abort_unless(strtoupper((string) $data['source_currency']) === $currency, 422,
            'A commercial value adjustment must be denominated in the frozen contract source currency.');

        $latest = BookingCommercialValueAdjustment::query()
            ->where('booking_id', $booking->id)
            ->orderByDesc('effective_at')->orderByDesc('created_at')
            ->first();
        $previousSource = $latest
            ? round((float) $latest->resulting_effective_source_amount, 4)
            : round((float) $attribution->contract_value_source, 4);
        $previousLkr = $latest
            ? ($latest->resulting_effective_lkr_amount !== null ? round((float) $latest->resulting_effective_lkr_amount, 4) : null)
            : ($attribution->contract_value_lkr !== null ? round((float) $attribution->contract_value_lkr, 4) : null);

        return [
            'attribution' => $attribution,
            'currency' => $currency,
            'previous_source' => $previousSource,
            'previous_lkr' => $previousLkr,
            'effective_at' => Carbon::parse($data['effective_at']),
        ];
    }

    private function scheduleRevisionPayload(array $data, array $context): array
    {
        $type = $data['adjustment_type'];
        $payload = [
            'effective_at' => $data['effective_at'],
            'reason' => $data['reason'],
            'contract_basis' => 'fixed_term',
            'reconciliation_rule' => $data['reconciliation_rule'] ?? 'exact',
            'items' => $data['items'] ?? [],
        ];

        if ($type === 'cancellation') {
            abort_unless(empty($payload['items']), 422,
                'A cancellation retires the remaining future-unpaid lines; it does not replace them with new ones.');
            $payload['reconciliation_rule'] = 'exact';
            $payload['contractual_override_mode'] = 'retained_only';

            return $payload;
        }

        $magnitude = round((float) ($data['source_amount'] ?? 0), 4);
        if ($type !== 'extension') {
            abort_unless($magnitude > 0, 422, 'A commercial value adjustment magnitude must be positive.');
        }
        $signed = match ($type) {
            'increase', 'cancellation_fee' => $magnitude,
            'decrease' => -$magnitude,
            'extension' => 0.0,
        };
        $resultingSource = round($context['previous_source'] + $signed, 4);
        abort_unless($resultingSource >= 0, 422, 'A decrease cannot take the effective contract value below zero.');

        if ($context['currency'] === 'LKR') {
            $resultingLkr = $resultingSource;
        } elseif ($signed === 0.0) {
            $resultingLkr = $context['previous_lkr'];
        } else {
            abort_unless(! empty($data['lkr_amount']), 422,
                'A non-LKR commercial value adjustment requires an approved LKR magnitude for the change.');
            abort_unless($context['previous_lkr'] !== null, 422,
                'A prior governed LKR total is required before a non-LKR commercial value adjustment.');
            $lkrMagnitude = round((float) $data['lkr_amount'], 4);
            $lkrSigned = $type === 'decrease' ? -$lkrMagnitude : $lkrMagnitude;
            $resultingLkr = round($context['previous_lkr'] + $lkrSigned, 4);
        }

        $payload['contractual_override_mode'] = 'fixed';
        $payload['contractual_source_override'] = $resultingSource;
        $payload['contractual_lkr_override'] = $resultingLkr;

        return $payload;
    }

    private function previewResponse(array $data, array $context, array $schedulePreview): array
    {
        $resultingSource = (float) $schedulePreview['contractual_source_amount'];
        $resultingLkr = $schedulePreview['contractual_lkr_amount'] ?? null;
        $countsAsNewSales = in_array($data['adjustment_type'], self::NEW_SALES_ADJUSTMENT_TYPES, true)
            && $context['attribution']->business_classification === 'new_business';
        abort_if($countsAsNewSales && ($context['previous_lkr'] === null || $resultingLkr === null), 409,
            'A governed LKR value is required before a commercial adjustment can affect New Sales.');

        return [
            'adjustment_type' => $data['adjustment_type'],
            'source_currency' => $context['currency'],
            'previous_effective_source_amount' => $context['previous_source'],
            'previous_effective_lkr_amount' => $context['previous_lkr'],
            'resulting_effective_source_amount' => $resultingSource,
            'resulting_effective_lkr_amount' => $resultingLkr,
            'delta_source_amount' => round($resultingSource - $context['previous_source'], 4),
            'delta_lkr_amount' => ($resultingLkr !== null && $context['previous_lkr'] !== null)
                ? round((float) $resultingLkr - $context['previous_lkr'], 4) : null,
            'counts_as_new_sales_adjustment' => $countsAsNewSales,
            'schedule_reconciliation' => $schedulePreview,
            'preview_checksum' => $schedulePreview['preview_checksum'],
            'write_performed' => false,
        ];
    }

    private function payloadChecksum(string $bookingId, array $data): string
    {
        $payload = $data;
        unset($payload['idempotency_key'], $payload['preview_checksum']);

        return hash('sha256', CanonicalJson::encode(['booking_id' => $bookingId, 'facts' => $payload]));
    }
}
