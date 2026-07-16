<?php
// app/Http/Controllers/Api/Vehicle/VehicleCategoryController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleCategory;
use App\Http\Requests\Vehicle\VehicleCategory\CreateVehicleCategoryRequest;
use App\Http\Requests\Vehicle\VehicleCategory\UpdateVehicleCategoryRequest;
use App\Http\Resources\Vehicle\VehicleCategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleCategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-categories.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-categories.create')->only(['store']);
        $this->middleware('permission:vehicle-categories.edit')->only(['update']);
        $this->middleware('permission:vehicle-categories.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleCategory::withInactive()->withCount('vehicles');
        if ($request->filled('search')) {
            $q->whereLikeInsensitive('name', $request->search);
        }
        
        // Only apply is_active filter if explicitly set
        if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
            $q->where('is_active', $request->boolean('is_active'));
        }
        
        return VehicleCategoryResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleCategoryRequest $request): JsonResponse
    {
        $cat = VehicleCategory::create($request->validated() + ['created_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Category created',
            'data' => ['category' => new VehicleCategoryResource($cat)]
        ], 201);
    }

    public function show(VehicleCategory $vehicleCategory): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['category' => new VehicleCategoryResource($vehicleCategory)]
        ]);
    }

    public function update(UpdateVehicleCategoryRequest $request, VehicleCategory $vehicleCategory): JsonResponse
    {
        $vehicleCategory->update($request->validated() + ['updated_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Category updated',
            'data' => ['category' => new VehicleCategoryResource($vehicleCategory)]
        ]);
    }

    public function destroy(VehicleCategory $vehicleCategory): JsonResponse
    {
        $vehicleCategory->delete();

        return response()->json(['status' => 'success', 'message' => 'Category deleted']);
    }
}
