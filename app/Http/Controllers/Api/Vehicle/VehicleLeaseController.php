<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleLease;
use App\Services\VehicleLeaseAccountingService;
use App\Services\VehicleLeaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleLeaseController extends Controller
{
    public function __construct(
        private readonly VehicleLeaseService $leases,
        private readonly VehicleLeaseAccountingService $accounting
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'vehicle_id' => ['nullable', 'uuid', 'exists:vehicles,id'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'expired', 'closure_pending', 'released', 'completed'])],
            'financial_status' => ['nullable', Rule::in(['pending', 'active', 'settled'])],
            'search' => ['nullable', 'string', 'max:120'],
            'due_before' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = VehicleLease::query()
            ->with([
                'vehicle:id,title,license_plate,registration_no',
                'financeProvider',
                'ownerAtStart.user:id,first_name,last_name',
                'release',
            ])
            ->withSum('schedules as scheduled_amount', 'amount_due')
            ->withSum(['payments as paid_amount' => fn ($payment) => $payment->where('status', 'recorded')], 'amount')
            ->when($filters['vehicle_id'] ?? null, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['financial_status'] ?? null, fn ($q, $status) => $q->where('financial_status', $status))
            ->when($filters['due_before'] ?? null, fn ($q, $date) => $q->whereHas(
                'schedules',
                fn ($schedule) => $schedule->whereDate('due_date', '<=', $date)
                    ->whereIn('status', ['scheduled', 'partially_paid', 'overdue'])
            ))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $search = trim($search);
                $q->where(function ($nested) use ($search) {
                    $nested->whereLikeInsensitive('lease_number', $search)
                        ->orWhereLikeInsensitive('agreement_number', $search)
                        ->orWhereHas('financeProvider', fn ($provider) => $provider->whereLikeInsensitive('name', $search))
                        ->orWhereHas('vehicle', fn ($vehicle) => $vehicle
                            ->whereLikeInsensitive('title', $search)
                            ->orWhereLikeInsensitive('license_plate', $search)
                            ->orWhereLikeInsensitive('registration_no', $search));
                });
            })
            ->latest('start_date');

        $statusCounts = VehicleLease::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $rows = $query->paginate($filters['per_page'] ?? 25);
        $rows->getCollection()->transform(function (VehicleLease $lease) {
            $scheduled = round((float) ($lease->scheduled_amount ?? 0), 2);
            $paid = round((float) ($lease->paid_amount ?? 0), 2);
            $scheduleBalance = max(0, round($scheduled - $paid, 2));
            $release = $lease->release;
            $lease->setAttribute(
                'outstanding_amount',
                $lease->status === 'released'
                    ? ($release?->settlement_status === 'pending'
                        ? max(0, (float) $release->net_settlement_amount)
                        : 0)
                    : ($lease->financial_status === 'settled' ? 0 : $scheduleBalance)
            );
            $lease->setAttribute(
                'receivable_amount',
                $lease->status === 'released' && $release?->settlement_status === 'pending'
                    ? abs(min(0, (float) $release->net_settlement_amount))
                    : 0
            );
            return $lease;
        });
        $accountingStart = now()->startOfMonth();
        $accountingEnd = now()->endOfMonth();
        $accounting = [
            'period' => [
                'start_date' => $accountingStart->toDateString(),
                'end_date' => $accountingEnd->toDateString(),
            ],
            ...$this->accounting->portfolioSummary(
                $accountingStart,
                $accountingEnd,
                Vehicle::query()->pluck('id')
            ),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $rows,
            'accounting' => $accounting,
            'summary' => [
                'total' => (int) $statusCounts->sum(),
                'draft' => (int) ($statusCounts['draft'] ?? 0),
                'active' => (int) ($statusCounts['active'] ?? 0),
                'expired' => (int) ($statusCounts['expired'] ?? 0),
                'closure_pending' => (int) ($statusCounts['closure_pending'] ?? 0),
                'released' => (int) ($statusCounts['released'] ?? 0),
                'completed' => (int) ($statusCounts['completed'] ?? 0),
            ],
        ]);
    }

    public function vehicleHistory(Vehicle $vehicle): JsonResponse
    {
        $leases = VehicleLease::query()
            ->where('vehicle_id', $vehicle->id)
            ->with(['financeProvider', 'ownerAtStart.user', 'release'])
            ->withSum('schedules as scheduled_amount', 'amount_due')
            ->withSum(['payments as paid_amount' => fn ($payment) => $payment->where('status', 'recorded')], 'amount')
            ->orderByDesc('start_date')
            ->get()
            ->each(function (VehicleLease $lease) {
                $release = $lease->release;
                $lease->setAttribute(
                    'outstanding_amount',
                    $lease->status === 'released'
                        ? ($release?->settlement_status === 'pending'
                            ? max(0, (float) $release->net_settlement_amount)
                            : 0)
                        : ($lease->financial_status === 'settled'
                            ? 0
                            : max(0, round((float) ($lease->scheduled_amount ?? 0) - (float) ($lease->paid_amount ?? 0), 2)))
                );
                $lease->setAttribute(
                    'receivable_amount',
                    $lease->status === 'released' && $release?->settlement_status === 'pending'
                        ? abs(min(0, (float) $release->net_settlement_amount))
                        : 0
                );
            });

        return response()->json(['status' => 'success', 'data' => $leases]);
    }

    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->normalizeCurrency($request);
        $data = $request->validate($this->rules());
        $lease = $this->leases->create($vehicle, $data, $request->user()?->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle finance/lease contract draft created without changing the vehicle owner.',
            'data' => $this->leases->summary($lease),
        ], 201);
    }

    public function show(VehicleLease $vehicleLease): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->leases->summary($vehicleLease)]);
    }

    public function update(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        $this->normalizeCurrency($request);
        $data = $request->validate($this->rules($vehicleLease));

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle lease draft updated.',
            'data' => $this->leases->updateDraft($vehicleLease, $data, $request->user()?->id),
        ]);
    }

    public function activate(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Finance/lease contract activated and payment schedule generated. Vehicle ownership was not changed.',
            'data' => $this->leases->activate($vehicleLease, $request->user()?->id),
        ]);
    }

    public function recordPayment(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Lease payment recorded and allocated to the oldest outstanding instalment.',
            'data' => $this->leases->recordPayment($vehicleLease, $data, $request->user()?->id),
        ]);
    }

    public function release(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        $data = $request->validate([
            'release_type' => ['required', Rule::in(['scheduled_return', 'early_termination', 'repossession', 'voluntary_surrender', 'other'])],
            'effective_at' => ['required', 'date', 'before_or_equal:now'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'condition_status' => ['nullable', Rule::in(['excellent', 'good', 'fair', 'damaged'])],
            'location' => ['nullable', 'string', 'max:255'],
            'released_to' => ['nullable', 'string', 'max:255'],
            'termination_charge' => ['nullable', 'numeric', 'min:0'],
            'deposit_credit' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:3000'],
            'condition_notes' => ['nullable', 'string', 'max:3000'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle release recorded without removing its lease history.',
            'data' => $this->leases->release($vehicleLease, $data, $request->user()->id),
        ]);
    }

    public function reversePayment(Request $request, VehicleLease $vehicleLease, string $payment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:3000'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Lease payment reversed with its original record retained.',
            'data' => $this->leases->reversePayment($vehicleLease, $payment, $data['reason'], $request->user()->id),
        ]);
    }

    public function recordDepositDisposition(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        $data = $request->validate([
            'disposition_type' => ['required', Rule::in(['return_received', 'forfeited', 'offset'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => [
                'required',
                Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'online', 'offset', 'not_applicable', 'other']),
            ],
            'reference' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Refundable-deposit disposition recorded.',
            'data' => $this->leases->recordDepositDisposition(
                $vehicleLease,
                $data,
                $request->user()->id
            ),
        ]);
    }

    public function reverseDepositDisposition(
        Request $request,
        VehicleLease $vehicleLease,
        string $depositDisposition
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:3000'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Refundable-deposit disposition reversed with its original record retained.',
            'data' => $this->leases->reverseDepositDisposition(
                $vehicleLease,
                $depositDisposition,
                $data['reason'],
                $request->user()->id
            ),
        ]);
    }

    public function settleRelease(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        $data = $request->validate([
            'settled_at' => ['required', 'date', 'before_or_equal:now'],
            'direction' => ['required', Rule::in(['payable_to_provider', 'receivable_from_provider'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'online', 'offset', 'other'])],
            'settlement_reference' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vehicle_lease_releases', 'settlement_reference')
                    ->ignore($vehicleLease->release()->value('id')),
            ],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Lease release settlement recorded.',
            'data' => $this->leases->settleRelease($vehicleLease, $data, $request->user()->id),
        ]);
    }

    public function closeFinance(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        $data = $request->validate([
            'closed_at' => ['required', 'date', 'before_or_equal:now'],
            'closure_reference' => ['required', 'string', 'max:255', 'unique:vehicle_leases,closure_reference'],
            'closure_document_id' => ['required', 'uuid', 'exists:documents,id'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Finance contract closed. Vehicle owner and availability remain unchanged.',
            'data' => $this->leases->closeFinance($vehicleLease, $data, $request->user()->id),
        ]);
    }

    public function transferOwnership(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        $data = $request->validate([
            'new_owner_id' => [
                'nullable',
                Rule::prohibitedIf(fn () => $request->input('new_ownership_type') === 'company_owned'),
                Rule::requiredIf(fn () => $request->input('new_ownership_type') !== 'company_owned'),
                'uuid',
                'exists:vehicle_owners,id',
            ],
            'new_ownership_type' => ['required', Rule::in(['company_owned', 'package_fleet', 'outside_call_taxi', 'rented_asset', 'leased_asset'])],
            'transfer_type' => ['required', Rule::in(['title_transfer', 'buyout'])],
            'effective_at' => ['required', 'date', 'before_or_equal:now'],
            'reference' => [
                'required', 'string', 'max:255',
                Rule::unique('vehicle_ownership_histories', 'reference'),
                Rule::unique('vehicle_leases', 'closure_reference'),
            ],
            'closure_document_id' => ['required', 'uuid', 'exists:documents,id'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Verified ownership transfer recorded with immutable ownership history.',
            'data' => $this->leases->transferOwnership($vehicleLease, $data, $request->user()->id),
        ]);
    }

    public function renew(Request $request, VehicleLease $vehicleLease): JsonResponse
    {
        $this->normalizeCurrency($request);
        $data = $request->validate($this->rules());

        return response()->json([
            'status' => 'success',
            'message' => 'Renewal created as a new draft lease; the previous lease remains unchanged.',
            'data' => $this->leases->renew($vehicleLease, $data, $request->user()?->id),
        ], 201);
    }

    private function rules(?VehicleLease $lease = null): array
    {
        return [
            'finance_provider_id' => ['required', 'uuid', 'exists:vehicle_finance_providers,id'],
            'agreement_number' => [
                'nullable', 'string', 'max:120',
                Rule::unique('vehicle_leases', 'agreement_number')->ignore($lease?->id),
            ],
            'contract_type' => ['required', Rule::in(['vehicle_loan', 'hire_purchase', 'finance_lease', 'operating_lease'])],
            'title_holder' => ['nullable', 'string', 'max:255'],
            'lien_reference' => ['nullable', 'string', 'max:255'],
            'ownership_transfer_required' => ['nullable', 'boolean'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'first_payment_date' => ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:end_date'],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->whereNull('deleted_at'),
            ],
            'financed_amount' => ['required', 'numeric', 'min:0.01'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            'down_payment_paid_date' => ['nullable', 'date', 'before_or_equal:today'],
            'down_payment_method' => ['nullable', 'string', 'max:40'],
            'down_payment_reference' => ['nullable', 'string', 'max:255'],
            'refundable_deposit' => ['nullable', 'numeric', 'min:0'],
            'deposit_paid_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit_paid_date' => ['nullable', 'date', 'before_or_equal:today'],
            'deposit_payment_method' => ['nullable', 'string', 'max:40'],
            'deposit_payment_reference' => ['nullable', 'string', 'max:255'],
            'installment_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_frequency' => ['required', Rule::in(['monthly', 'quarterly', 'semiannual', 'annual'])],
            'installment_count' => ['required', 'integer', 'min:1', 'max:600'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'balloon_payment' => ['nullable', 'numeric', 'min:0'],
            'reminder_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function normalizeCurrency(Request $request): void
    {
        if ($request->exists('currency')) {
            $request->merge([
                'currency' => strtoupper(trim((string) $request->input('currency'))),
            ]);
        }
    }
}
