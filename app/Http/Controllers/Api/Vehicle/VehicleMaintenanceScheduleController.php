<?php
// app/Http/Controllers/Api/Vehicle/VehicleMaintenanceScheduleController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleMaintenanceSchedule;
use App\Http\Requests\Vehicle\VehicleMaintenanceSchedule\CreateVehicleMaintenanceScheduleRequest;
use App\Http\Requests\Vehicle\VehicleMaintenanceSchedule\UpdateVehicleMaintenanceScheduleRequest;
use App\Http\Resources\Vehicle\VehicleMaintenanceScheduleResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleMaintenanceScheduleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicle-maintenance-schedules.view')->only(['index','show']);
        $this->middleware('permission:vehicle-maintenance-schedules.create')->only(['store']);
        $this->middleware('permission:vehicle-maintenance-schedules.edit')->only(['update']);
        $this->middleware('permission:vehicle-maintenance-schedules.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleMaintenanceSchedule::query();
        return VehicleMaintenanceScheduleResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleMaintenanceScheduleRequest $request): JsonResponse
    {
        $sch = VehicleMaintenanceSchedule::create($request->validated()+['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Schedule created','data'=>['schedule'=>new VehicleMaintenanceScheduleResource($sch)]],201);
    }

    public function show(VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['schedule'=>new VehicleMaintenanceScheduleResource($vehicleMaintenanceSchedule)]]);
    }

    public function update(UpdateVehicleMaintenanceScheduleRequest $request, VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): JsonResponse
    {
        $vehicleMaintenanceSchedule->update($request->validated()+['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Schedule updated','data'=>['schedule'=>new VehicleMaintenanceScheduleResource($vehicleMaintenanceSchedule)]]);
    }

    public function destroy(VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): JsonResponse
    {
        $vehicleMaintenanceSchedule->delete();
        return response()->json(['status'=>'success','message'=>'Schedule deleted']);
    }
}
