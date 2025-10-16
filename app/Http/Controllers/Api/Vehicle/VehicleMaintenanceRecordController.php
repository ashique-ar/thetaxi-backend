<?php
// app/Http/Controllers/Api/Vehicle/VehicleMaintenanceRecordController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleMaintenanceRecord;
use App\Http\Requests\Vehicle\VehicleMaintenanceRecord\CreateVehicleMaintenanceRecordRequest;
use App\Http\Requests\Vehicle\VehicleMaintenanceRecord\UpdateVehicleMaintenanceRecordRequest;
use App\Http\Resources\Vehicle\VehicleMaintenanceRecordResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleMaintenanceRecordController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-maintenance-records.view')->only(['index','show']);
        $this->middleware('permission:vehicle-maintenance-records.create')->only(['store']);
        $this->middleware('permission:vehicle-maintenance-records.edit')->only(['update']);
        $this->middleware('permission:vehicle-maintenance-records.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        return VehicleMaintenanceRecordResource::collection(
            VehicleMaintenanceRecord::paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateVehicleMaintenanceRecordRequest $request): JsonResponse
    {
        $rec = VehicleMaintenanceRecord::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Record created','data'=>['record'=>new VehicleMaintenanceRecordResource($rec)]],201);
    }

    public function show(VehicleMaintenanceRecord $vehicleMaintenanceRecord): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['record'=>new VehicleMaintenanceRecordResource($vehicleMaintenanceRecord)]]);
    }

    public function update(UpdateVehicleMaintenanceRecordRequest $request, VehicleMaintenanceRecord $vehicleMaintenanceRecord): JsonResponse
    {
        $vehicleMaintenanceRecord->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Record updated','data'=>['record'=>new VehicleMaintenanceRecordResource($vehicleMaintenanceRecord)]]);
    }

    public function destroy(VehicleMaintenanceRecord $vehicleMaintenanceRecord): JsonResponse
    {
        $vehicleMaintenanceRecord->delete();
        return response()->json(['status'=>'success','message'=>'Record deleted']);
    }
}
