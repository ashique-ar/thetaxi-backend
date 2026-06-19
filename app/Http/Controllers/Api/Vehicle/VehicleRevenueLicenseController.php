<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\VehicleRevenueLicense\CreateVehicleRevenueLicenseRequest;
use App\Http\Requests\Vehicle\VehicleRevenueLicense\UpdateVehicleRevenueLicenseRequest;
use App\Http\Resources\Vehicle\VehicleRevenueLicenseResource;
use App\Models\Vehicle\VehicleRevenueLicense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class VehicleRevenueLicenseController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicles.view')->only(['index', 'show']);
        $this->middleware('permission:vehicles.create|vehicles.edit')->only(['store', 'renew']);
        $this->middleware('permission:vehicles.edit')->only(['update']);
        $this->middleware('permission:vehicles.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $query = VehicleRevenueLicense::query()
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('expiring_soon'), fn ($q) => $q->whereDate('expiry_date', '<=', now()->addDays(30)));

        return VehicleRevenueLicenseResource::collection($query->latest('expiry_date')->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleRevenueLicenseRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['renewal_reminder_date'] = $this->managedReminderDate($payload['expiry_date'] ?? null);
        $payload['renewal_date'] = null;

        $license = VehicleRevenueLicense::create($payload + ['created_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Revenue license created',
            'data' => ['revenue_license' => new VehicleRevenueLicenseResource($license)],
        ], 201);
    }

    public function show(VehicleRevenueLicense $vehicleRevenueLicense): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['revenue_license' => new VehicleRevenueLicenseResource($vehicleRevenueLicense)],
        ]);
    }

    public function update(UpdateVehicleRevenueLicenseRequest $request, VehicleRevenueLicense $vehicleRevenueLicense): JsonResponse
    {
        $payload = $request->validated();
        if (array_key_exists('expiry_date', $payload)) {
            $payload['renewal_reminder_date'] = $this->managedReminderDate($payload['expiry_date']);
        }

        $vehicleRevenueLicense->update($payload + ['updated_user_id' => $request->user()->id]);

        return response()->json([
            'status' => 'success',
            'message' => 'Revenue license updated',
            'data' => ['revenue_license' => new VehicleRevenueLicenseResource($vehicleRevenueLicense)],
        ]);
    }

    public function renew(CreateVehicleRevenueLicenseRequest $request, VehicleRevenueLicense $vehicleRevenueLicense): JsonResponse
    {
        $renewed = DB::transaction(function () use ($request, $vehicleRevenueLicense) {
            $vehicleRevenueLicense->update([
                'status' => 'renewed',
                'updated_user_id' => $request->user()->id,
            ]);

            $payload = $request->validated();
            $payload['renewal_reminder_date'] = $this->managedReminderDate($payload['expiry_date'] ?? null);
            $payload['renewal_date'] = now()->toDateString();

            return VehicleRevenueLicense::create($payload + [
                'vehicle_id' => $vehicleRevenueLicense->vehicle_id,
                'renewed_from_id' => $vehicleRevenueLicense->id,
                'status' => 'active',
                'created_user_id' => $request->user()->id,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Revenue license renewed',
            'data' => ['revenue_license' => new VehicleRevenueLicenseResource($renewed)],
        ], 201);
    }

    public function destroy(VehicleRevenueLicense $vehicleRevenueLicense): JsonResponse
    {
        $vehicleRevenueLicense->delete();

        return response()->json(['status' => 'success', 'message' => 'Revenue license deleted']);
    }

    private function managedReminderDate(mixed $expiryDate): ?string
    {
        if (!$expiryDate) {
            return null;
        }

        return Carbon::parse($expiryDate)->subMonthNoOverflow()->toDateString();
    }
}
