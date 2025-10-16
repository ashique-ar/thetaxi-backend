<?php
// app/Http/Controllers/Api/Vehicle/VehicleInsuranceController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleInsurance;
use App\Http\Requests\Vehicle\VehicleInsurance\CreateVehicleInsuranceRequest;
use App\Http\Requests\Vehicle\VehicleInsurance\UpdateVehicleInsuranceRequest;
use App\Http\Resources\Vehicle\VehicleInsuranceResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleInsuranceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-insurances.view')->only(['index','show']);
        $this->middleware('permission:vehicle-insurances.create')->only(['store']);
        $this->middleware('permission:vehicle-insurances.edit')->only(['update']);
        $this->middleware('permission:vehicle-insurances.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleInsurance::with(['vehicle', 'provider']);
        return VehicleInsuranceResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleInsuranceRequest $request): JsonResponse
    {
        $ins = VehicleInsurance::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Insurance created','data'=>['insurance'=>new VehicleInsuranceResource($ins)]],201);
    }

    public function show(VehicleInsurance $vehicleInsurance): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['insurance'=>new VehicleInsuranceResource($vehicleInsurance)]]);
    }

    public function update(UpdateVehicleInsuranceRequest $request, VehicleInsurance $vehicleInsurance): JsonResponse
    {
        $vehicleInsurance->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Insurance updated','data'=>['insurance'=>new VehicleInsuranceResource($vehicleInsurance)]]);
    }

    public function destroy(VehicleInsurance $vehicleInsurance): JsonResponse
    {
        $vehicleInsurance->delete();
        return response()->json(['status'=>'success','message'=>'Insurance deleted']);
    }
}
