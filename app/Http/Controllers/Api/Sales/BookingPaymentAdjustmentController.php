<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Sales\SalesBookingAttribution;
use App\Services\Sales\BookingPaymentAdjustmentService;
use App\Services\Sales\SalesAccessScope;
use App\Services\Sales\SalesPolicySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class BookingPaymentAdjustmentController extends Controller
{
    public function __construct(
        private readonly SalesAccessScope $access,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function context(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (empty($data['company_id'])) {
            $companyIds = $this->access->companyIds($request->user(), 'sales.payment-adjustments.create-all');
            $companies = DB::table('companies')->when($companyIds !== null, fn ($query) => $query->whereIn('id', $companyIds))
                ->orderBy('name')->get(['id', 'name']);
            return response()->json(['status' => 'success', 'data' => [
                'data' => [], 'current_page' => 1, 'last_page' => 1,
            ], 'meta' => ['companies' => $companies, 'policy_ready' => false]]);
        }
        $this->access->assertCompany($request->user(), $data['company_id'], 'sales.payment-adjustments.create-all');
        $profileIds = $this->profileIds($request, $data['company_id']);
        $query = DB::table('booking_payment_receipt_components as component')
            ->join('booking_payment_receipts as receipt', 'receipt.id', '=', 'component.receipt_id')
            ->join('bookings as booking', 'booking.id', '=', 'receipt.booking_id')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->leftJoin('sales_commission_decisions as decision', function ($join): void {
                $join->on('decision.receipt_component_id', '=', 'component.id')
                    ->whereIn('decision.status', ['earned', 'shadow_earned']);
            })
            ->whereNull('component.deleted_at')->whereNull('receipt.deleted_at')->whereNull('attribution.deleted_at')
            ->whereNotNull('receipt.company_id')
            ->where('receipt.company_id', $data['company_id'])
            ->whereColumn('receipt.company_id', 'attribution.company_id')
            ->where('receipt.finality_status', 'confirmed');
        if ($profileIds !== null) $query->whereIn('attribution.collection_sales_profile_id', $profileIds);
        if ($search = trim((string) ($data['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('booking.booking_number', 'like', "%{$search}%")
                ->orWhere('receipt.reference', 'like', "%{$search}%"));
        }

        $policy = $this->policySettings->fxCorrectionsPolicy($data['company_id']);
        $query->select([
            'booking.id as booking_id', 'booking.booking_number', 'component.id as receipt_component_id',
            'component.component_type', 'component.source_amount', 'component.adjusted_source_amount',
            'component.lkr_amount as original_lkr_amount', 'component.is_commission_eligible',
            'receipt.source_currency', 'receipt.fx_rate_to_lkr as original_fx_rate_to_lkr',
            'receipt.fx_rate_at as original_fx_rate_at', 'receipt.fx_source as original_fx_source',
            'decision.id as commission_decision_id', 'decision.formula_kind',
        ]);
        foreach ([
            'id' => 'latest_fx_adjustment_id', 'lkr_amount' => 'latest_corrected_lkr_amount',
            'source_amount' => 'latest_affected_source_amount',
            'fx_rate_to_lkr' => 'latest_fx_rate_to_lkr', 'fx_rate_at' => 'latest_fx_rate_at',
            'fx_source' => 'latest_fx_source', 'correction_sequence' => 'latest_correction_sequence',
        ] as $column => $alias) {
            $query->selectSub(fn ($q) => $q->from('booking_payment_adjustments as latest_fx')
                ->select("latest_fx.{$column}")->whereColumn('latest_fx.receipt_component_id', 'component.id')
                ->where('latest_fx.impact_dimension', 'reporting_fx')
                ->orderByDesc('latest_fx.correction_sequence')->limit(1), $alias);
        }

        return response()->json(['status' => 'success', 'data' => $query
            ->orderByDesc('receipt.received_at')->paginate($request->integer('per_page', 25)), 'meta' => [
                'companies' => DB::table('companies')
                    ->when(($ids = $this->access->companyIds($request->user(), 'sales.payment-adjustments.create-all')) !== null, fn ($query) => $query->whereIn('id', $ids))
                    ->orderBy('name')->get(['id', 'name']),
            'fx_corrections_enabled' => $this->policySettings->featureEnabled($data['company_id'], 'fx_corrections'),
            'policy_ready' => $this->policySettings->featureEnabled($data['company_id'], 'fx_corrections') && ! empty($policy['approved_quote_base'])
                && in_array($policy['calculation_mode'] ?? null, ['multiply_source_by_rate', 'divide_source_by_rate'], true)
                && $policy['max_rate_age_hours'] !== null && $policy['rounding_scale'] !== null,
            'approved_quote_base' => $policy['approved_quote_base'] ?? null,
            'calculation_mode' => $policy['calculation_mode'] ?? null,
            'max_rate_age_hours' => $policy['max_rate_age_hours'] ?? null,
            'rounding_scale' => $policy['rounding_scale'] ?? null,
        ]]);
    }

    public function store(Request $request, Booking $booking, BookingPaymentAdjustmentService $adjustments): JsonResponse
    {
        $data = $request->validate([
            'receipt_component_id' => ['nullable', 'uuid'],
            'impact_dimension' => ['required', Rule::in(['cash_receipt', 'receivable_schedule', 'reporting_fx'])],
            'adjustment_type' => ['required', Rule::in(['refund', 'chargeback', 'bounce', 'reversal', 'credit_note', 'debit_note', 'cancellation_fee', 'write_off', 'fx_correction', 'source_correction'])],
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'source_amount' => ['required', 'numeric', 'gt:0'],
            'source_currency' => ['required', 'string', 'size:3'],
            'lkr_amount' => ['required_if:impact_dimension,reporting_fx', 'nullable', 'numeric', 'gt:0'],
            'fx_rate_to_lkr' => ['required_if:impact_dimension,reporting_fx', 'nullable', 'numeric', 'gt:0'],
            'fx_rate_at' => ['required_if:impact_dimension,reporting_fx', 'nullable', 'date'],
            'fx_source' => ['required_if:impact_dimension,reporting_fx', 'nullable', 'string', 'max:160'],
            'fx_quote_base' => ['required_if:impact_dimension,reporting_fx', 'nullable', 'string', 'max:40'],
            'fx_calculation_mode' => ['required_if:impact_dimension,reporting_fx', 'nullable', Rule::in(['multiply_source_by_rate', 'divide_source_by_rate'])],
            'adjustment_effective_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'reference' => ['required_if:impact_dimension,reporting_fx', 'nullable', 'string', 'max:160'],
            'preview_checksum' => ['required_if:impact_dimension,reporting_fx', 'nullable', 'string', 'size:64'],
            'corrects_adjustment_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);

        $this->assertBookingScope($request, $booking);
        $adjustment = $adjustments->record($booking, $data, $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $adjustment], 201);
    }

    public function preview(Request $request, Booking $booking, BookingPaymentAdjustmentService $adjustments): JsonResponse
    {
        $data = $request->validate([
            'receipt_component_id' => ['required', 'uuid'],
            'impact_dimension' => ['required', Rule::in(['reporting_fx'])],
            'adjustment_type' => ['required', Rule::in(['fx_correction'])],
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'source_amount' => ['required', 'numeric', 'gt:0'], 'source_currency' => ['required', 'string', 'size:3'],
            'lkr_amount' => ['required', 'numeric', 'gt:0'], 'fx_rate_to_lkr' => ['required', 'numeric', 'gt:0'],
            'fx_rate_at' => ['required', 'date'], 'fx_source' => ['required', 'string', 'max:160'],
            'fx_quote_base' => ['required', 'string', 'max:40'],
            'fx_calculation_mode' => ['required', Rule::in(['multiply_source_by_rate', 'divide_source_by_rate'])],
            'adjustment_effective_at' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000'],
            'reference' => ['required', 'string', 'max:160'],
            'corrects_adjustment_id' => ['nullable', 'uuid'],
        ]);
        $this->assertBookingScope($request, $booking);
        return response()->json(['status' => 'success', 'data' => $adjustments->previewReportingFx($booking, $data)]);
    }

    public function previewFxEstablishment(Request $request, Booking $booking, BookingPaymentAdjustmentService $adjustments): JsonResponse
    {
        $data = $this->validateFxEstablishment($request);
        $this->assertBookingScope($request, $booking);

        return response()->json(['status' => 'success', 'data' => $adjustments->previewFxSnapshotEstablishment($booking, $data)]);
    }

    public function establishFxSnapshot(Request $request, Booking $booking, BookingPaymentAdjustmentService $adjustments): JsonResponse
    {
        $data = $this->validateFxEstablishment($request, true);
        $this->assertBookingScope($request, $booking);
        $adjustment = $adjustments->establishFxSnapshot($booking, $data, $request->user()->id);

        return response()->json(['status' => 'success', 'data' => $adjustment], 201);
    }

    private function validateFxEstablishment(Request $request, bool $forApply = false): array
    {
        return $request->validate([
            'receipt_component_id' => ['required', 'uuid'],
            'source_amount' => ['required', 'numeric', 'gt:0'],
            'source_currency' => ['required', 'string', 'size:3'],
            'lkr_amount' => ['required', 'numeric', 'gt:0'],
            'fx_rate_to_lkr' => ['required', 'numeric', 'gt:0'],
            'fx_rate_at' => ['required', 'date'],
            'fx_source' => ['required', 'string', 'max:160'],
            'fx_quote_base' => ['required', 'string', 'max:40'],
            'fx_calculation_mode' => ['required', Rule::in(['multiply_source_by_rate', 'divide_source_by_rate'])],
            'reason' => ['required', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:160'],
            'preview_checksum' => [$forApply ? 'required' : 'nullable', 'string', 'size:64'],
            'idempotency_key' => [$forApply ? 'required' : 'nullable', 'string', 'max:160'],
        ]);
    }

    private function assertBookingScope(Request $request, Booking $booking): void
    {
        $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->firstOrFail();
        $profileIds = $this->profileIds($request, $attribution->company_id);
        abort_unless($profileIds === null || in_array($attribution->collection_sales_profile_id, $profileIds, true), 403,
            'Booking is outside your permitted payment-adjustment scope.');
    }

    private function profileIds(Request $request, ?string $companyId = null): ?array
    {
        return $this->access->profileIds($request->user(), 'sales.payment-adjustments.create-all',
            'sales.payment-adjustments.create-team', $companyId);
    }
}
