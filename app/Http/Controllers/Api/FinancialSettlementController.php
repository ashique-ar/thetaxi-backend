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
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Corporate\Corporate;
use App\Models\Customer;
use App\Models\Finance\CorporateRemittance;
use App\Models\Booking\BookingItem;
use App\Models\Finance\FinancialSettlementItem;
use App\Models\Finance\CorporateRemittanceAllocation;
use App\Models\Finance\FinancialAdjustment;
use Barryvdh\DomPDF\Facade\Pdf;

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
        $period = $request->validate([
            'billing_period_from' => ['nullable', 'date_format:Y-m-d'],
            'billing_period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:billing_period_from'],
        ]);
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
        if (!empty($period['billing_period_from']) || !empty($period['billing_period_to'])) {
            $query->when(!empty($period['billing_period_from']), fn ($q) => $q->whereDate('period_end', '>=', $period['billing_period_from']))
                ->when(!empty($period['billing_period_to']), fn ($q) => $q->whereDate('period_start', '<=', $period['billing_period_to']));
        }
        if ($request->boolean('attention_only'))
            $query->whereNotIn('status', ['paid', 'void', 'draft']);
        if ($request->boolean('follow_up_due'))
            $query->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now())->whereNotIn('status', ['paid', 'void']);
        $rows = (clone $query)->withCount('items')->with(['items' => fn ($q) => $q->with('booking:id,currency')->orderBy('created_at')->orderBy('id')])->latest()->paginate((int) $request->input('per_page', 25));
        $corporateNames = Corporate::query()->whereIn('id', $rows->getCollection()->where('owner_type', 'corporate')->pluck('owner_id'))->pluck('name', 'id');
        $rows->getCollection()->transform(function ($settlement) use ($corporateNames) {
            $settlement->owner_name = $settlement->owner_type === 'corporate' ? $corporateNames[$settlement->owner_id] ?? null : null;
            $settlement->currency = strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency') ?: $settlement->items->first()?->booking?->currency ?: 'LKR'));
            return $settlement;
        });
        $billedMonthlyItemIds = FinancialSettlementItem::query()->whereHas('settlement', fn($q) => $q->where('owner_type', 'corporate')->where('status', '!=', 'void'))
            ->pluck('booking_item_ids')->flatten()->filter()->map(fn($id) => (string) $id)->unique();
        $monthlyItems = BookingItem::query()->where('booking_items.status', 'completed')->whereHas('booking', fn($q) => $q->where('is_corporate_booking', true)->where('payment_collection_method', 'monthly_invoice'));
        $pendingMonthly = (clone $monthlyItems)->whereNotNull('booking_items.final_priced_at')->whereNotIn('booking_items.id', $billedMonthlyItemIds);
        $pendingMonthlyByCurrency = (clone $pendingMonthly)->join('bookings as monthly_bookings', 'booking_items.booking_id', '=', 'monthly_bookings.id')
            ->selectRaw("COALESCE(NULLIF(UPPER(booking_items.currency), ''), NULLIF(UPPER(monthly_bookings.currency), ''), 'LKR') as currency, SUM(COALESCE(booking_items.total_price, 0)) as amount")
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => ['currency' => (string) $row->currency, 'amount' => (float) $row->amount])->values();
        $currencyBySettlement = DB::table('financial_settlement_items as currency_items')
            ->join('bookings as currency_bookings', 'currency_bookings.id', '=', 'currency_items.booking_id')
            ->whereNull('currency_items.deleted_at')
            ->selectRaw("DISTINCT ON (currency_items.settlement_id) currency_items.settlement_id, COALESCE(NULLIF(UPPER(currency_bookings.currency), ''), 'LKR') as booking_currency")
            ->orderBy('currency_items.settlement_id')->orderBy('currency_items.created_at')->orderBy('currency_items.id');
        $periodSummary = DB::query()->fromSub(
            (clone $query)->select(['id', 'billing_terms_snapshot', 'charges_total', 'payments_total', 'outstanding_total']),
            'selected_settlements'
        )->leftJoinSub(clone $currencyBySettlement, 'selected_currency', 'selected_currency.settlement_id', '=', 'selected_settlements.id')
            ->selectRaw("COALESCE(NULLIF(UPPER(selected_settlements.billing_terms_snapshot->>'currency'), ''), selected_currency.booking_currency, 'LKR') as currency")
            ->selectRaw('COUNT(*) as invoices, SUM(charges_total) as charges_total, SUM(payments_total) as payments_total, SUM(outstanding_total) as outstanding_total')
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => [
                'currency' => (string) $row->currency, 'invoices' => (int) $row->invoices,
                'charges_total' => (float) $row->charges_total, 'payments_total' => (float) $row->payments_total,
                'outstanding_total' => (float) $row->outstanding_total,
            ])->values();
        $settlementTotals = DB::table('financial_account_settlements as currency_settlements')
            ->leftJoinSub($currencyBySettlement, 'currency_by_settlement', 'currency_by_settlement.settlement_id', '=', 'currency_settlements.id')
            ->whereNull('currency_settlements.deleted_at')
            ->selectRaw("COALESCE(NULLIF(UPPER(currency_settlements.billing_terms_snapshot->>'currency'), ''), currency_by_settlement.booking_currency, 'LKR') as currency")
            ->selectRaw("SUM(CASE WHEN currency_settlements.owner_type = 'customer' AND currency_settlements.status NOT IN ('paid', 'void') THEN currency_settlements.outstanding_total ELSE 0 END) as customer_due")
            ->selectRaw("SUM(CASE WHEN currency_settlements.owner_type = 'corporate' AND currency_settlements.status NOT IN ('paid', 'void') THEN currency_settlements.outstanding_total ELSE 0 END) as corporate_due")
            ->selectRaw("SUM(CASE WHEN currency_settlements.owner_type = 'corporate' AND currency_settlements.status = 'overdue' THEN currency_settlements.outstanding_total ELSE 0 END) as corporate_overdue")
            ->selectRaw("SUM(CASE WHEN currency_settlements.status = 'overdue' THEN currency_settlements.outstanding_total ELSE 0 END) as overdue")
            ->groupBy('currency')->orderBy('currency')->get();
        $dueByCurrency = fn ($key) => $settlementTotals->map(fn ($row) => ['currency' => (string) $row->currency, 'amount' => (float) $row->{$key}])->filter(fn ($row) => $row['amount'] > 0)->values();
        $unappliedRemittanceByCurrency = CorporateRemittance::query()
            ->selectRaw("COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency, SUM(unapplied_amount) as amount")
            ->where('unapplied_amount', '>', 0)->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => ['currency' => (string) $row->currency, 'amount' => (float) $row->amount])->values();
        $driverCashByCurrency = BookingPaymentReceipt::query()->join('bookings as cash_bookings', 'cash_bookings.id', '=', 'booking_payment_receipts.booking_id')
            ->where('received_via', 'driver')->where('finality_status', 'confirmed')
            ->selectRaw("COALESCE(NULLIF(UPPER(source_currency), ''), NULLIF(UPPER(cash_bookings.currency), ''), 'LKR') as currency, COALESCE(SUM(amount - driver_company_settled_amount), 0) as amount")
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => ['currency' => (string) $row->currency, 'amount' => (float) $row->amount])->values();
        $driverCashOverdueByCurrency = BookingPaymentReceipt::query()->join('bookings as cash_bookings', 'cash_bookings.id', '=', 'booking_payment_receipts.booking_id')
            ->where('received_via', 'driver')->whereIn('driver_company_settlement_status', ['unsettled', 'partially_settled'])->where('finality_status', 'confirmed')->where('received_at', '<', now()->subDays(7))
            ->selectRaw("COALESCE(NULLIF(UPPER(source_currency), ''), NULLIF(UPPER(cash_bookings.currency), ''), 'LKR') as currency, COALESCE(SUM(amount - driver_company_settled_amount), 0) as amount")
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => ['currency' => (string) $row->currency, 'amount' => (float) $row->amount])->values();
        $driverPositions = DriverHireSettlement::query()->whereNotIn('status', ['paid', 'recovered'])->select('driver_id')->selectRaw('SUM(final_balance) as net_balance')->groupBy('driver_id')->with('driver.user')->get()->map(fn($row) => ['driver_id' => $row->driver_id, 'driver_name' => trim(($row->driver?->user?->first_name ?? '') . ' ' . ($row->driver?->user?->last_name ?? '')), 'net_balance' => (float) $row->net_balance, 'position' => (float) $row->net_balance > 0 ? 'company_owes_driver' : ((float) $row->net_balance < 0 ? 'driver_owes_company' : 'settled')]);
        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'customer_due_by_currency' => $dueByCurrency('customer_due'),
                    'corporate_due_by_currency' => $dueByCurrency('corporate_due'),
                    'overdue_by_currency' => $dueByCurrency('overdue'),
                    'partially_settled' => FinancialAccountSettlement::where('status', 'partial')->count(),
                    'completed' => FinancialAccountSettlement::where('status', 'paid')->count(),
                    'driver_cash_held_by_currency' => $driverCashByCurrency,
                    'driver_cash_overdue_by_currency' => $driverCashOverdueByCurrency,
                    'company_payable_to_drivers' => (float) DriverHireSettlement::whereIn('status', ['draft', 'submitted', 'ops_reviewed', 'accounts_finalized'])->where('final_balance', '>', 0)->sum('final_balance'),
                    'company_receivable_from_drivers' => abs((float) DriverHireSettlement::whereIn('status', ['draft', 'submitted', 'ops_reviewed', 'recovery_pending'])->where('final_balance', '<', 0)->sum('final_balance')),
                    'driver_settlements_overdue' => (float) DriverHireSettlement::whereNotIn('status', ['paid', 'recovered'])->whereNotNull('settlement_due_date')->whereDate('settlement_due_date', '<', today())->sum(DB::raw('ABS(final_balance)')),
                    'disputed' => FinancialAccountSettlement::where('status', 'disputed')->count() + BookingPaymentReceipt::where('driver_company_settlement_status', 'disputed')->count(),
                    'collection_follow_up_due' => FinancialAccountSettlement::where('owner_type', 'corporate')->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now())->whereNotIn('status', ['paid', 'void'])->count(),
                    'active_corporates' => Corporate::active()->count(),
                    'corporate_open_invoices' => FinancialAccountSettlement::where('owner_type', 'corporate')->whereNotIn('status', ['paid', 'void', 'draft'])->count(),
                    'corporate_overdue_by_currency' => $dueByCurrency('corporate_overdue'),
                    'corporate_disputed' => FinancialAccountSettlement::where('owner_type', 'corporate')->where('status', 'disputed')->count(),
                    'corporate_draft_invoices' => FinancialAccountSettlement::where('owner_type', 'corporate')->where('status', 'draft')->count(),
                    'pending_monthly_trip_count' => (clone $pendingMonthly)->count(),
                    'pending_monthly_by_currency' => $pendingMonthlyByCurrency,
                    'final_pricing_pending_trip_count' => (clone $monthlyItems)->whereNull('final_priced_at')->count(),
                    'unapplied_remittance_by_currency' => $unappliedRemittanceByCurrency,
                ],
                'settlements' => $rows,
                'billing_period_summary_by_currency' => $periodSummary,
                'driver_net_positions' => $driverPositions,
                'recent_corporate_remittances' => CorporateRemittance::query()->with(['allocations.reversal', 'allocations.settlement.items.booking:id,currency'])->latest('received_at')->limit(20)->get(),
                'vehicle_lease_accounting' => $leaseAccounting,
            ]
        ]);
    }

    public function exportBillingPeriods(Request $request)
    {
        $filters = $request->validate([
            'format' => ['required', 'in:csv,pdf'],
            'billing_period_from' => ['nullable', 'date_format:Y-m-d'],
            'billing_period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:billing_period_from'],
            'status' => ['nullable', 'in:draft,open,partial,overdue,disputed,paid,void'],
            'owner_type' => ['nullable', 'in:customer,corporate'],
            'follow_up_due' => ['nullable', 'boolean'],
        ]);
        $from = $filters['billing_period_from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['billing_period_to'] ?? today()->toDateString();
        $settlements = FinancialAccountSettlement::query()->with(['items' => fn ($q) => $q->with('booking:id,currency')->orderBy('created_at')->orderBy('id')])
            ->whereDate('period_end', '>=', $from)->whereDate('period_start', '<=', $to)
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['owner_type']), fn ($q) => $q->where('owner_type', $filters['owner_type']))
            ->when(!empty($filters['follow_up_due']), fn ($q) => $q->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now())->whereNotIn('status', ['paid', 'void']))
            ->orderByDesc('period_start')->orderBy('settlement_number')->get();
        $corporateNames = Corporate::query()->whereIn('id', $settlements->where('owner_type', 'corporate')->pluck('owner_id'))->pluck('name', 'id');
        $customerNames = Customer::query()->with('user')->whereIn('id', $settlements->where('owner_type', 'customer')->pluck('owner_id'))->get()
            ->mapWithKeys(fn ($customer) => [$customer->id => trim(($customer->user?->first_name ?? '') . ' ' . ($customer->user?->last_name ?? '')) ?: $customer->id]);
        $headers = ['Settlement', 'Invoice', 'Account type', 'Account', 'Billing cycle', 'Period start', 'Period end', 'Due date', 'Status', 'Charges', 'Payments', 'Outstanding', 'Currency'];
        $rows = $settlements->map(function ($settlement) use ($corporateNames, $customerNames) {
            $currency = strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency') ?: $settlement->items->first()?->booking?->currency ?: 'LKR'));
            return [
                $settlement->settlement_number, $settlement->invoice_number, $settlement->owner_type,
                $settlement->owner_type === 'corporate' ? ($corporateNames[$settlement->owner_id] ?? $settlement->owner_id) : ($customerNames[$settlement->owner_id] ?? $settlement->owner_id),
                $settlement->billing_cycle, $settlement->period_start?->toDateString(), $settlement->period_end?->toDateString(),
                $settlement->due_date?->toDateString(), $settlement->status, $settlement->charges_total,
                $settlement->payments_total, $settlement->outstanding_total, $currency,
            ];
        })->all();
        $totals = $settlements->groupBy(fn ($settlement) => strtoupper((string) (data_get($settlement->billing_terms_snapshot, 'currency') ?: $settlement->items->first()?->booking?->currency ?: 'LKR')))
            ->map(fn ($group, $currency) => [
                'BILLING PERIOD TOTAL (' . count($group) . ' invoices)', '', '', '', '', '', '', '', '',
                $group->sum('charges_total'), $group->sum('payments_total'), $group->sum('outstanding_total'), $currency,
            ])->values()->all();
        if ($totals) $rows = [...$rows, array_fill(0, count($headers), ''), ...$totals];
        $filename = 'billing-period-settlements_' . $from . '_to_' . $to;
        if ($filters['format'] === 'csv') {
            return response()->streamDownload(function () use ($headers, $rows) {
                $output = fopen('php://output', 'w');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, $headers);
                foreach ($rows as $row) {
                    $safe = array_map(fn ($value) => is_string($value) && !is_numeric($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value, $row);
                    fputcsv($output, $safe);
                }
                fclose($output);
            }, $filename . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $head = implode('', array_map(fn ($value) => '<th>' . e($value) . '</th>', $headers));
        $body = collect($rows)->map(fn ($row) => '<tr>' . implode('', array_map(fn ($value) => '<td>' . e((string) $value) . '</td>', $row)) . '</tr>')->implode('');
        $html = '<html><head><meta charset="utf-8"><style>body{font:9px sans-serif}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:4px;text-align:left}th{background:#eee}</style></head><body><h2>Billing period settlements: ' . e($from) . ' to ' . e($to) . '</h2><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></body></html>';
        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($filename . '.pdf');
    }

    public function show(FinancialAccountSettlement $financialSettlement)
    {
        $issues = $this->service->monitorReconciliation($financialSettlement);
        $data = $financialSettlement->load(['items.booking', 'allocations']);
        $data->reconciliation_issues = $issues;
        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['owner_type' => 'required|in:customer,corporate', 'owner_id' => 'required|uuid', 'billing_cycle' => 'required|in:ad_hoc,weekly,fortnightly,monthly', 'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start', 'due_date' => 'nullable|date|after_or_equal:period_end', 'currency' => 'required|string|regex:/^[A-Za-z]{3}$/', 'notes' => 'nullable|string|max:2000']);
        $data['currency'] = strtoupper($data['currency']);
        return response()->json(['status' => 'success', 'message' => 'Settlement created with all eligible bookings.', 'data' => $this->service->create($data, Auth::id())], 201);
    }

    public function issue(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data = $request->validate(['invoice_number' => 'nullable|string|max:80', 'due_date' => 'nullable|date', 'send_invoice' => 'nullable|boolean']);
        return response()->json(['status' => 'success', 'message' => 'Settlement issued.', 'data' => $this->service->issue($financialSettlement, $data)]);
    }

    public function receivePayment(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $this->assertSettlementCollectionScope($request, $financialSettlement);
        $request->merge(['source_currency' => strtoupper((string) $request->input('source_currency'))]);
        $data=$request->validate([
            'source_amount'=>'required|numeric|gt:0',
            'source_currency'=>'required|string|size:3',
            'fx_rate_to_lkr'=>'nullable|required_unless:source_currency,LKR|numeric|gt:0',
            'fx_rate_at'=>'nullable|required_unless:source_currency,LKR|date|before_or_equal:now',
            'fx_source'=>'nullable|required_unless:source_currency,LKR|string|max:120',
            'payment_method'=>'required|in:cash,card,bank_transfer,online,cheque,other',
            'reference'=>'nullable|string|max:120',
            'received_at'=>'required|date|before_or_equal:now',
            'notes'=>'nullable|string|max:1000',
            'idempotency_key'=>'required|uuid',
        ]);
        return response()->json(['status'=>'success','message'=>'Account payment allocated to the oldest outstanding bookings.','data'=>$this->service->receivePayment($financialSettlement,$data,Auth::id())]);
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

    public function reverseCorporateRemittanceAllocation(Request $request, CorporateRemittanceAllocation $allocation)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        return response()->json(['status' => 'success', 'message' => 'Allocation reversed to unapplied corporate credit.', 'data' => $this->service->reverseCorporateRemittanceAllocation($allocation, $data['reason'], Auth::id())]);
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

    private function assertSettlementCollectionScope(Request $request, FinancialAccountSettlement $settlement): void
    {
        if ($request->user()->can('sales.collections.view-all')) {
            return;
        }
        $companyIds = DB::table('staff')
            ->where('user_id', $request->user()->id)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->pluck('company_id')->filter()->unique()->values()->all();
        $bookingIds = $settlement->items()->pluck('booking_id');
        $scopedCount = SalesBookingAttribution::query()
            ->whereIn('booking_id', $bookingIds)
            ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrainIn($_companyIdScope, 'company_id', $companyIds))
            ->count();

        abort_unless($bookingIds->isNotEmpty() && $scopedCount === $bookingIds->count(), 404,
            'Settlement was not found in the current legal-entity scope.');
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

    public function downloadAdjustment(FinancialAdjustment $adjustment)
    {
        $adjustment->load('settlement', 'booking');
        return Pdf::loadView('finance.financial-adjustment', compact('adjustment'))
            ->download(strtoupper(str_replace('_', '-', $adjustment->type)).'-'.$adjustment->id.'.pdf');
    }
}
