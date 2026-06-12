<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\DriverHireSettlementRequest;
use App\Http\Requests\Driver\DriverIouAdvanceRequest;
use App\Http\Requests\Driver\DriverSettlementExpenseRequest;
use App\Http\Resources\Driver\DriverHireSettlementResource;
use App\Http\Resources\Driver\DriverIouAdvanceResource;
use App\Http\Resources\Driver\DriverSettlementExpenseResource;
use App\Models\Driver\DriverHireSettlement;
use App\Services\DriverHireSettlementService;
use Illuminate\Http\Request;

class DriverHireSettlementController extends Controller
{
    public function __construct(private DriverHireSettlementService $settlementService)
    {
    }

    public function index(Request $request)
    {
        $query = DriverHireSettlement::with(['driver.user', 'vehicle', 'vehicleGroup', 'booking', 'driverLog', 'expenses', 'iouAdvances'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('driver_id'), fn ($q) => $q->where('driver_id', $request->driver_id))
            ->when($request->filled('booking_id'), fn ($q) => $q->where('booking_id', $request->booking_id))
            ->when($request->filled('vehicle_group_id'), fn ($q) => $q->where('vehicle_group_id', $request->vehicle_group_id))
            ->orderByDesc('created_at');

        return DriverHireSettlementResource::collection($query->paginate($request->per_page ?? 25));
    }

    public function store(DriverHireSettlementRequest $request)
    {
        $settlement = DriverHireSettlement::create(array_merge([
            'status' => 'draft',
        ], $request->validated()));

        $settlement = $this->settlementService->applyBattaRule($settlement, $request->input('batta_category'));

        return response()->json([
            'status' => 'success',
            'message' => 'Driver hire settlement created',
            'data' => new DriverHireSettlementResource($this->loadSettlement($settlement)),
        ], 201);
    }

    public function show(DriverHireSettlement $driverHireSettlement)
    {
        return response()->json([
            'status' => 'success',
            'data' => new DriverHireSettlementResource($this->loadSettlement($driverHireSettlement)),
        ]);
    }

    public function update(DriverHireSettlementRequest $request, DriverHireSettlement $driverHireSettlement)
    {
        abort_if(in_array($driverHireSettlement->status, ['accounts_finalized', 'paid', 'recovered'], true), 422, 'Finalized settlements cannot be edited.');

        $driverHireSettlement->update($request->validated());
        $settlement = $this->settlementService->applyBattaRule($driverHireSettlement->refresh(), $request->input('batta_category'));

        return response()->json([
            'status' => 'success',
            'message' => 'Driver hire settlement updated',
            'data' => new DriverHireSettlementResource($this->loadSettlement($settlement)),
        ]);
    }

    public function addExpense(DriverSettlementExpenseRequest $request, DriverHireSettlement $driverHireSettlement)
    {
        abort_if(in_array($driverHireSettlement->status, ['accounts_finalized', 'paid', 'recovered'], true), 422, 'Finalized settlements cannot be edited.');

        $expense = $driverHireSettlement->expenses()->create($request->validated());
        $this->settlementService->recalculate($driverHireSettlement->refresh());

        return response()->json([
            'status' => 'success',
            'message' => 'Expense added',
            'data' => new DriverSettlementExpenseResource($expense),
        ], 201);
    }

    public function updateExpense(DriverSettlementExpenseRequest $request, DriverHireSettlement $driverHireSettlement, string $expenseId)
    {
        abort_if(in_array($driverHireSettlement->status, ['accounts_finalized', 'paid', 'recovered'], true), 422, 'Finalized settlements cannot be edited.');

        $expense = $driverHireSettlement->expenses()->whereKey($expenseId)->firstOrFail();
        $data = $request->validated();
        if (isset($data['status']) && in_array($data['status'], ['approved', 'partially_approved', 'rejected'], true)) {
            $data['reviewed_by'] = $request->user()->id;
            $data['reviewed_at'] = now();
            if ($data['status'] === 'approved' && !isset($data['approved_amount'])) {
                $data['approved_amount'] = $expense->claimed_amount;
            }
            if ($data['status'] === 'rejected') {
                $data['approved_amount'] = 0;
            }
        }
        $expense->update($data);
        $this->settlementService->recalculate($driverHireSettlement->refresh());

        return response()->json([
            'status' => 'success',
            'message' => 'Expense updated',
            'data' => new DriverSettlementExpenseResource($expense->refresh()),
        ]);
    }

    public function addIou(DriverIouAdvanceRequest $request, DriverHireSettlement $driverHireSettlement)
    {
        abort_if(in_array($driverHireSettlement->status, ['accounts_finalized', 'paid', 'recovered'], true), 422, 'Finalized settlements cannot be edited.');

        $advance = $driverHireSettlement->iouAdvances()->create(array_merge($request->validated(), [
            'booking_id' => $driverHireSettlement->booking_id,
            'driver_id' => $driverHireSettlement->driver_id,
            'issued_by' => $request->user()->id,
        ]));
        $this->settlementService->recalculate($driverHireSettlement->refresh());

        return response()->json([
            'status' => 'success',
            'message' => 'IOU advance added',
            'data' => new DriverIouAdvanceResource($advance),
        ], 201);
    }

    public function submit(DriverHireSettlement $driverHireSettlement)
    {
        $settlement = $this->settlementService->markSubmitted($driverHireSettlement);

        return response()->json([
            'status' => 'success',
            'message' => 'Settlement submitted',
            'data' => new DriverHireSettlementResource($this->loadSettlement($settlement)),
        ]);
    }

    public function opsReview(Request $request, DriverHireSettlement $driverHireSettlement)
    {
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
            'rejection_reason' => ['nullable', 'required_if:approved,false', 'string'],
        ]);

