<?php
// app/Http/Controllers/Api/Vehicle/VehicleDistanceMultiplierController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleDistanceMultiplier;
use App\Http\Requests\Vehicle\VehicleDistanceMultiplier\CreateVehicleDistanceMultiplierRequest;
use App\Http\Requests\Vehicle\VehicleDistanceMultiplier\UpdateVehicleDistanceMultiplierRequest;
use App\Http\Resources\Vehicle\VehicleDistanceMultiplierResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleDistanceMultiplierController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-distance-multipliers.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-distance-multipliers.create')->only(['store']);
        $this->middleware('permission:vehicle-distance-multipliers.edit')->only(['update']);
        $this->middleware('permission:vehicle-distance-multipliers.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleDistanceMultiplier::query();
        return VehicleDistanceMultiplierResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleDistanceMultiplierRequest $request): JsonResponse
    {
        $dm = VehicleDistanceMultiplier::create($request->validated() + ['created_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Distance multiplier created',
            'data' => ['multiplier' => new VehicleDistanceMultiplierResource($dm)]
        ], 201);
    }

    public function show(VehicleDistanceMultiplier $vehicleDistanceMultiplier): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['multiplier' => new VehicleDistanceMultiplierResource($vehicleDistanceMultiplier)]
        ]);
    }

    public function update(UpdateVehicleDistanceMultiplierRequest $request, VehicleDistanceMultiplier $vehicleDistanceMultiplier): JsonResponse
    {
        $vehicleDistanceMultiplier->update($request->validated() + ['updated_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Distance multiplier updated',
            'data' => ['multiplier' => new VehicleDistanceMultiplierResource($vehicleDistanceMultiplier)]
        ]);
    }

    public function destroy(VehicleDistanceMultiplier $vehicleDistanceMultiplier): JsonResponse
    {
        $vehicleDistanceMultiplier->delete();
        return response()->json(['status' => 'success', 'message' => 'Distance multiplier deleted']);
    }
}
