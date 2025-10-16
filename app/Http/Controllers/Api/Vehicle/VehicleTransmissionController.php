<?php
// app/Http/Controllers/Api/Vehicle/VehicleTransmissionController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleTransmission;
use App\Http\Requests\Vehicle\VehicleTransmission\CreateVehicleTransmissionRequest;
use App\Http\Requests\Vehicle\VehicleTransmission\UpdateVehicleTransmissionRequest;
use App\Http\Resources\Vehicle\VehicleTransmissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleTransmissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-transmissions.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-transmissions.create')->only(['store']);
        $this->middleware('permission:vehicle-transmissions.edit')->only(['update']);
        $this->middleware('permission:vehicle-transmissions.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleTransmission::query();
        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->search . '%');
        }
        return VehicleTransmissionResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleTransmissionRequest $request): JsonResponse
    {
        $vt = VehicleTransmission::create($request->validated() + ['created_user_id' => $request->user()->id]);
        return response()->json(['status' => 'success', 'message' => 'Transmission created', 'data' => ['transmission' => new VehicleTransmissionResource($vt)]], 201);
    }

    public function show(VehicleTransmission $vehicleTransmission): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => ['transmission' => new VehicleTransmissionResource($vehicleTransmission)]]);
    }

    public function update(UpdateVehicleTransmissionRequest $request, VehicleTransmission $vehicleTransmission): JsonResponse
    {
        $vehicleTransmission->update($request->validated() + ['updated_user_id' => $request->user()->id]);
        return response()->json(['status' => 'success', 'message' => 'Transmission updated', 'data' => ['transmission' => new VehicleTransmissionResource($vehicleTransmission)]]);
    }

    public function destroy(VehicleTransmission $vehicleTransmission): JsonResponse
    {
        $vehicleTransmission->delete();
        return response()->json(['status' => 'success', 'message' => 'Transmission deleted']);
    }
}
