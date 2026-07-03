<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleInsurance;
use App\Models\Vehicle\VehicleInsuranceClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleInsuranceClaimController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-insurances.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-insurances.create')->only('store');
        $this->middleware('permission:vehicle-insurances.edit')->only('update');
        $this->middleware('permission:vehicle-insurances.delete')->only('destroy');
    }

    public function index(Request $request, VehicleInsurance $vehicleInsurance): JsonResponse
    {
        $claims = $vehicleInsurance->claims()->latest('incident_date')->paginate($request->integer('per_page', 25));
        return response()->json(['status' => 'success', 'data' => $claims->items(), 'meta' => ['total' => $claims->total()]]);
    }

    public function store(Request $request, VehicleInsurance $vehicleInsurance): JsonResponse
    {
        $claim = $vehicleInsurance->claims()->create($this->validated($request));
        return response()->json(['status' => 'success', 'message' => 'Insurance claim created', 'data' => $claim], 201);
    }

    public function show(VehicleInsurance $vehicleInsurance, VehicleInsuranceClaim $claim): JsonResponse
    {
        $this->ensureOwnership($vehicleInsurance, $claim);
        return response()->json(['status' => 'success', 'data' => $claim]);
    }

    public function update(Request $request, VehicleInsurance $vehicleInsurance, VehicleInsuranceClaim $claim): JsonResponse
    {
        $this->ensureOwnership($vehicleInsurance, $claim);
        $claim->update($this->validated($request, $claim));
        return response()->json(['status' => 'success', 'message' => 'Insurance claim updated', 'data' => $claim->fresh()]);
    }

    public function destroy(VehicleInsurance $vehicleInsurance, VehicleInsuranceClaim $claim): JsonResponse
    {
        $this->ensureOwnership($vehicleInsurance, $claim);
        $claim->delete();
        return response()->json(['status' => 'success', 'message' => 'Insurance claim deleted']);
    }

    private function validated(Request $request, ?VehicleInsuranceClaim $claim = null): array
    {
        return $request->validate([
            'claim_number' => ['required', 'string', 'max:100', 'unique:vehicle_insurance_claims,claim_number,' . ($claim?->id ?? 'NULL')],
            'incident_date' => ['required', 'date'],
            'filed_date' => ['nullable', 'date', 'after_or_equal:incident_date'],
            'status' => ['required', 'in:draft,submitted,under_review,approved,rejected,settled,closed'],
            'claimed_amount' => ['nullable', 'numeric', 'min:0'],
            'approved_amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['required', 'string'],
            'resolution_notes' => ['nullable', 'string'],
        ]);
    }

    private function ensureOwnership(VehicleInsurance $insurance, VehicleInsuranceClaim $claim): void
    {
        abort_unless($claim->vehicle_insurance_id === $insurance->id, 404);
    }
}
