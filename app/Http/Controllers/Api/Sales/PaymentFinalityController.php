<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Booking\BookingPaymentFinalityPolicy;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Staff;
use App\Services\BookingPaymentLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\Sales\SalesPolicySettingsService;
use Illuminate\Validation\ValidationException;

class PaymentFinalityController extends Controller
{
    public function __construct(
        private readonly BookingPaymentLedgerService $ledger,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'retired'])],
        ]);
        $query = BookingPaymentFinalityPolicy::query()
            ->leftJoin('companies', 'companies.id', '=', 'booking_payment_finality_policies.company_id')
            ->select(['booking_payment_finality_policies.*', 'companies.name as company_name']);
        if (! $request->user()->can('sales.payment-finality.manage-all')) {
            $query->whereIn('booking_payment_finality_policies.company_id', $this->actorCompanyIds($request));
        }

        return response()->json(['status' => 'success', 'data' => $query
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('booking_payment_finality_policies.company_id', $id))
            ->when($data['payment_method'] ?? null, fn ($q, $method) => $q->where('booking_payment_finality_policies.payment_method', strtolower($method)))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('booking_payment_finality_policies.status', $status))
            ->orderBy('booking_payment_finality_policies.payment_method')->orderByDesc('booking_payment_finality_policies.version')->get()]);
    }

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = DB::table('companies')->whereNull('deleted_at');
        if (! $request->user()->can('sales.payment-finality.manage-all')) {
            $query->whereIn('id', $this->actorCompanyIds($request));
        }
        if (! empty($data['selected_id'])) {
            $query->where('id', $data['selected_id']);
        } elseif (! empty($data['search'])) {
            $term = '%' . addcslashes($data['search'], '%_\\') . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('city', 'like', $term));
        }
        $rows = $query->select(['id', 'name', 'city'])->orderBy('name')->orderBy('id')
            ->paginate($data['per_page'] ?? 25);
        $rows->getCollection()->transform(fn ($company) => [
            'value' => (string) $company->id,
            'label' => $company->name,
            'metadata' => ['city' => $company->city],
            'status' => 'active',
        ]);
        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function context(Request $request): JsonResponse
    {
        $ids = $request->user()->can('sales.payment-finality.manage-all') ? DB::table('companies')->pluck('id') : collect($this->actorCompanyIds($request));
        $companies = DB::table('companies')->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
        return response()->json(['status' => 'success', 'data' => [
            'companies' => $companies,
            'enforcement_enabled_by_company' => $companies->mapWithKeys(fn ($company) => [
                $company->id => $this->policySettings->featureEnabled((string) $company->id, 'enforce_payment_finality'),
            ]),
        ]]);
    }

    public function receipts(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'finality_status' => ['nullable', Rule::in(['pending_clearance', 'confirmed', 'failed'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = BookingPaymentReceipt::query()->join('bookings', 'bookings.id', '=', 'booking_payment_receipts.booking_id')
            ->when(! $request->user()->can('sales.payment-finality.manage-all'), fn ($q) => $q->whereIn('booking_payment_receipts.company_id', $this->actorCompanyIds($request)))
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('booking_payment_receipts.company_id', $id))
            ->when($data['finality_status'] ?? null, fn ($q, $status) => $q->where('booking_payment_receipts.finality_status', $status))
            ->select(['booking_payment_receipts.id', 'booking_payment_receipts.company_id', 'booking_payment_receipts.booking_id', 'bookings.booking_number', 'booking_payment_receipts.source_amount', 'booking_payment_receipts.source_currency', 'booking_payment_receipts.lkr_amount', 'booking_payment_receipts.payment_method', 'booking_payment_receipts.reference', 'booking_payment_receipts.received_at', 'booking_payment_receipts.finality_status', 'booking_payment_receipts.finalized_at'])
            ->addSelect([
                'commission_decision_count' => DB::table('sales_commission_decisions as decision')
                    ->join('booking_payment_receipt_components as component', 'component.id', '=', 'decision.receipt_component_id')
                    ->whereColumn('component.receipt_id', 'booking_payment_receipts.id')->selectRaw('count(*)'),
                'commission_open_hold_count' => DB::table('sales_commission_decisions as decision')
                    ->join('booking_payment_receipt_components as component', 'component.id', '=', 'decision.receipt_component_id')
                    ->leftJoin('sales_commission_hold_releases as release', 'release.commission_decision_id', '=', 'decision.id')
                    ->leftJoin('sales_commission_hold_adjustments as adjustment', 'adjustment.commission_decision_id', '=', 'decision.id')
                    ->leftJoin('sales_commission_hold_resolutions as resolution', 'resolution.commission_decision_id', '=', 'decision.id')
                    ->whereColumn('component.receipt_id', 'booking_payment_receipts.id')
                    ->whereIn('decision.status', ['held', 'shadow_held'])
                    ->whereNull('release.id')->whereNull('adjustment.id')->whereNull('resolution.id')->selectRaw('count(*)'),
                'commission_finality_release_count' => DB::table('sales_commission_decisions as decision')
                    ->join('booking_payment_receipt_components as component', 'component.id', '=', 'decision.receipt_component_id')
                    ->join('sales_commission_hold_releases as release', 'release.commission_decision_id', '=', 'decision.id')
                    ->whereColumn('component.receipt_id', 'booking_payment_receipts.id')
                    ->where('release.release_kind', 'finality_confirmation')->selectRaw('count(*)'),
                'commission_terminal_resolution_count' => DB::table('sales_commission_decisions as decision')
                    ->join('booking_payment_receipt_components as component', 'component.id', '=', 'decision.receipt_component_id')
                    ->join('sales_commission_hold_resolutions as resolution', 'resolution.commission_decision_id', '=', 'decision.id')
                    ->whereColumn('component.receipt_id', 'booking_payment_receipts.id')
                    ->where('resolution.resolution_kind', 'failed_finality_no_entitlement')->selectRaw('count(*)'),
                'commission_decision_status' => DB::table('sales_commission_decisions as decision')
                    ->join('booking_payment_receipt_components as component', 'component.id', '=', 'decision.receipt_component_id')
                    ->whereColumn('component.receipt_id', 'booking_payment_receipts.id')->select('decision.status')->limit(1),
                'commission_hold_code' => DB::table('sales_commission_decisions as decision')
                    ->join('booking_payment_receipt_components as component', 'component.id', '=', 'decision.receipt_component_id')
                    ->whereColumn('component.receipt_id', 'booking_payment_receipts.id')->select('decision.hold_code')->limit(1),
                'commission_release_kind' => DB::table('sales_commission_decisions as decision')
                    ->join('booking_payment_receipt_components as component', 'component.id', '=', 'decision.receipt_component_id')
                    ->join('sales_commission_hold_releases as release', 'release.commission_decision_id', '=', 'decision.id')
                    ->whereColumn('component.receipt_id', 'booking_payment_receipts.id')->select('release.release_kind')->limit(1),
            ])
            ->latest('booking_payment_receipts.received_at')->paginate($request->integer('per_page', 25));
        return response()->json(['status' => 'success', 'data' => $query]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'payment_method' => ['required', 'string', 'max:50'],
            'official_collection_state' => ['required', Rule::in(['confirmed', 'pending_clearance'])],
            'can_earn_before_final' => ['required', 'boolean'],
            'hold_payout_until_final' => ['required', 'boolean'],
            'clearance_timeout_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'required_evidence_type' => ['nullable', 'string', 'max:80'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertCompanyScope($request, $data['company_id']);
        $data['payment_method'] = strtolower($data['payment_method']);
        $this->validatePendingClearancePolicy($data);

        $policy = DB::transaction(function () use ($request, $data) {
            $company = DB::table('companies')->where('id', $data['company_id'])->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 422, 'Select an available legal entity.');
            $version = (int) BookingPaymentFinalityPolicy::query()
                ->where('company_id', $data['company_id'])->where('payment_method', $data['payment_method'])
                ->lockForUpdate()->max('version') + 1;
            return BookingPaymentFinalityPolicy::create($data + ['version' => $version, 'status' => 'draft', 'created_by' => $request->user()->id]);
        });

        return response()->json(['status' => 'success', 'data' => $policy], 201);
    }

    public function approve(Request $request, BookingPaymentFinalityPolicy $policy): JsonResponse
    {
        $this->assertCompanyScope($request, $policy->company_id);
        abort_unless($policy->status === 'draft', 422, 'Only draft finality policies can be approved.');
        DB::transaction(function () use ($policy, $request): void {
            DB::table('companies')->where('id', $policy->company_id)->lockForUpdate()->first();
            $policy = BookingPaymentFinalityPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            abort_unless($policy->status === 'draft', 422, 'Only draft finality policies can be approved.');
            $this->validatePendingClearancePolicy($policy->toArray());
            abort_if($policy->created_by === $request->user()->id, 409, 'Finality-policy creator cannot approve the same version.');
            $overlap = BookingPaymentFinalityPolicy::query()
                ->where('company_id', $policy->company_id)->where('payment_method', $policy->payment_method)
                ->where('status', 'approved')->where('id', '!=', $policy->id)
                ->where('effective_from', '<', $policy->effective_until ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $policy->effective_from))
                ->exists();
            abort_if($overlap, 422, 'An approved finality policy already overlaps this effective period.');
            $policy->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        });

        return response()->json(['status' => 'success', 'data' => $policy->fresh()]);
    }

    public function transition(Request $request, BookingPaymentReceipt $receipt): JsonResponse
    {
        $data = $request->validate([
            'to_status' => ['required', Rule::in(['pending_clearance', 'confirmed', 'failed'])],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence_reference' => ['nullable', 'string', 'max:500'],
            'evidence_file_id' => ['nullable', 'uuid', 'exists:domain_evidence_files,id'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        $this->assertCompanyScope($request, $receipt->company_id);
        $policy = DB::table('booking_payment_finality_policies')
            ->where('company_id', $receipt->company_id)->where('payment_method', strtolower((string) $receipt->payment_method))
            ->where('status', 'approved')->where('effective_from', '<=', $receipt->received_at)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $receipt->received_at))
            ->orderByDesc('version')->first();
        if ($data['to_status'] === 'confirmed'
            && $this->policySettings->featureEnabled((string) $receipt->company_id, 'enforce_payment_finality')) {
            abort_unless($policy, 422, 'An approved payment-method finality policy is required before confirmation.');
        }
        if ($policy?->required_evidence_type) {
            abort_unless(! empty($data['evidence_file_id']), 422, 'This finality policy requires private evidence.');
        }
        if (! empty($data['evidence_file_id'])) {
            $evidence = DB::table('domain_evidence_files')->whereKey($data['evidence_file_id'])->whereNull('deleted_at')
                ->where('domain', 'sales')->where('company_id', $receipt->company_id)
                ->where('subject_type', 'booking_payment_receipt')->where('subject_id', $receipt->id)->first();
            abort_unless($evidence, 422, 'Finality evidence must be bound to this receipt and legal entity.');
            abort_unless(! $policy?->required_evidence_type || $evidence->evidence_type === $policy->required_evidence_type,
                422, 'The uploaded evidence type does not satisfy the approved finality policy.');
        }

        return response()->json(['status' => 'success', 'data' => $this->ledger->transitionReceiptFinality(
            $receipt, $data['to_status'], $data['reason'], $data['evidence_file_id'] ?? ($data['evidence_reference'] ?? null),
            $data['idempotency_key'], (string) $request->user()->id,
        )]);
    }

    private function assertCompanyScope(Request $request, ?string $companyId): void
    {
        if ($request->user()->can('sales.payment-finality.manage-all')) {
            return;
        }
        abort_unless($companyId && in_array($companyId, $this->actorCompanyIds($request), true), 403, 'Payment-finality record is outside your legal entity.');
    }

    private function actorCompanyIds(Request $request): array
    {
        return Staff::query()->where('user_id', $request->user()->id)
            ->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->pluck('company_id')->filter()->unique()->values()->all();
    }

    private function validatePendingClearancePolicy(array $policy): void
    {
        if (($policy['official_collection_state'] ?? null) === 'pending_clearance'
            && ! (bool) ($policy['hold_payout_until_final'] ?? false)) {
            throw ValidationException::withMessages([
                'hold_payout_until_final' => ['A pending-clearance policy must hold commission payout until confirmed finality.'],
            ]);
        }
    }
}
