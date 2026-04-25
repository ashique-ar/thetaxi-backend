<?php
// app/Http/Controllers/Api/Vehicle/VehicleContractTypeController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleContractType;
use App\Http\Requests\Vehicle\VehicleContractType\CreateVehicleContractTypeRequest;
use App\Http\Requests\Vehicle\VehicleContractType\UpdateVehicleContractTypeRequest;
use App\Http\Resources\Vehicle\VehicleContractTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleContractTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-contract-types.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-contract-types.create')->only(['store']);
        $this->middleware('permission:vehicle-contract-types.edit')->only(['update']);
        $this->middleware('permission:vehicle-contract-types.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleContractType::query();
        if ($request->filled('search')) {
            $q->whereLikeInsensitive('name', $request->search);
        }
        return VehicleContractTypeResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleContractTypeRequest $request): JsonResponse
    {
        $ctr = VehicleContractType::create($request->validated() + ['created_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Contract type created',
            'data' => ['contractType' => new VehicleContractTypeResource($ctr)]
        ], 201);
    }

    public function show(VehicleContractType $vehicleContractType): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['contractType' => new VehicleContractTypeResource($vehicleContractType)]
        ]);
    }

    public function update(UpdateVehicleContractTypeRequest $request, VehicleContractType $vehicleContractType): JsonResponse
    {
        $vehicleContractType->update($request->validated() + ['updated_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Contract type updated',
            'data' => ['contractType' => new VehicleContractTypeResource($vehicleContractType)]
        ]);
    }

    public function destroy(VehicleContractType $vehicleContractType): JsonResponse
    {
        $vehicleContractType->delete();
        return response()->json(['status' => 'success', 'message' => 'Contract type deleted']);
    }
}
