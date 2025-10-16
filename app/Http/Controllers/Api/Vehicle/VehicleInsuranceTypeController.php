<?php
// app/Http/Controllers/Api/Vehicle/VehicleInsuranceTypeController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleInsuranceType;
use App\Http\Requests\Vehicle\VehicleInsuranceType\CreateVehicleInsuranceTypeRequest;
use App\Http\Requests\Vehicle\VehicleInsuranceType\UpdateVehicleInsuranceTypeRequest;
use App\Http\Resources\Vehicle\VehicleInsuranceTypeResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleInsuranceTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-insurance-types.view')->only(['index','show']);
        $this->middleware('permission:vehicle-insurance-types.create')->only(['store']);
        $this->middleware('permission:vehicle-insurance-types.edit')->only(['update']);
        $this->middleware('permission:vehicle-insurance-types.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {   $q = VehicleInsuranceType::query();
        return VehicleInsuranceTypeResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleInsuranceTypeRequest $request): JsonResponse
    {
        $type = VehicleInsuranceType::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Type created','data'=>['type'=>new VehicleInsuranceTypeResource($type)]],201);
    }

    public function show(VehicleInsuranceType $vehicleInsuranceType): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['type'=>new VehicleInsuranceTypeResource($vehicleInsuranceType)]]);
    }

    public function update(UpdateVehicleInsuranceTypeRequest $request, VehicleInsuranceType $vehicleInsuranceType): JsonResponse
    {
        $vehicleInsuranceType->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Type updated','data'=>['type'=>new VehicleInsuranceTypeResource($vehicleInsuranceType)]]);
    }

    public function destroy(VehicleInsuranceType $vehicleInsuranceType): JsonResponse
    {
        $vehicleInsuranceType->delete();
        return response()->json(['status'=>'success','message'=>'Type deleted']);
    }
}
