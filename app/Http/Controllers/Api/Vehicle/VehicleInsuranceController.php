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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class VehicleInsuranceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicles.view|vehicle-insurances.view')->only(['index','show']);
        $this->middleware('permission:vehicles.create|vehicles.edit|vehicle-insurances.create')->only(['store', 'renew']);
        $this->middleware('permission:vehicles.edit|vehicle-insurances.edit')->only(['update']);
        $this->middleware('permission:vehicles.delete|vehicle-insurances.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = VehicleInsurance::with(['vehicle', 'provider'])
            ->when($request->filled('vehicle_id'), fn ($query) => $query->where('vehicle_id', $request->vehicle_id))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->boolean('expiring_soon'), fn ($query) => $query->whereDate('end_date', '<=', now()->addDays(30)));

        return VehicleInsuranceResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleInsuranceRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['renewal_reminder_date'] = $this->managedReminderDate($payload['end_date'] ?? null);
        $payload['renewal_date'] = null;

        $ins = VehicleInsurance::create($payload + ['created_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Insurance created','data'=>['insurance'=>new VehicleInsuranceResource($ins)]],201);
    }

    public function show(VehicleInsurance $vehicleInsurance): JsonResponse
    {
        return response()->json(['status'=>'success','data'=>['insurance'=>new VehicleInsuranceResource($vehicleInsurance)]]);
    }

    public function update(UpdateVehicleInsuranceRequest $request, VehicleInsurance $vehicleInsurance): JsonResponse
    {
        $payload = $request->validated();
        if (array_key_exists('end_date', $payload)) {
            $payload['renewal_reminder_date'] = $this->managedReminderDate($payload['end_date']);
        }

        $vehicleInsurance->update($payload + ['updated_user_id'=>$request->user()->id]);
        return response()->json(['status'=>'success','message'=>'Insurance updated','data'=>['insurance'=>new VehicleInsuranceResource($vehicleInsurance)]]);
    }

    public function renew(CreateVehicleInsuranceRequest $request, VehicleInsurance $vehicleInsurance): JsonResponse
    {
        $renewed = DB::transaction(function () use ($request, $vehicleInsurance) {
            $vehicleInsurance->update([
                'status' => 'renewed',
                'updated_user_id' => $request->user()->id,
            ]);

            $payload = $request->validated();
            $payload['renewal_reminder_date'] = $this->managedReminderDate($payload['end_date'] ?? null);
            $payload['renewal_date'] = now()->toDateString();

            return VehicleInsurance::create($payload + [
                'vehicle_id' => $vehicleInsurance->vehicle_id,
                'renewed_from_id' => $vehicleInsurance->id,
                'status' => 'active',
                'created_user_id' => $request->user()->id,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Insurance renewed',
            'data' => ['insurance' => new VehicleInsuranceResource($renewed)],
        ], 201);
    }

    public function destroy(VehicleInsurance $vehicleInsurance): JsonResponse
    {
        $vehicleInsurance->delete();
        return response()->json(['status'=>'success','message'=>'Insurance deleted']);
    }

    private function managedReminderDate(mixed $expiryDate): ?string
    {
        if (!$expiryDate) {
            return null;
        }

        return Carbon::parse($expiryDate)->subMonthNoOverflow()->toDateString();
    }
}
