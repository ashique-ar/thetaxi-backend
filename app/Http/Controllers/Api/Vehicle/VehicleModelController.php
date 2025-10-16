<?php
// app/Http/Controllers/Api/Vehicle/VehicleModelController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleModel;
use App\Http\Requests\Vehicle\VehicleModel\CreateVehicleModelRequest;
use App\Http\Requests\Vehicle\VehicleModel\UpdateVehicleModelRequest;
use App\Http\Resources\Vehicle\VehicleModelResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleModelController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-models.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-models.create')->only(['store']);
        $this->middleware('permission:vehicle-models.edit')->only(['update']);
        $this->middleware('permission:vehicle-models.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleModel::query();
        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('make_id')) {
            $q->where('make_id', $request->make_id);
        }

        return VehicleModelResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleModelRequest $request): JsonResponse
    {
        $vm = VehicleModel::create($request->validated() + ['created_user_id' => $request->user()->id]);
        return response()->json(['status' => 'success', 'message' => 'Model created', 'data' => ['model' => new VehicleModelResource($vm)]], 201);
    }

    public function show(VehicleModel $vehicleModel): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => ['model' => new VehicleModelResource($vehicleModel)]]);
    }

    public function update(UpdateVehicleModelRequest $request, VehicleModel $vehicleModel): JsonResponse
    {
        $vehicleModel->update($request->validated() + ['updated_user_id' => $request->user()->id]);
        return response()->json(['status' => 'success', 'message' => 'Model updated', 'data' => ['model' => new VehicleModelResource($vehicleModel)]]);
    }

    public function destroy(VehicleModel $vehicleModel): JsonResponse
    {
        $vehicleModel->delete();
        return response()->json(['status' => 'success', 'message' => 'Model deleted']);
    }
}
