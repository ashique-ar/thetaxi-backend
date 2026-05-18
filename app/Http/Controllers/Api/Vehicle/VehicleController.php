<?php
// app/Http/Controllers/Api/Vehicle/VehicleController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Corporate\Corporate;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\Vehicle;
use App\Http\Requests\Vehicle\Vehicle\CreateVehicleRequest;
use App\Http\Requests\Vehicle\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\Vehicle\VehicleResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VehicleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicles.view')->only(['index', 'show']);
        $this->middleware('permission:vehicles.create')->only(['store']);
        $this->middleware('permission:vehicles.edit')->only(['update']);
        $this->middleware('permission:vehicles.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = Vehicle::with(['owner', 'grade', 'group.class', 'group.fuelType', 'group.transmission', 'group.category', 'group.make', 'group.model', 'group.grade', 'contractType']);
        if ($request->filled('search')) {
            $q->where(function ($query) use ($request) {
                $query->whereLikeInsensitive('title', $request->search)
                    ->orWhereLikeInsensitive('license_plate', $request->search)
                    ->orWhereLikeInsensitive('registration_no', $request->search);
            });
        }

        $filters = [
            'class_id',
            'fuel_type_id',
            'transmission_id',
            'category_id',
            'model_id',
            'make_id',
            'owner_id',
            'grade_id',
            'group_id',
            'is_active',
        ];

        foreach ($filters as $field) {
            if ($request->filled($field)) {
                // cast booleans if needed
                $value = in_array($field, ['is_active'])
                    ? filter_var($request->get($field), FILTER_VALIDATE_BOOL)
                    : $request->get($field);

                if (in_array($field, ['class_id', 'make_id', 'fuel_type_id', 'transmission_id', 'category_id', 'model_id', 'grade_id'])) {
                    $q->whereHas('group', function ($query) use ($field, $value) {
                        $query->where($field, $value);
                    });
                } else {
                    $q->where($field, $value);
                }
            }
        }




        if ($request->filled('sort') && $request->filled('direction')) {
            $direction = strtolower($request->direction) === 'desc' ? 'desc' : 'asc';
            $q->orderBy($request->sort, $direction);
        }


        return VehicleResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateVehicleRequest $request): JsonResponse
    {
        $data = $this->normalizeVehicleIdentifierPayload($request->validated());
        $data['created_user_id'] = $request->user()->id;
        $vehicle = Vehicle::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle created',
            'data' => ['vehicle' => new VehicleResource($vehicle)]
        ], 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $vehicle->load(['owner', 'grade', 'group.class', 'group.fuelType', 'group.transmission', 'group.category', 'group.make', 'group.model', 'group.grade', 'contractType']);

        return response()->json([
            'status' => 'success',
            'data' => new VehicleResource($vehicle)
        ]);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $data = $this->normalizeVehicleIdentifierPayload($request->validated());
        $data['updated_user_id'] = $request->user()->id;
        $vehicle->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle updated',
            'data' => ['vehicle' => new VehicleResource($vehicle)]
        ]);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $vehicle->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle deleted',
        ]);
    }

    private function normalizeVehicleIdentifierPayload(array $data): array
    {
        $identifier = $data['license_plate'] ?? $data['registration_no'] ?? null;

        if ($identifier !== null) {
            $data['license_plate'] = $identifier;
            $data['registration_no'] = $identifier;
        }

        return $data;
    }

    /**
     * Get available vehicles
     */
    public function getAvailableVehicles(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'vehicle_type' => 'nullable|string',
            'location' => 'nullable|string'
        ]);

        $query = Vehicle::where('is_active', true)
            ->where('is_active', true)
            ->whereDoesntHave('group.bookings', function ($q) use ($request) {
                $q->where('status', '!=', 'cancelled')
                    ->where(function ($subQ) use ($request) {
                        $subQ->whereBetween('start_date', [$request->start_date, $request->end_date])
                            ->orWhereBetween('end_date', [$request->start_date, $request->end_date]);
                    });
            });

        if ($request->vehicle_type) {
            $query->where('type', $request->vehicle_type);
        }

        if ($request->location) {
            $query->where('current_location', 'like', '%' . $request->location . '%');
        }

        $vehicles = $query->with(['group.category', 'group.make', 'group.model'])->get();

        return response()->json([
            'status' => 'success',
            'data' => $vehicles
        ]);
    }

    /**
     * Check vehicle availability
     */
    public function checkAvailability(Request $request, $vehicleId)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date'
        ]);

        $vehicle = Vehicle::findOrFail($vehicleId);

        $conflicts = Booking::where('vehicle_id', $vehicleId)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($request) {
                $q->whereBetween('pickup_datetime', [$request->start_date, $request->end_date])
                    ->orWhereBetween('dropoff_datetime', [$request->start_date, $request->end_date]);
            })
            ->with(['customer'])
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'available' => $conflicts->isEmpty(),
                'conflicts' => $conflicts
            ]
        ]);
    }

    /**
     * Get maintenance history
     */
    public function getMaintenanceHistory(Request $request, $vehicleId)
    {
        $records = VehicleMaintenanceRecord::where('vehicle_id', $vehicleId)
            ->with(['maintenanceType'])
            ->orderBy('completion_date', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'status' => 'success',
            'data' => $records
        ]);
    }

    /**
     * Get upcoming maintenance
     */
    public function getUpcomingMaintenance(Request $request)
    {
        $schedules = VehicleMaintenanceSchedule::with(['vehicle', 'maintenanceType'])
            ->where('scheduled_date', '>=', now())
            ->where('status', 'scheduled')
            ->orderBy('scheduled_date')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'status' => 'success',
            'data' => $schedules
        ]);
    }

    /**
     * Complete maintenance schedule
     */
    public function completeMaintenanceSchedule(Request $request, $scheduleId)
    {
        $request->validate([
            'completion_date' => 'required|date',
            'cost' => 'required|numeric',
            'notes' => 'nullable|string'
        ]);

        $schedule = VehicleMaintenanceSchedule::findOrFail($scheduleId);

        // Create maintenance record
        $record = VehicleMaintenanceRecord::create([
            'vehicle_id' => $schedule->vehicle_id,
            'maintenance_type_id' => $schedule->maintenance_type_id,
            'completion_date' => $request->completion_date,
            'cost' => $request->cost,
            'notes' => $request->notes,
            'scheduled_maintenance_id' => $schedule->id
        ]);

        // Update schedule status
        $schedule->update(['status' => 'completed']);

        return response()->json([
            'status' => 'success',
            'data' => $record->load(['vehicle', 'maintenanceType'])
        ]);
    }

    /**
     * Block vehicle
     */
    public function blockVehicle(Request $request, $vehicleId)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'reason' => 'required|string',
            'notes' => 'nullable|string'
        ]);

        $vehicle = Vehicle::findOrFail($vehicleId);

        // Create blocking record
        $block = VehicleBlock::create([
            'vehicle_id' => $vehicleId,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'reason' => $request->reason,
            'notes' => $request->notes,
            'created_by' => auth()->id()
        ]);

        // Update vehicle status
        $vehicle->update(['status' => 'blocked']);

        return response()->json([
            'status' => 'success',
            'data' => $block
        ]);
    }

    /**
     * Update vehicle availability
     */
    public function updateAvailability(Request $request, $vehicleId)
    {
        $request->validate([
            'is_available' => 'required|boolean',
            'reason' => 'nullable|string',
            'expected_available_date' => 'nullable|date'
        ]);

        $vehicle = Vehicle::findOrFail($vehicleId);

        $status = $request->is_available ? 'available' : 'unavailable';

        $vehicle->update([
            'status' => $status,
            'availability_reason' => $request->reason,
            'expected_available_date' => $request->expected_available_date
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $vehicle
        ]);
    }

    /**
     * Get service types
     */
    public function getServiceTypes(Request $request)
    {
        $context = (string) $request->input('context', 'portal');
        $ownerType = (string) $request->input('owner_type', ($context === 'corporate' ? 'corporate' : ''));
        $ownerId = (string) $request->input('owner_id', '');

        if ($context === 'corporate' && $ownerType === 'corporate' && $ownerId !== '') {
            $serviceTypes = Corporate::findOrFail($ownerId)
                ->serviceTypes()
                ->where('service_types.context', 'corporate')
                ->where('service_types.owner_type', '')
                ->where('service_types.owner_id', '')
                ->where('service_types.is_active', true)
                ->get();
        } else {
            $serviceTypes = ServiceType::forContext($context, '', '')
                ->where('is_active', true)
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => $serviceTypes
        ]);
    }

    /**
     * Get insurance types
     */
    public function getInsuranceTypes()
    {
        $insuranceTypes = VehicleInsuranceType::where('is_active', true)->get();

        return response()->json([
            'status' => 'success',
            'data' => $insuranceTypes
        ]);
    }
}
