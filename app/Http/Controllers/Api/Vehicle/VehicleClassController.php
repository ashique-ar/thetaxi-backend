<?php
// app/Http/Controllers/Api/Vehicle/VehicleClassController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleClass;
use App\Http\Requests\Vehicle\VehicleClass\CreateVehicleClassRequest;
use App\Http\Requests\Vehicle\VehicleClass\UpdateVehicleClassRequest;
use App\Http\Resources\Vehicle\VehicleClassResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleClassController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-classes.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-classes.create')->only(['store']);
        $this->middleware('permission:vehicle-classes.edit')->only(['update']);
        $this->middleware('permission:vehicle-classes.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleClass::withInactive();
        if ($request->filled('search')) {
            $q->whereLikeInsensitive('name', $request->search);
        }
        
        // Only apply is_active filter if explicitly set
        if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
            $q->where('is_active', $request->boolean('is_active'));
        }
        
        return VehicleClassResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleClassRequest $request): JsonResponse
    {
        $class = VehicleClass::create($request->validated() + ['created_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Class created',
            'data' => ['class' => new VehicleClassResource($class)]
        ], 201);
    }

    public function show(VehicleClass $vehicleClass): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['class' => new VehicleClassResource($vehicleClass)]
        ]);
    }

    public function update(UpdateVehicleClassRequest $request, VehicleClass $vehicleClass): JsonResponse
    {
        $vehicleClass->update($request->validated() + ['updated_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Class updated',
            'data' => ['class' => new VehicleClassResource($vehicleClass)]
        ]);
    }

    public function destroy(VehicleClass $vehicleClass): JsonResponse
    {
        $vehicleClass->delete();
        return response()->json(['status' => 'success', 'message' => 'Class deleted']);
    }
}
