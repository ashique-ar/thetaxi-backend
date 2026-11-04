<?php
// app/Http/Controllers/Api/Vehicle/VehicleMaintenanceScheduleController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleMaintenanceSchedule;
use App\Models\Vehicle\Vehicle;
use App\Http\Requests\Vehicle\VehicleMaintenanceSchedule\CreateVehicleMaintenanceScheduleRequest;
use App\Http\Requests\Vehicle\VehicleMaintenanceSchedule\UpdateVehicleMaintenanceScheduleRequest;
use App\Http\Resources\Vehicle\VehicleMaintenanceScheduleResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
        $q = VehicleMaintenanceSchedule::query()->with('booking')
            ->when($request->filled('vehicle_id'), fn ($query) => $query->where('vehicle_id', $request->input('vehicle_id')));
        return VehicleMaintenanceScheduleResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function forVehicle(Request $request, Vehicle $vehicle)
    {
        return VehicleMaintenanceScheduleResource::collection(
            VehicleMaintenanceSchedule::query()
                ->with('booking')
                ->where('vehicle_id', $vehicle->id)
                ->orderBy('next_due_date')
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function store(CreateVehicleMaintenanceScheduleRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (!empty($data['booking_item_id']) && empty($data['booking_id'])) {
            abort(422, 'A booking is required when linking a trip.');
        }
        if (!empty($data['booking_qc_id']) && empty($data['booking_id'])) {
            abort(422, 'A booking is required when linking a QC follow-up.');
        }
        if (!empty($data['booking_item_id'])) {
            abort_unless(DB::table('booking_items')->where('id', $data['booking_item_id'])
                ->where('booking_id', $data['booking_id'])->exists(), 422, 'The selected trip does not belong to this booking.');
        }
        if (!empty($data['booking_qc_id'])) {
            abort_unless(DB::table('booking_qcs')->where('id', $data['booking_qc_id'])
                ->where('booking_id', $data['booking_id'])
                ->where('vehicle_id', $data['vehicle_id'])
                ->where('requires_maintenance', true)
                ->where(function ($query) use ($data): void {
                    if (empty($data['booking_item_id'])) {
                        $query->whereNull('booking_item_id');
                    } else {
                        $query->where('booking_item_id', $data['booking_item_id'])
                            ->orWhere(function ($legacy) use ($data): void {
                                $legacy->whereNull('booking_item_id')
                                    ->whereRaw('(select count(*) from booking_items where booking_id = ?) = 1', [$data['booking_id']]);
                            });
                    }
                })
                ->exists(), 422, 'The QC follow-up must match the selected booking, trip, and vehicle.');
        }
        $sch = VehicleMaintenanceSchedule::create($data+['created_user_id'=>$request->user()->id, 'status' => 'scheduled']);
        return response()->json(['status'=>'success','message'=>'Schedule created','data'=>['schedule'=>new VehicleMaintenanceScheduleResource($sch)]],201);
    }

    public function show(VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['schedule'=>new VehicleMaintenanceScheduleResource($vehicleMaintenanceSchedule->loadMissing('booking'))]]);
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
