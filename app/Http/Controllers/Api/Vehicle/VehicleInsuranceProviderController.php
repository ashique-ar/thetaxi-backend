<?php
// app/Http/Controllers/Api/Vehicle/VehicleInsuranceProviderController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleInsuranceProvider;
use App\Http\Requests\Vehicle\VehicleInsuranceProvider\CreateVehicleInsuranceProviderRequest;
use App\Http\Requests\Vehicle\VehicleInsuranceProvider\UpdateVehicleInsuranceProviderRequest;
use App\Http\Resources\Vehicle\VehicleInsuranceProviderResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleInsuranceProviderController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-insurance-providers.view')->only(['index','show']);
        $this->middleware('permission:vehicle-insurance-providers.create')->only(['store']);
        $this->middleware('permission:vehicle-insurance-providers.edit')->only(['update']);
        $this->middleware('permission:vehicle-insurance-providers.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleInsuranceProvider::query();
        return VehicleInsuranceProviderResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleInsuranceProviderRequest $request): JsonResponse
    {
        $prov = VehicleInsuranceProvider::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Provider created','data'=>['provider'=>new VehicleInsuranceProviderResource($prov)]],201);
    }

    public function show(VehicleInsuranceProvider $vehicleInsuranceProvider): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['provider'=>new VehicleInsuranceProviderResource($vehicleInsuranceProvider)]]);
    }

    public function update(UpdateVehicleInsuranceProviderRequest $request, VehicleInsuranceProvider $vehicleInsuranceProvider): JsonResponse
    {
        $vehicleInsuranceProvider->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Provider updated','data'=>['provider'=>new VehicleInsuranceProviderResource($vehicleInsuranceProvider)]]);
    }

    public function destroy(VehicleInsuranceProvider $vehicleInsuranceProvider): JsonResponse
    {
        $vehicleInsuranceProvider->delete();
        return response()->json(['status'=>'success','message'=>'Provider deleted']);
    }
}
