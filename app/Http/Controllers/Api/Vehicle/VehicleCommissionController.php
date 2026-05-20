<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleCommission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleCommissionController extends Controller
{
    public function index(Vehicle $vehicle): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $vehicle->commissions()
                ->orderByDesc('effective_from')
                ->get(),
        ]);
    }

    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $this->validatedData($request);
        $data['vehicle_id'] = $vehicle->id;
        $data['created_user_id'] = $request->user()->id;

        $commission = VehicleCommission::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle commission created',
            'data' => $commission,
        ], 201);
    }

    public function update(Request $request, Vehicle $vehicle, VehicleCommission $commission): JsonResponse
    {
        abort_unless($commission->vehicle_id === $vehicle->id, 404);

        $data = $this->validatedData($request, true);
        $data['updated_user_id'] = $request->user()->id;
        $commission->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle commission updated',
            'data' => $commission,
        ]);
    }

    public function destroy(Vehicle $vehicle, VehicleCommission $commission): JsonResponse
    {
        abort_unless($commission->vehicle_id === $vehicle->id, 404);
        $commission->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle commission deleted',
        ]);
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : '';

        return $request->validate([
            'commission_type' => [$prefix . 'required', 'string', 'in:percentage,fixed_amount'],
            'rate' => ['nullable', 'required_if:commission_type,percentage', 'numeric', 'min:0'],
            'amount' => ['nullable', 'required_if:commission_type,fixed_amount', 'numeric', 'min:0'],
            'effective_from' => [$prefix . 'required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
