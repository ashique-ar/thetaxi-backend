<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Booking\BookingPaymentReceiptComponent;
use App\Models\Sales\SalesBookingAttribution;
use App\Services\BookingPaymentLedgerService;
use App\Services\Sales\SalesCollectionCompanyIntegrity;
use App\Services\Sales\SalesAccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentLedgerReconciliationController extends Controller
{
    public function __construct(
        private readonly BookingPaymentLedgerService $ledger,
        private readonly SalesAccessScope $scope,
        private readonly SalesCollectionCompanyIntegrity $companyIntegrity,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'booking_number' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.collections.view-all');
        $limit = $data['limit'] ?? 250;
        $paidWithoutReceipt = Booking::query()
            ->whereIn('payment_status', ['paid', 'success', 'online_paid'])
            ->whereHas('salesAttribution', fn($query) => $query->where('company_id', $data['company_id']))
            ->whereDoesntHave('paymentReceipts')
            ->when($data['booking_number'] ?? null, fn($query, $number) => $query->where('booking_number', $number))
            ->orderBy('booking_number')->limit($limit + 1)
            ->get([
                'booking_number',
                'payment_status',
                'payment_method',
            ])->map(fn (Booking $booking) => [
                'booking_number' => $booking->booking_number,
                'payment_status' => $booking->payment_status,
                'payment_method' => $booking->payment_method,
            ]);
        $paidHasMore = $paidWithoutReceipt->count() > $limit;
        $paidWithoutReceipt = $paidWithoutReceipt->take($limit)->values();
        $receiptExceptions = DB::table('booking_payment_receipts as receipt')
            ->join('bookings as booking', 'booking.id', '=', 'receipt.booking_id')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->where('attribution.company_id', $data['company_id'])
            ->where(fn($query) => $query->whereNull('receipt.company_id')
                ->orWhereColumn('receipt.company_id', '!=', 'attribution.company_id')
                ->orWhereNull('receipt.source_currency')
                ->orWhere(fn($fx) => $fx->where('receipt.source_currency', '!=', 'LKR')->whereNull('receipt.fx_rate_to_lkr'))
                ->orWhereNotExists(fn($components) => $components->selectRaw('1')
                    ->from('booking_payment_receipt_components as component')
                    ->whereColumn('component.receipt_id', 'receipt.id')))
            ->when($data['booking_number'] ?? null, fn($query, $number) => $query->where('booking.booking_number', $number))
            ->orderBy('booking.booking_number')->orderBy('receipt.received_at')->limit($limit + 1)
            ->select([
                'booking.booking_number',
                'receipt.payment_method',
                'receipt.received_at',
                'receipt.source_currency',
                'receipt.fx_rate_to_lkr',
                'receipt.finality_status',
            ])
            ->selectRaw("CASE WHEN receipt.company_id IS NULL THEN 'missing' WHEN receipt.company_id != attribution.company_id THEN 'mismatch' ELSE 'matched' END AS company_state")
            ->selectRaw("CASE WHEN EXISTS (SELECT 1 FROM booking_payment_receipt_components component WHERE component.receipt_id = receipt.id) THEN 1 ELSE 0 END AS has_components")
            ->get();
        $receiptsHaveMore = $receiptExceptions->count() > $limit;
        $receiptExceptions = $receiptExceptions->take($limit)->map(fn ($receipt) => [
            'booking_number' => $receipt->booking_number,
            'payment_method' => $receipt->payment_method,
            'received_at' => $receipt->received_at,
            'source_currency' => $receipt->source_currency,
            'fx_rate_to_lkr' => $receipt->fx_rate_to_lkr,
            'finality_status' => $receipt->finality_status,
            'company_state' => $receipt->company_state,
            'has_components' => (bool) $receipt->has_components,
        ])->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'write_performed' => false,
                'paid_without_receipt' => $paidWithoutReceipt,
                'receipt_exceptions' => $receiptExceptions,
                'counts' => ['paid_without_receipt' => $paidWithoutReceipt->count(), 'receipt_exceptions' => $receiptExceptions->count()],
                'limit' => $limit,
                'has_more' => ['paid_without_receipt' => $paidHasMore, 'receipt_exceptions' => $receiptsHaveMore],
                'warning' => 'Preview never creates attribution, FX, finality, commission, or payout facts.',
            ]
        ]);
    }

    public function receiptComponentOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.collections.view-all');
        $query = DB::table('booking_payment_receipts as receipt')
            ->join('bookings as booking', 'booking.id', '=', 'receipt.booking_id')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->where('receipt.company_id', $data['company_id'])
            ->where('attribution.company_id', $data['company_id'])
            ->whereIn('receipt.payment_purpose', ['booking_payment', 'service_deposit', 'security_deposit'])
            ->whereNotExists(fn ($components) => $components->selectRaw('1')
                ->from('booking_payment_receipt_components as component')
                ->whereColumn('component.receipt_id', 'receipt.id'));
        if (! empty($data['selected_id'])) {
            $query->where('receipt.id', $data['selected_id']);
        } elseif (! empty($data['search'])) {
            $term = '%'.addcslashes($data['search'], '%_\\').'%';
            $query->where(fn ($scope) => $scope->where('booking.booking_number', 'like', $term)
                ->orWhere('receipt.payment_method', 'like', $term)
                ->orWhere('receipt.payment_purpose', 'like', $term));
        }
        $rows = $query->orderBy('booking.booking_number')->orderBy('receipt.received_at')
            ->select(['receipt.id', 'booking.booking_number', 'receipt.payment_purpose', 'receipt.payment_method', 'receipt.received_at'])
            ->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($row) => [
            'value' => (string) $row->id,
            'label' => $row->booking_number.' · '.$row->payment_method.' · '.$row->received_at,
            'status' => 'active',
            'metadata' => ['booking_number' => $row->booking_number, 'payment_purpose' => $row->payment_purpose],
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function legacyReceiptBookingOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.collections.view-all');
        $query = DB::table('bookings as booking')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->whereNull('booking.deleted_at')
            ->whereNull('attribution.deleted_at')
            ->where('attribution.company_id', $data['company_id'])
            ->where(function ($paid): void {
                $paid->where('booking.payment_collected_amount', '>', 0)
                    ->orWhere(function ($fullyPaid): void {
                        $fullyPaid->whereNull('booking.payment_collected_amount')
                            ->whereIn('booking.payment_status', ['paid', 'success', 'online_paid']);
                    });
            })
            ->whereRaw('COALESCE(booking.total_actual, booking.total_estimated, booking.amount_to_pay, 0) > 0')
            ->whereNotExists(fn ($receipts) => $receipts->selectRaw('1')->from('booking_payment_receipts as receipt')
                ->whereColumn('receipt.booking_id', 'booking.id'));
        if (! empty($data['selected_id'])) {
            $query->where('booking.id', $data['selected_id']);
        } elseif (! empty($data['search'])) {
            $term = '%'.addcslashes(trim($data['search']), '%_\\').'%';
            $query->where(fn ($search) => $search->where('booking.booking_number', 'like', $term)
                ->orWhere('booking.payment_reference', 'like', $term)
                ->orWhere('booking.payment_method', 'like', $term));
        }
        $rows = $query->orderBy('booking.booking_number')
            ->select([
                'booking.id', 'booking.booking_number', 'booking.payment_status', 'booking.payment_collected_amount',
                'booking.total_actual', 'booking.total_estimated', 'booking.amount_to_pay', 'booking.currency',
                'booking.payment_method', 'booking.payment_reference', 'booking.payment_collected_by_driver_id',
            ])->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(function ($row): array {
            $total = round((float) ($row->total_actual ?? $row->total_estimated ?? $row->amount_to_pay ?? 0), 2);
            $amount = $row->payment_collected_amount !== null
                ? min($total, max(0, round((float) $row->payment_collected_amount, 2)))
                : (in_array(strtolower((string) $row->payment_status), ['paid', 'success', 'online_paid'], true) ? $total : 0.0);
            $currency = strtoupper(trim((string) $row->currency));

            return [
                'value' => (string) $row->id,
                'label' => $row->booking_number.' · '.number_format($amount, 2).' '.($currency ?: 'currency unavailable'),
                'status' => 'active',
                'metadata' => [
                    'legacy_paid_amount' => number_format($amount, 2, '.', ''),
                    'booking_currency' => $currency,
                    'payment_method' => (string) $row->payment_method,
                    'payment_reference' => (string) $row->payment_reference,
                ],
            ];
        });

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function legacyReceiptRepairHistory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'booking_number' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.collections.view-all');
        $query = DB::table('financial_audit_events as event')
            ->join('booking_payment_receipts as receipt', 'receipt.id', '=', 'event.subject_id')
            ->join('bookings as booking', 'booking.id', '=', 'event.booking_id')
            ->join('sales_booking_attributions as attribution', 'attribution.booking_id', '=', 'booking.id')
            ->leftJoin('domain_evidence_files as evidence', function ($join) use ($data): void {
                $join->on('evidence.id', '=', 'receipt.legacy_repair_evidence_file_id')
                    ->where('evidence.domain', 'sales')
                    ->where('evidence.company_id', $data['company_id'])
                    ->where('evidence.subject_type', 'booking')
                    ->whereColumn('evidence.subject_id', 'booking.id')
                    ->where('evidence.evidence_type', 'legacy_paid_receipt_repair')
                    ->where('evidence.classification', 'restricted')
                    ->whereNull('evidence.deleted_at');
            })
            ->where('event.domain', 'sales')
            ->where('event.company_id', $data['company_id'])
            ->where('event.subject_type', 'booking_payment')
            ->where('event.event_type', 'legacy_payment_receipt_repaired')
            ->whereColumn('event.booking_id', 'booking.id')
            ->where('attribution.company_id', $data['company_id'])
            ->whereNull('booking.deleted_at')
            ->whereNull('attribution.deleted_at')
            ->where('receipt.metadata->reconciliation_repair', true)
            ->when($data['booking_number'] ?? null, fn ($scope, $number) => $scope->where('booking.booking_number', $number))
            ->select([
                'booking.booking_number', 'receipt.amount', 'receipt.payment_method', 'receipt.reference',
                'receipt.received_at', 'receipt.source_currency', 'receipt.legacy_repair_request_checksum', 'receipt.legacy_repair_before_checksum',
                'receipt.legacy_repair_evidence_file_id', 'receipt.legacy_repair_reason',
                'event.metadata as audit_metadata', 'event.occurred_at', 'event.amount as audit_amount',
                'evidence.id as evidence_id', 'evidence.file_name as evidence_file_name', 'evidence.file_checksum as evidence_file_checksum',
            ])
            ->selectSub(DB::table('financial_audit_events as matching_event')->selectRaw('COUNT(*)')
                ->where('matching_event.domain', 'sales')
                ->where('matching_event.company_id', $data['company_id'])
                ->where('matching_event.subject_type', 'booking_payment')
                ->whereColumn('matching_event.subject_id', 'receipt.id')
                ->whereColumn('matching_event.booking_id', 'booking.id')
                ->where('matching_event.event_type', 'legacy_payment_receipt_repaired'), 'repair_event_count')
            ->orderByDesc('event.occurred_at')->orderBy('booking.booking_number');
        $rows = $query->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(function ($row): array {
            $audit = json_decode((string) $row->audit_metadata, true) ?: [];
            $requestChecksum = (string) $row->legacy_repair_request_checksum;
            $beforeChecksum = (string) $row->legacy_repair_before_checksum;
            $afterChecksum = (string) ($audit['after_checksum'] ?? '');
            $reason = (string) $row->legacy_repair_reason;
            $evidenceValid = ! empty($row->legacy_repair_evidence_file_id)
                && (string) $row->evidence_id === (string) $row->legacy_repair_evidence_file_id;
            $auditValid = (int) $row->repair_event_count === 1
                && strlen($requestChecksum) === 64 && hash_equals($requestChecksum, (string) ($audit['request_checksum'] ?? ''))
                && strlen($beforeChecksum) === 64 && hash_equals($beforeChecksum, (string) ($audit['before_checksum'] ?? ''))
                && strlen($afterChecksum) === 64
                && hash_equals($reason, (string) ($audit['repair_reason'] ?? ''))
                && round((float) $row->amount, 2) === round((float) $row->audit_amount, 2);

            return [
                'booking_number' => (string) $row->booking_number,
                'amount' => (float) $row->amount,
                'currency' => (string) $row->source_currency,
                'payment_method' => (string) $row->payment_method,
                'reference' => (string) $row->reference,
                'received_at' => $row->received_at,
                'recorded_at' => $row->occurred_at,
                'actor' => (string) ($audit['actor_display_snapshot'] ?? 'Unavailable'),
                'reason' => $reason,
                'audit_status' => $auditValid && $evidenceValid ? 'consistent' : 'held',
                'request_checksum' => $requestChecksum,
                'before_checksum' => $beforeChecksum,
                'after_checksum' => $afterChecksum,
                'evidence_id' => $evidenceValid ? (string) $row->evidence_id : null,
                'evidence_file_name' => $evidenceValid ? (string) $row->evidence_file_name : null,
                'evidence_file_checksum' => $evidenceValid ? (string) $row->evidence_file_checksum : null,
            ];
        });

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function repairLegacyBooking(Request $request, Booking $booking): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'payment_method' => ['required', 'string', 'max:50'],
            'reference' => ['required', 'string', 'max:160'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['required', 'string', 'max:2000'],
            'evidence_file_id' => ['required', 'uuid', 'exists:domain_evidence_files,id'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.collections.view-all');

        $result = $this->ledger->repairLegacyPaidBooking($booking, $data, (string) $request->user()->id, $data['company_id']);
        return response()->json([
            'status' => 'success',
            'data' => $result['summary'],
            'meta' => ['replayed' => $result['replayed']],
        ], $result['replayed'] ? 200 : 201);
    }

    public function repairComponents(Request $request, BookingPaymentReceipt $receipt): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'evidence_file_id' => ['required', 'uuid', 'exists:domain_evidence_files,id'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $this->scope->assertCompany($request->user(), $data['company_id'], 'sales.collections.view-all');

        $result = DB::transaction(function () use ($receipt, $data, $request): array {
            $booking = Booking::query()->lockForUpdate()->findOrFail($receipt->booking_id);
            $attribution = SalesBookingAttribution::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            abort_unless($attribution?->company_id && hash_equals($data['company_id'], (string) $attribution->company_id), 409,
                'The booking legal entity is missing or changed; refresh the authorized reconciliation scope.');
            $this->companyIntegrity->assertConsistent($booking, (string) $attribution->company_id);
            $receipt = BookingPaymentReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            abort_unless(hash_equals((string) $attribution->company_id, (string) $receipt->company_id), 409,
                'The receipt company does not match its booking attribution.');
            $componentType = (string) $receipt->payment_purpose;
            abort_unless(in_array($componentType, ['booking_payment', 'service_deposit', 'security_deposit'], true), 409,
                'The receipt purpose is not governed for component repair.');
            $eligible = in_array($componentType, ['booking_payment', 'service_deposit'], true);
            $requestChecksum = hash('sha256', json_encode([
                'company_id' => (string) $attribution->company_id,
                'booking_id' => (string) $booking->id,
                'receipt_id' => (string) $receipt->id,
                'component_type' => $componentType,
                'is_allocatable' => true,
                'is_collection_target_eligible' => $eligible,
                'is_commission_eligible' => $eligible,
                'evidence_file_id' => $data['evidence_file_id'],
                'reason' => trim($data['reason']),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $duplicate = BookingPaymentReceiptComponent::query()
                ->where('repair_idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                abort_unless((string) $duplicate->receipt_id === (string) $receipt->id
                    && hash_equals((string) $duplicate->repair_request_checksum, $requestChecksum), 409,
                    'This component repair key was already used with different facts.');
                abort_unless(DB::table('domain_evidence_files')->where('id', $duplicate->repair_evidence_file_id)
                    ->where('domain', 'sales')->where('company_id', $attribution->company_id)
                    ->where('subject_type', 'booking_payment_receipt')->where('subject_id', $receipt->id)
                    ->where('evidence_type', 'legacy_payment_component_repair')
                    ->where('classification', 'restricted')->whereNull('deleted_at')->exists(), 409,
                    'Original restricted component repair evidence is missing or unavailable.');
                $afterChecksum = $this->componentRepairChecksum($duplicate);
                $events = DB::table('domain_audit_events')->where('domain', 'sales')
                    ->where('company_id', $attribution->company_id)
                    ->where('event_type', 'sales.payment.component.repaired')
                    ->where('correlation_id', $data['idempotency_key'])
                    ->where('subject_type', 'booking_payment_receipt_component')
                    ->where('subject_id', $duplicate->id)->limit(2)->get(['before_checksum', 'after_checksum', 'reason']);
                abort_unless($duplicate->repair_before_checksum && $events->count() === 1
                    && hash_equals($duplicate->repair_before_checksum, (string) $events[0]->before_checksum)
                    && hash_equals($afterChecksum, (string) $events[0]->after_checksum)
                    && $events[0]->reason === $duplicate->repair_reason, 409,
                    'Original component repair evidence is missing or inconsistent; replay is held.');

                return [...$this->componentRepairResult($duplicate), 'replayed' => true];
            }
            abort_if(DB::table('domain_audit_events')->where('domain', 'sales')
                ->where('company_id', $attribution->company_id)
                ->where('event_type', 'sales.payment.component.repaired')
                ->where('correlation_id', $data['idempotency_key'])->exists(), 409,
                'Component repair audit evidence exists without its source row; replay is held.');
            abort_if($receipt->components()->exists(), 422,
                'Receipt components already exist; create an adjustment instead of rewriting classification.');
            abort_unless(DB::table('domain_evidence_files')->where('id', $data['evidence_file_id'])
                ->where('domain', 'sales')->where('company_id', $attribution->company_id)
                ->where('subject_type', 'booking_payment_receipt')->where('subject_id', $receipt->id)
                ->where('evidence_type', 'legacy_payment_component_repair')
                ->where('classification', 'restricted')->whereNull('deleted_at')->exists(), 422,
                'Restricted evidence must be attached to this receipt and legal entity.');
            $beforeChecksum = hash('sha256', json_encode([
                'booking_id' => (string) $booking->id, 'company_id' => (string) $attribution->company_id,
                'receipt_id' => (string) $receipt->id, 'payment_purpose' => $componentType,
                'source_amount' => (string) ($receipt->source_amount ?? $receipt->amount),
                'lkr_amount' => $receipt->lkr_amount === null ? null : (string) $receipt->lkr_amount,
                'components_exist' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $component = BookingPaymentReceiptComponent::create([
                'receipt_id' => $receipt->id,
                'component_type' => $componentType,
                'source_amount' => $receipt->source_amount ?? $receipt->amount,
                'lkr_amount' => $receipt->lkr_amount,
                'is_allocatable' => true,
                'is_collection_target_eligible' => $eligible,
                'is_commission_eligible' => $eligible,
                'repair_idempotency_key' => $data['idempotency_key'],
                'repair_request_checksum' => $requestChecksum,
                'repair_before_checksum' => $beforeChecksum,
                'repair_evidence_file_id' => $data['evidence_file_id'],
                'repair_reason' => trim($data['reason']),
            ]);
            $afterChecksum = $this->componentRepairChecksum($component);
            DB::table('domain_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'domain' => 'sales',
                'company_id' => $receipt->company_id,
                'subject_type' => 'booking_payment_receipt_component',
                'subject_id' => $component->id,
                'event_type' => 'sales.payment.component.repaired',
                'actor_user_id' => $request->user()->id,
                'actor_type' => 'user',
                'correlation_id' => $data['idempotency_key'],
                'source_ip' => $request->ip(),
                'before_checksum' => $beforeChecksum,
                'after_checksum' => $afterChecksum,
                'reason' => trim($data['reason']),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return [...$this->componentRepairResult($component), 'replayed' => false];
        });

        return response()->json(['status' => 'success', 'data' => $result], $result['replayed'] ? 200 : 201);
    }

    private function componentRepairResult(BookingPaymentReceiptComponent $component): array
    {
        return [
            'component_type' => $component->component_type,
            'source_amount' => $component->source_amount,
            'lkr_amount' => $component->lkr_amount,
            'is_allocatable' => (bool) $component->is_allocatable,
            'is_collection_target_eligible' => (bool) $component->is_collection_target_eligible,
            'is_commission_eligible' => (bool) $component->is_commission_eligible,
        ];
    }

    private function componentRepairChecksum(BookingPaymentReceiptComponent $component): string
    {
        return hash('sha256', json_encode([
            'id' => (string) $component->id,
            'receipt_id' => (string) $component->receipt_id,
            'component_type' => (string) $component->component_type,
            'source_amount' => (string) $component->source_amount,
            'lkr_amount' => $component->lkr_amount === null ? null : (string) $component->lkr_amount,
            'is_allocatable' => (bool) $component->is_allocatable,
            'is_collection_target_eligible' => (bool) $component->is_collection_target_eligible,
            'is_commission_eligible' => (bool) $component->is_commission_eligible,
            'repair_idempotency_key' => (string) $component->repair_idempotency_key,
            'repair_request_checksum' => (string) $component->repair_request_checksum,
            'repair_before_checksum' => (string) $component->repair_before_checksum,
            'repair_evidence_file_id' => (string) $component->repair_evidence_file_id,
            'repair_reason' => (string) $component->repair_reason,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
