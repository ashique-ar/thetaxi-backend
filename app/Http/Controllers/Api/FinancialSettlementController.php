<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Finance\FinancialAccountSettlement;
use App\Services\FinancialAccountSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Finance\DriverCashSettlement;
use App\Models\Finance\FinancialAuditEvent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\Driver\DriverHireSettlement;
use App\Models\Vehicle\Vehicle;
use App\Services\BookingPaymentLedgerService;
use App\Services\VehicleLeaseAccountingService;
use App\Models\Corporate\Corporate;
use App\Models\Finance\CorporateRemittance;
use App\Models\Booking\BookingItem;
use App\Models\Finance\FinancialSettlementItem;

class FinancialSettlementController extends Controller
{
    public function __construct(
        private readonly FinancialAccountSettlementService $service,
        private readonly BookingPaymentLedgerService $ledger,
        private readonly VehicleLeaseAccountingService $vehicleLeaseAccounting
    )
    {
    }

    public function account(string $ownerType, string $ownerId)
    {
        abort_unless(in_array($ownerType, ['customer', 'corporate'], true), 404);
        return response()->json(['status' => 'success', 'data' => $this->ledger->accountSummaryFor($ownerType, $ownerId)]);
    }

    public function dashboard(Request $request)
    {
        $this->service->markOverdueSettlements(Auth::id());
        $leaseAccounting = null;
        if ($request->user()?->can('vehicle-leases.view')) {
            $periodStart = now()->startOfMonth();
            $periodEnd = now()->endOfMonth();
            $leaseAccounting = [
                'period' => [
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                ],
                ...$this->vehicleLeaseAccounting->portfolioSummary(
                    $periodStart,
                    $periodEnd,
                    Vehicle::query()->pluck('id')
                ),
            ];
        }
        $query = FinancialAccountSettlement::query();
        if ($request->filled('owner_type'))
            $query->where('owner_type', $request->owner_type);
        if ($request->filled('status'))
            $query->where('status', $request->status);
        if ($request->filled('corporate_id'))
            $query->where('owner_type', 'corporate')->where('owner_id', $request->corporate_id);
        if ($request->boolean('attention_only'))
            $query->whereNotIn('status', ['paid', 'void', 'draft']);
        if ($request->boolean('follow_up_due'))
            $query->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now())->whereNotIn('status', ['paid', 'void']);
        $rows = (clone $query)->withCount('items')->latest()->paginate((int) $request->input('per_page', 25));
        $corporateNames = Corporate::query()->whereIn('id', $rows->getCollection()->where('owner_type', 'corporate')->pluck('owner_id'))->pluck('name', 'id');
        $rows->getCollection()->transform(function ($settlement) use ($corporateNames) {
            $settlement->owner_name = $settlement->owner_type === 'corporate' ? $corporateNames[$settlement->owner_id] ?? null : null;
            return $settlement;
        });
        $billedMonthlyItemIds = FinancialSettlementItem::query()->whereHas('settlement', fn($q) => $q->where('owner_type', 'corporate')->whereNotIn('status', ['void', 'draft']))
            ->pluck('booking_item_ids')->flatten()->filter()->map(fn($id) => (string) $id)->unique();
        $monthlyItems = BookingItem::query()->where('status', 'completed')->whereHas('booking', fn($q) => $q->where('is_corporate_booking', true)->where('payment_collection_method', 'monthly_invoice'));
        $pendingMonthly = (clone $monthlyItems)->whereNotNull('final_priced_at')->whereNotIn('id', $billedMonthlyItemIds);
        $driverPositions = DriverHireSettlement::query()->whereNotIn('status', ['paid', 'recovered'])->select('driver_id')->selectRaw('SUM(final_balance) as net_balance')->groupBy('driver_id')->with('driver.user')->get()->map(fn($row) => ['driver_id' => $row->driver_id, 'driver_name' => trim(($row->driver?->user?->first_name ?? '') . ' ' . ($row->driver?->user?->last_name ?? '')), 'net_balance' => (float) $row->net_balance, 'position' => (float) $row->net_balance > 0 ? 'company_owes_driver' : ((float) $row->net_balance < 0 ? 'driver_owes_company' : 'settled')]);
        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'customer_due' => (float) FinancialAccountSettlement::where('owner_type', 'customer')->whereNotIn('status', ['paid', 'void'])->sum('outstanding_total'),
                    'corporate_due' => (float) FinancialAccountSettlement::where('owner_type', 'corporate')->whereNotIn('status', ['paid', 'void'])->sum('outstanding_total'),
                    'overdue' => (float) FinancialAccountSettlement::where('status', 'overdue')->sum('outstanding_total'),
                    'partially_settled' => FinancialAccountSettlement::where('status', 'partial')->count(),
                    'completed' => FinancialAccountSettlement::where('status', 'paid')->count(),
                    'driver_cash_held' => (float) BookingPaymentReceipt::where('received_via', 'driver')->selectRaw('COALESCE(SUM(amount-driver_company_settled_amount),0) as balance')->value('balance'),
                    'driver_cash_overdue' => (float) BookingPaymentReceipt::where('received_via', 'driver')->where('driver_company_settlement_status', 'unsettled')->where('received_at', '<', now()->subDays(7))->selectRaw('COALESCE(SUM(amount-driver_company_settled_amount),0) as balance')->value('balance'),
                    'company_payable_to_drivers' => (float) DriverHireSettlement::whereIn('status', ['draft', 'submitted', 'ops_reviewed', 'accounts_finalized'])->where('final_balance', '>', 0)->sum('final_balance'),
                    'company_receivable_from_drivers' => abs((float) DriverHireSettlement::whereIn('status', ['draft', 'submitted', 'ops_reviewed', 'recovery_pending'])->where('final_balance', '<', 0)->sum('final_balance')),
                    'driver_settlements_overdue' => (float) DriverHireSettlement::whereNotIn('status', ['paid', 'recovered'])->whereNotNull('settlement_due_date')->whereDate('settlement_due_date', '<', today())->sum(DB::raw('ABS(final_balance)')),
                    'disputed' => FinancialAccountSettlement::where('status', 'disputed')->count() + BookingPaymentReceipt::where('driver_company_settlement_status', 'disputed')->count(),
                    'collection_follow_up_due' => FinancialAccountSettlement::where('owner_type', 'corporate')->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now())->whereNotIn('status', ['paid', 'void'])->count(),
                    'active_corporates' => Corporate::active()->count(),
                    'corporate_open_invoices' => FinancialAccountSettlement::where('owner_type', 'corporate')->whereNotIn('status', ['paid', 'void', 'draft'])->count(),
                    'corporate_overdue' => (float) FinancialAccountSettlement::where('owner_type', 'corporate')->where('status', 'overdue')->sum('outstanding_total'),
                    'corporate_disputed' => FinancialAccountSettlement::where('owner_type', 'corporate')->where('status', 'disputed')->count(),
                    'pending_monthly_trip_count' => (clone $pendingMonthly)->count(),
                    'pending_monthly_total' => (float) (clone $pendingMonthly)->sum('total_price'),
                    'final_pricing_pending_trip_count' => (clone $monthlyItems)->whereNull('final_priced_at')->count(),
                    'unapplied_remittance_total' => (float) CorporateRemittance::sum('unapplied_amount'),
                ],
                'settlements' => $rows,
                'driver_net_positions' => $driverPositions,
                'vehicle_lease_accounting' => $leaseAccounting,
            ]
        ]);
    }

    public function show(FinancialAccountSettlement $financialSettlement)
    {
        $this->service->monitorReconciliation($financialSettlement);
        return response()->json(['status' => 'success', 'data' => $financialSettlement->load(['items.booking', 'allocations'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['owner_type' => 'required|in:customer,corporate', 'owner_id' => 'required|uuid', 'billing_cycle' => 'required|in:ad_hoc,weekly,fortnightly,monthly', 'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start', 'due_date' => 'nullable|date|after_or_equal:period_end', 'notes' => 'nullable|string|max:2000']);
        return response()->json(['status' => 'success', 'message' => 'Settlement created with all eligible bookings.', 'data' => $this->service->create($data, Auth::id())], 201);
    }

    public function issue(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data = $request->validate(['invoice_number' => 'nullable|string|max:80', 'due_date' => 'nullable|date', 'send_invoice' => 'nullable|boolean']);
        return response()->json(['status' => 'success', 'message' => 'Settlement issued.', 'data' => $this->service->issue($financialSettlement, $data)]);
    }

    public function receivePayment(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data = $request->validate(['amount' => 'required|numeric|gt:0', 'payment_method' => 'required|in:cash,card,bank_transfer,online,cheque,other', 'reference' => 'nullable|string|max:120', 'received_at' => 'required|date|before_or_equal:now', 'notes' => 'nullable|string|max:1000', 'idempotency_key' => 'nullable|string|max:120']);
        return response()->json(['status' => 'success', 'message' => 'Account payment allocated to the oldest outstanding bookings.', 'data' => $this->service->receivePayment($financialSettlement, $data, Auth::id())]);
    }

    public function receiveCorporateRemittance(Request $request, Corporate $corporate)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'currency' => 'required|string|size:3',
            'payment_method' => 'required|in:cash,card,bank_transfer,online,cheque,other',
            'reference' => 'nullable|string|max:120',
            'idempotency_key' => 'required|string|max:120',
            'received_at' => 'required|date|before_or_equal:now',
            'notes' => 'nullable|string|max:1000',
            'settlement_ids' => 'nullable|array',
            'settlement_ids.*' => 'uuid',
        ]);
        return response()->json(['status' => 'success', 'message' => 'Corporate remittance recorded and allocated to eligible invoices.', 'data' => $this->service->receiveCorporateRemittance($corporate, $data, Auth::id())], 201);
    }

    public function allocateCorporateRemittance(Request $request, CorporateRemittance $remittance)
    {
        $data = $request->validate(['settlement_ids'=>'required|array|min:1','settlement_ids.*'=>'uuid|distinct']);
        return response()->json(['status'=>'success','message'=>'Unapplied remittance allocated to selected invoices.','data'=>$this->service->allocateCorporateRemittance($remittance,$data['settlement_ids'],Auth::id())]);
    }

    public function followUp(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data = $request->validate([
            'collection_owner_id' => 'nullable|uuid',
            'collection_last_contact_at' => 'nullable|date|before_or_equal:now',
            'promised_payment_date' => 'nullable|date',
            'next_follow_up_at' => 'nullable|date',
            'collection_notes' => 'nullable|string|max:2000',
        ]);
        return response()->json(['status' => 'success', 'message' => 'Collection follow-up updated.', 'data' => $this->service->updateCollectionFollowUp($financialSettlement, $data, Auth::id())]);
    }

    public function driverCash(Request $request)
    {
        $rows = BookingPaymentReceipt::with(['booking', 'driver.user'])->where('received_via', 'driver')->whereRaw('amount > driver_company_settled_amount')->latest('received_at')->paginate((int) $request->input('per_page', 25));
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function settleDriverCash(Request $request)
    {
        $data = $request->validate(['driver_id' => 'required|uuid', 'amount' => 'required|numeric|gt:0', 'due_date' => 'nullable|date', 'handed_over_at' => 'required|date|before_or_equal:now', 'reference' => 'nullable|string|max:120', 'proof_files' => 'nullable|array', 'notes' => 'nullable|string|max:1000']);
        return response()->json(['status' => 'success', 'message' => 'Driver cash handover allocated to collected booking payments.', 'data' => $this->service->settleDriverCash($data, Auth::id())], 201);
    }

    public function disputeDriverCash(Request $request, BookingPaymentReceipt $receipt)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        return response()->json(['status' => 'success', 'message' => 'Driver cash collection marked disputed.', 'data' => $this->service->disputeDriverCashReceipt($receipt, $data['reason'], Auth::id())]);
    }

    public function resolveDriverCashDispute(Request $request, BookingPaymentReceipt $receipt)
    {
        $data = $request->validate(['notes' => 'required|string|max:1000']);
        return response()->json(['status' => 'success', 'message' => 'Driver cash dispute resolved.', 'data' => $this->service->resolveDriverCashReceiptDispute($receipt, $data['notes'], Auth::id())]);
    }

    public function adjust(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data = $request->validate(['booking_id' => 'required|uuid', 'type' => 'required|in:additional_charge,discount,refund,credit_note,waiver', 'amount' => 'required|numeric|gt:0', 'reason' => 'required|string|max:1000', 'reference' => 'required_if:type,refund|nullable|string|max:120', 'metadata' => 'nullable|array', 'metadata.payment_method' => 'required_if:type,refund|nullable|in:cash,card,bank_transfer,online,cheque,other', 'metadata.refunded_at' => 'required_if:type,refund|nullable|date|before_or_equal:now']);
        return response()->json(['status' => 'success', 'message' => 'Adjustment recorded and settlement recalculated.', 'data' => $this->service->adjust($financialSettlement, $data, Auth::id())]);
    }

    public function dispute(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        return response()->json(['status' => 'success', 'message' => 'Settlement marked disputed.', 'data' => $this->service->dispute($financialSettlement, $data['reason'], Auth::id())]);
    }

    public function resolveDispute(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data = $request->validate(['notes' => 'required|string|max:2000']);
        return response()->json(['status' => 'success', 'message' => 'Settlement dispute resolved.', 'data' => $this->service->resolveDispute($financialSettlement, $data['notes'], Auth::id())]);
    }

    public function audit(FinancialAccountSettlement $financialSettlement)
    {
        return response()->json(['status' => 'success', 'data' => FinancialAuditEvent::where('subject_type', 'account_settlement')->where('subject_id', $financialSettlement->id)->orderByDesc('occurred_at')->get()]);
    }

    public function downloadInvoice(FinancialAccountSettlement $financialSettlement)
    {
        $document = $financialSettlement->document ?: $this->service->generateDocument($financialSettlement);
        abort_unless($document->pdf_path && Storage::disk($document->pdf_disk)->exists($document->pdf_path), 404, 'Invoice document is not available.');
        return Storage::disk($document->pdf_disk)->download($document->pdf_path, $document->invoice_number . '.pdf');
    }
}
