<?php
// app/Http/Controllers/Api/Vehicle/VehicleOwnerTypeController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleOwnerType;
use App\Http\Requests\Vehicle\VehicleOwnerType\CreateVehicleOwnerTypeRequest;
use App\Http\Requests\Vehicle\VehicleOwnerType\UpdateVehicleOwnerTypeRequest;
use App\Http\Resources\Vehicle\VehicleOwnerTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleOwnerTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-owner-types.view')->only(['index','show']);
        $this->middleware('permission:vehicle-owner-types.create')->only(['store']);
        $this->middleware('permission:vehicle-owner-types.edit')->only(['update']);
        $this->middleware('permission:vehicle-owner-types.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleOwnerType::query();
        if ($request->filled('search')) {
            $q->where('name','like','%'.$request->search.'%');
        }
        return VehicleOwnerTypeResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleOwnerTypeRequest $request): JsonResponse
    {
        $vot = VehicleOwnerType::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Owner type created','data'=>['type'=>new VehicleOwnerTypeResource($vot)]],201);
    }

    public function show(VehicleOwnerType $vehicleOwnerType): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['type'=>new VehicleOwnerTypeResource($vehicleOwnerType)]]);
    }

    public function update(UpdateVehicleOwnerTypeRequest $request, VehicleOwnerType $vehicleOwnerType): JsonResponse
    {
        $vehicleOwnerType->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Owner type updated','data'=>['type'=>new VehicleOwnerTypeResource($vehicleOwnerType)]]);
    }

    public function destroy(VehicleOwnerType $vehicleOwnerType): JsonResponse
    {
        $vehicleOwnerType->delete();
        return response()->json(['status'=>'success','message'=>'Owner type deleted']);
    }
}