        $driverHireSettlement->update([
            'status' => $data['approved'] ? 'ops_reviewed' : 'rejected',
            'ops_reviewed_at' => now(),
            'ops_reviewed_by' => $request->user()->id,
            'ops_review_notes' => $data['notes'] ?? null,
            'rejection_reason' => $data['rejection_reason'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $data['approved'] ? 'Settlement reviewed by operations' : 'Settlement rejected',
            'data' => new DriverHireSettlementResource($this->loadSettlement($driverHireSettlement->refresh())),
        ]);
    }

    public function finalizeAccounts(Request $request, DriverHireSettlement $driverHireSettlement)
    {
        abort_unless($driverHireSettlement->status === 'ops_reviewed', 422, 'Operations review is required before accounts finalization.');

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $driverHireSettlement = $this->settlementService->recalculate($driverHireSettlement);
        $driverHireSettlement->update([
            'status' => $driverHireSettlement->final_balance >= 0 ? 'accounts_finalized' : 'recovery_pending',
            'accounts_finalized_at' => now(),
            'accounts_finalized_by' => $request->user()->id,
            'accounts_notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Settlement finalized by accounts',
            'data' => new DriverHireSettlementResource($this->loadSettlement($driverHireSettlement->refresh())),
        ]);
    }

    public function markPaid(DriverHireSettlement $driverHireSettlement)
    {
        abort_unless($driverHireSettlement->status === 'accounts_finalized' && $driverHireSettlement->final_balance >= 0, 422, 'Only payable finalized settlements can be marked paid.');

        $driverHireSettlement->update(['status' => 'paid', 'paid_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'Settlement marked paid', 'data' => new DriverHireSettlementResource($this->loadSettlement($driverHireSettlement->refresh()))]);
    }

    public function markRecovered(DriverHireSettlement $driverHireSettlement)
    {
        abort_unless($driverHireSettlement->status === 'recovery_pending' && $driverHireSettlement->final_balance < 0, 422, 'Only recoverable settlements can be marked recovered.');

        $driverHireSettlement->update(['status' => 'recovered', 'recovered_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'Settlement marked recovered', 'data' => new DriverHireSettlementResource($this->loadSettlement($driverHireSettlement->refresh()))]);
    }

    public function destroy(DriverHireSettlement $driverHireSettlement)
    {
        abort_if(in_array($driverHireSettlement->status, ['accounts_finalized', 'paid', 'recovered'], true), 422, 'Finalized settlements cannot be deleted.');

        $driverHireSettlement->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Driver hire settlement deleted',
        ]);
    }

    public function dashboard()
    {
        $base = DriverHireSettlement::query();

        return response()->json([
            'status' => 'success',
            'data' => [
                'pending_ops_review' => (clone $base)->where('status', 'submitted')->count(),
                'pending_accounts_finalization' => (clone $base)->where('status', 'ops_reviewed')->count(),
                'payable_total' => (float) (clone $base)->whereIn('status', ['accounts_finalized', 'paid'])->where('final_balance', '>', 0)->sum('final_balance'),
                'recoverable_total' => abs((float) (clone $base)->whereIn('status', ['recovery_pending', 'recovered'])->where('final_balance', '<', 0)->sum('final_balance')),
                'iou_outstanding' => (float) (clone $base)->whereIn('status', ['draft', 'submitted', 'ops_reviewed', 'recovery_pending'])->sum('iou_total'),
            ],
        ]);
    }

    private function loadSettlement(DriverHireSettlement $settlement): DriverHireSettlement
    {
        return $settlement->load(['driver.user', 'vehicle', 'vehicleGroup', 'booking', 'bookingItem', 'driverLog', 'battaRule', 'expenses', 'iouAdvances']);
    }
}
