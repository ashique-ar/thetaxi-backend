<?php
// app/Http/Controllers/Api/Vehicle/VehicleGroupController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleGroup;
use App\Http\Requests\Vehicle\VehicleGroup\CreateVehicleGroupRequest;
use App\Http\Requests\Vehicle\VehicleGroup\UpdateVehicleGroupRequest;
use App\Http\Resources\Vehicle\VehicleGroupResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleGroupController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-groups.view')->only(['index', 'show']);
        $this->middleware('permission:vehicle-groups.create')->only(['store']);
        $this->middleware('permission:vehicle-groups.edit')->only(['update']);
        $this->middleware('permission:vehicle-groups.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleGroup::withInactive()->with(
            'grade',
            'make',
            'model',
            'transmission',
            'fuelType',
            'category',
            'class'
        );
        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->search . '%');
        }

        $filters = [
            'grade_id',
            'class_id',
            'fuel_type_id',
            'transmission_id',
            'category_id',
            'model_id',
            'is_premium',
        ];

        foreach ($filters as $field) {
            if ($request->filled($field)) {
                // cast booleans if needed
                $value = in_array($field, ['is_active', 'is_premium'])
                    ? filter_var($request->get($field), FILTER_VALIDATE_BOOL)
                    : $request->get($field);

                $q->where($field, $value);
            }
        }

        // Optional sorting
        if ($request->filled('sort') && $request->filled('direction')) {
            $direction = strtolower($request->direction) === 'desc' ? 'desc' : 'asc';
            $q->orderBy($request->sort, $direction);
        }

        $perPage = (int) $request->get('per_page', 15);
        $results = $q->paginate($perPage);
        return VehicleGroupResource::collection($results);
    }

    public function store(CreateVehicleGroupRequest $request): JsonResponse
    {
        $vg = VehicleGroup::create($request->validated() + ['created_user_id' => $request->user()->id]);
        return response()->json(['status' => 'success', 'message' => 'Group created', 'data' => ['group' => new VehicleGroupResource($vg)]], 201);
    }

    public function show(VehicleGroup $vehicleGroup): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'group' => new VehicleGroupResource($vehicleGroup->with(
                    'grade',
                    'make',
                    'model',
                    'transmission',
                    'fuelType',
                    'category',
                    'class'
                ))
            ]
        ]);
    }

    public function update(UpdateVehicleGroupRequest $request, VehicleGroup $vehicleGroup): JsonResponse
    {
        $vehicleGroup->update($request->validated());
        return response()->json([
            'status' => 'success',
            'message' => 'Group updated',
            'data' => [
                'group' => new VehicleGroupResource($vehicleGroup->load(
                    'grade',
                    'make',
                    'model',
                    'transmission',
                    'fuelType',
                    'category',
                    'class'
                ))
            ]
        ]);
    }

    public function destroy(VehicleGroup $vehicleGroup): JsonResponse
    {
        $vehicleGroup->delete();
        return response()->json(['status' => 'success', 'message' => 'Group deleted']);
    }
}
