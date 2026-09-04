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

class FinancialSettlementController extends Controller
{
    public function __construct(
        private readonly FinancialAccountSettlementService $service,
        private readonly BookingPaymentLedgerService $ledger,
        private readonly VehicleLeaseAccountingService $vehicleLeaseAccounting
    ) {}

    public function account(string $ownerType,string $ownerId)
    { abort_unless(in_array($ownerType,['customer','corporate'],true),404);return response()->json(['status'=>'success','data'=>$this->ledger->accountSummaryFor($ownerType,$ownerId)]); }

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
        if ($request->filled('owner_type')) $query->where('owner_type', $request->owner_type);
        if ($request->filled('status')) $query->where('status', $request->status);
        $rows = (clone $query)->withCount('items')->latest()->paginate((int) $request->input('per_page', 25));
        $driverPositions=DriverHireSettlement::query()->whereNotIn('status',['paid','recovered'])->select('driver_id')->selectRaw('SUM(final_balance) as net_balance')->groupBy('driver_id')->with('driver.user')->get()->map(fn($row)=>['driver_id'=>$row->driver_id,'driver_name'=>trim(($row->driver?->user?->first_name??'').' '.($row->driver?->user?->last_name??'')),'net_balance'=>(float)$row->net_balance,'position'=>(float)$row->net_balance>0?'company_owes_driver':((float)$row->net_balance<0?'driver_owes_company':'settled')]);
        return response()->json(['status'=>'success','data'=>[
            'summary'=>[
                'customer_due'=>(float) FinancialAccountSettlement::where('owner_type','customer')->whereNotIn('status',['paid','void'])->sum('outstanding_total'),
                'corporate_due'=>(float) FinancialAccountSettlement::where('owner_type','corporate')->whereNotIn('status',['paid','void'])->sum('outstanding_total'),
                'overdue'=>(float) FinancialAccountSettlement::where('status','overdue')->sum('outstanding_total'),
                'partially_settled'=>FinancialAccountSettlement::where('status','partial')->count(),
                'completed'=>FinancialAccountSettlement::where('status','paid')->count(),
                'driver_cash_held'=>(float) BookingPaymentReceipt::where('received_via','driver')->selectRaw('COALESCE(SUM(amount-driver_company_settled_amount),0) as balance')->value('balance'),
                'driver_cash_overdue'=>(float) BookingPaymentReceipt::where('received_via','driver')->where('driver_company_settlement_status','unsettled')->where('received_at','<',now()->subDays(7))->selectRaw('COALESCE(SUM(amount-driver_company_settled_amount),0) as balance')->value('balance'),
                'company_payable_to_drivers'=>(float) DriverHireSettlement::whereIn('status',['draft','submitted','ops_reviewed','accounts_finalized'])->where('final_balance','>',0)->sum('final_balance'),
                'company_receivable_from_drivers'=>abs((float)DriverHireSettlement::whereIn('status',['draft','submitted','ops_reviewed','recovery_pending'])->where('final_balance','<',0)->sum('final_balance')),
                'driver_settlements_overdue'=>(float)DriverHireSettlement::whereNotIn('status',['paid','recovered'])->whereNotNull('settlement_due_date')->whereDate('settlement_due_date','<',today())->sum(DB::raw('ABS(final_balance)')),
                'disputed'=>FinancialAccountSettlement::where('status','disputed')->count()+BookingPaymentReceipt::where('driver_company_settlement_status','disputed')->count(),
            ],
            'settlements'=>$rows,
            'driver_net_positions'=>$driverPositions,
            'vehicle_lease_accounting'=>$leaseAccounting,
        ]]);
    }

    public function show(FinancialAccountSettlement $financialSettlement)
    {
        $this->service->monitorReconciliation($financialSettlement);
        return response()->json(['status'=>'success','data'=>$financialSettlement->load(['items.booking','allocations'])]);
    }

    public function store(Request $request)
    {
        $data=$request->validate(['owner_type'=>'required|in:customer,corporate','owner_id'=>'required|uuid','billing_cycle'=>'required|in:ad_hoc,weekly,fortnightly,monthly','period_start'=>'required|date','period_end'=>'required|date|after_or_equal:period_start','due_date'=>'nullable|date|after_or_equal:period_end','notes'=>'nullable|string|max:2000']);
        return response()->json(['status'=>'success','message'=>'Settlement created with all eligible bookings.','data'=>$this->service->create($data,Auth::id())],201);
    }

    public function issue(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data=$request->validate(['invoice_number'=>'nullable|string|max:80','due_date'=>'nullable|date','send_invoice'=>'nullable|boolean']);
        return response()->json(['status'=>'success','message'=>'Settlement issued.','data'=>$this->service->issue($financialSettlement,$data)]);
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
            ->whereIn('company_id', $companyIds)
            ->count();

        abort_unless($bookingIds->isNotEmpty() && $scopedCount === $bookingIds->count(), 404,
            'Settlement was not found in the current legal-entity scope.');
    }

    public function driverCash(Request $request)
    {
        $rows=BookingPaymentReceipt::with(['booking','driver.user'])->where('received_via','driver')->whereRaw('amount > driver_company_settled_amount')->latest('received_at')->paginate((int)$request->input('per_page',25));
        return response()->json(['status'=>'success','data'=>$rows]);
    }

    public function settleDriverCash(Request $request)
    {
        $data=$request->validate(['driver_id'=>'required|uuid','amount'=>'required|numeric|gt:0','due_date'=>'nullable|date','handed_over_at'=>'required|date|before_or_equal:now','reference'=>'nullable|string|max:120','proof_files'=>'nullable|array','notes'=>'nullable|string|max:1000']);
        return response()->json(['status'=>'success','message'=>'Driver cash handover allocated to collected booking payments.','data'=>$this->service->settleDriverCash($data,Auth::id())],201);
    }

    public function disputeDriverCash(Request $request, BookingPaymentReceipt $receipt)
    { $data=$request->validate(['reason'=>'required|string|max:1000']);return response()->json(['status'=>'success','message'=>'Driver cash collection marked disputed.','data'=>$this->service->disputeDriverCashReceipt($receipt,$data['reason'],Auth::id())]); }

    public function resolveDriverCashDispute(Request $request, BookingPaymentReceipt $receipt)
    { $data=$request->validate(['notes'=>'required|string|max:1000']);return response()->json(['status'=>'success','message'=>'Driver cash dispute resolved.','data'=>$this->service->resolveDriverCashReceiptDispute($receipt,$data['notes'],Auth::id())]); }

    public function adjust(Request $request, FinancialAccountSettlement $financialSettlement)
    {
        $data=$request->validate(['booking_id'=>'required|uuid','type'=>'required|in:additional_charge,discount,refund,credit_note,waiver','amount'=>'required|numeric|gt:0','reason'=>'required|string|max:1000','reference'=>'nullable|string|max:120']);
        return response()->json(['status'=>'success','message'=>'Adjustment recorded and settlement recalculated.','data'=>$this->service->adjust($financialSettlement,$data,Auth::id())]);
    }

    public function dispute(Request $request, FinancialAccountSettlement $financialSettlement)
    { $data=$request->validate(['reason'=>'required|string|max:2000']);return response()->json(['status'=>'success','message'=>'Settlement marked disputed.','data'=>$this->service->dispute($financialSettlement,$data['reason'],Auth::id())]); }

    public function resolveDispute(Request $request, FinancialAccountSettlement $financialSettlement)
    { $data=$request->validate(['notes'=>'required|string|max:2000']);return response()->json(['status'=>'success','message'=>'Settlement dispute resolved.','data'=>$this->service->resolveDispute($financialSettlement,$data['notes'],Auth::id())]); }

    public function audit(FinancialAccountSettlement $financialSettlement)
    { return response()->json(['status'=>'success','data'=>FinancialAuditEvent::where('subject_type','account_settlement')->where('subject_id',$financialSettlement->id)->orderByDesc('occurred_at')->get()]); }

    public function downloadInvoice(FinancialAccountSettlement $financialSettlement)
    {
        $document=$financialSettlement->document ?: $this->service->generateDocument($financialSettlement);
        abort_unless($document->pdf_path && Storage::disk($document->pdf_disk)->exists($document->pdf_path),404,'Invoice document is not available.');
        return Storage::disk($document->pdf_disk)->download($document->pdf_path,$document->invoice_number.'.pdf');
    }
}
