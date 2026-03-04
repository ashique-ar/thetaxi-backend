<?php
// app/Http/Controllers/Api/Vehicle/VehicleFuelTypeController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleFuelType;
use App\Http\Requests\Vehicle\VehicleFuelType\CreateVehicleFuelTypeRequest;
use App\Http\Requests\Vehicle\VehicleFuelType\UpdateVehicleFuelTypeRequest;
use App\Http\Resources\Vehicle\VehicleFuelTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleFuelTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-fuel-types.view')->only(['index','show']);
        $this->middleware('permission:vehicle-fuel-types.create')->only(['store']);
        $this->middleware('permission:vehicle-fuel-types.edit')->only(['update']);
        $this->middleware('permission:vehicle-fuel-types.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleFuelType::withInactive();
        if ($request->filled('search')) {
            $q->where('name','like','%'.$request->search.'%');
        }
        
        // Only apply is_active filter if explicitly set
        if ($request->filled('is_active') && $request->is_active !== '' && $request->is_active !== 'all') {
            $q->where('is_active', $request->boolean('is_active'));
        }
        
        return VehicleFuelTypeResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleFuelTypeRequest $request): JsonResponse
    {
        $ft = VehicleFuelType::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Fuel type created','data'=>['fuelType'=>new VehicleFuelTypeResource($ft)]],201);
    }

    public function show(VehicleFuelType $vehicleFuelType): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['fuelType'=>new VehicleFuelTypeResource($vehicleFuelType)]]);
    }

    public function update(UpdateVehicleFuelTypeRequest $request, VehicleFuelType $vehicleFuelType): JsonResponse
    {
        $vehicleFuelType->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Fuel type updated','data'=>['fuelType'=>new VehicleFuelTypeResource($vehicleFuelType)]]);
    }

    public function destroy(VehicleFuelType $vehicleFuelType): JsonResponse
    {
        $vehicleFuelType->delete();
        return response()->json(['status'=>'success','message'=>'Fuel type deleted']);
    }
}
