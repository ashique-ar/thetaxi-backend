<?php
// app/Http/Controllers/Api/Vehicle/VehicleController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Corporate\Corporate;
use App\Models\Booking\Booking;
use App\Models\DriverAssignment;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\Vehicle;
use App\Http\Requests\Vehicle\Vehicle\CreateVehicleRequest;
use App\Http\Requests\Vehicle\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\Vehicle\VehicleResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $q = Vehicle::with(['owner.driver.user', 'grade', 'group.class', 'group.fuelType', 'group.transmission', 'group.category', 'group.make', 'group.model', 'group.grade', 'contractType', 'activeCommission']);
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
            'ownership_type',
            'usage_type',
            'payment_model',
            'assignment_policy',
            'default_driver_id',
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
        $vehicle->load(['owner.driver.user', 'grade', 'group.class', 'group.fuelType', 'group.transmission', 'group.category', 'group.make', 'group.model', 'group.grade', 'contractType', 'activeCommission']);

        return response()->json([
            'status' => 'success',
            'data' => new VehicleResource($vehicle)
        ]);
    }

    public function operationsDashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'ownership_type' => ['nullable', 'string'],
            'usage_type' => ['nullable', 'string'],
            'payment_model' => ['nullable', 'string'],
            'vehicle_group_id' => ['nullable', 'uuid'],
            'driver_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'uuid'],
            'hire_status' => ['nullable', 'string'],
        ]);

        $start = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->startOfDay()
            : now()->startOfMonth();
        $end = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->endOfDay()
            : now()->endOfMonth();

        $vehiclesQuery = Vehicle::query()
            ->with(['owner.driver.user', 'group.category', 'activeCommission', 'defaultDriver.user'])
            ->when($request->filled('ownership_type'), fn ($q) => $q->where('ownership_type', $request->ownership_type))
            ->when($request->filled('usage_type'), fn ($q) => $q->where('usage_type', $request->usage_type))
            ->when($request->filled('payment_model'), fn ($q) => $q->where('payment_model', $request->payment_model))
            ->when($request->filled('vehicle_group_id'), fn ($q) => $q->where('vehicle_group_id', $request->vehicle_group_id))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('owner_id', $request->owner_id))
            ->when($request->filled('driver_id'), fn ($q) => $q->where('default_driver_id', $request->driver_id));

        $vehicles = $vehiclesQuery->get();
        $vehicleIds = $vehicles->pluck('id');

        $activeVehicleIds = DriverAssignment::query()
            ->join('booking_items', 'driver_assignments.booking_item_id', '=', 'booking_items.id')
            ->whereIn('booking_items.vehicle_id', $vehicleIds)
            ->whereIn('trip_phase', ['active', 'confirmed', 'accepted', 'pickup_arrived', 'in_progress'])
            ->where('driver_assignments.status', '!=', 'cancelled')
            ->pluck('booking_items.vehicle_id')
            ->unique();

        $performance = DB::table('booking_items')
            ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->leftJoin('driver_assignments', 'booking_items.id', '=', 'driver_assignments.booking_item_id')
            ->whereIn('booking_items.vehicle_id', $vehicleIds)
            ->whereBetween('bookings.created_at', [$start, $end])
            ->when($request->filled('driver_id'), fn ($q) => $q->where('booking_items.driver_id', $request->driver_id))
            ->when($request->filled('hire_status'), fn ($q) => $q->where('bookings.status', $request->hire_status))
            ->groupBy('booking_items.vehicle_id')
            ->select('booking_items.vehicle_id')
            ->selectRaw('COUNT(DISTINCT bookings.id) as hire_count')
            ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, bookings.total_estimated, 0) ELSE 0 END) as revenue")
            ->selectRaw('SUM(COALESCE(driver_assignments.total_distance_km, 0)) as used_mileage')
            ->get()
            ->keyBy('vehicle_id');

        $vehicleRows = $vehicles->map(function (Vehicle $vehicle) use ($performance, $activeVehicleIds) {
            $row = $performance->get($vehicle->id);
            $hireCount = (int) ($row->hire_count ?? 0);
            $revenue = (float) ($row->revenue ?? 0);
            $usedMileage = (float) ($row->used_mileage ?? 0);
            $limit = $vehicle->monthly_mileage_limit !== null ? (float) $vehicle->monthly_mileage_limit : null;
            $commission = $vehicle->activeCommission;
            $commissionPayable = 0.0;

            if ($commission) {
                $commissionPayable = $commission->commission_type === 'fixed_amount'
                    ? (float) ($commission->amount ?? 0)
                    : round($revenue * ((float) ($commission->rate ?? 0) / 100), 2);
            }

            return [
                'id' => $vehicle->id,
                'title' => $vehicle->title,
                'license_plate' => $vehicle->license_plate ?? $vehicle->registration_no,
                'ownership_type' => $vehicle->ownership_type,
                'usage_type' => $vehicle->usage_type,
                'payment_model' => $vehicle->payment_model,
                'assignment_policy' => $vehicle->assignment_policy,
                'status' => $vehicle->status,
                'is_active' => (bool) $vehicle->is_active,
                'is_on_hire' => $activeVehicleIds->contains($vehicle->id),
                'default_driver' => $vehicle->defaultDriver?->user ? trim($vehicle->defaultDriver->user->first_name . ' ' . $vehicle->defaultDriver->user->last_name) : null,
                'owner' => $vehicle->owner?->user ? trim($vehicle->owner->user->first_name . ' ' . $vehicle->owner->user->last_name) : null,
                'hire_count' => $hireCount,
                'revenue' => $revenue,
                'commission_payable' => $commissionPayable,
                'monthly_payment_commitment' => $vehicle->monthly_payment_commitment !== null ? (float) $vehicle->monthly_payment_commitment : null,
                'monthly_mileage_limit' => $limit,
                'used_mileage' => $usedMileage,
                'remaining_mileage' => $limit !== null ? max($limit - $usedMileage, 0) : null,
                'excess_mileage' => $limit !== null ? max($usedMileage - $limit, 0) : null,
                'underutilized_mileage' => $limit !== null ? max($limit - $usedMileage, 0) : null,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
                'summary' => [
                    'total_vehicles' => $vehicleRows->count(),
                    'active_hires' => $activeVehicleIds->count(),
                    'active_vehicles' => $vehicleRows->where('is_active', true)->count(),
                    'inactive_vehicles' => $vehicleRows->where('is_active', false)->count(),
                    'maintenance_vehicles' => $vehicleRows->where('status', 'maintenance')->count(),
                    'assigned_drivers' => $vehicleRows->whereNotNull('default_driver')->count(),
                    'unassigned_vehicles' => $vehicleRows->whereNull('default_driver')->count(),
                    'available_for_any_driver' => $vehicleRows->where('assignment_policy', 'any_driver')->count(),
                    'rented_assets' => $vehicleRows->whereIn('ownership_type', ['rented_asset', 'leased_asset'])->count(),
                    'outside_taxi_operations' => $vehicleRows->where('ownership_type', 'outside_call_taxi')->count(),
                    'total_revenue' => round($vehicleRows->sum('revenue'), 2),
                    'total_commission_payable' => round($vehicleRows->sum('commission_payable'), 2),
                    'fixed_monthly_commitments' => round($vehicleRows->sum('monthly_payment_commitment'), 2),
                    'commission_based_vehicles' => $vehicleRows->whereIn('payment_model', ['commission', 'commission_plus_fixed'])->count(),
                    'fixed_monthly_vehicles' => $vehicleRows->whereIn('payment_model', ['fixed_monthly', 'commission_plus_fixed'])->count(),
                ],
                'by_ownership_type' => $vehicleRows->groupBy('ownership_type')->map->count()->all(),
                'by_usage_type' => $vehicleRows->groupBy('usage_type')->map->count()->all(),
                'by_payment_model' => $vehicleRows->groupBy('payment_model')->map->count()->all(),
                'highest_used_vehicles' => $vehicleRows->sortByDesc('hire_count')->take(10)->values(),
                'least_used_vehicles' => $vehicleRows->sortBy('hire_count')->take(10)->values(),
                'low_utilization_fixed_costs' => $vehicleRows
                    ->filter(fn ($row) => $row['monthly_payment_commitment'] && $row['monthly_mileage_limit'] && $row['used_mileage'] < ($row['monthly_mileage_limit'] * 0.5))
                    ->values(),
                'vehicles' => $vehicleRows,
            ],
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
        $recordOrderColumn = Schema::hasColumn('vehicle_maintenance_records', 'completion_date')
            ? 'completion_date'
            : 'performed_date';

        $records = VehicleMaintenanceRecord::where('vehicle_id', $vehicleId)
            ->with(['schedule'])
            ->orderBy($recordOrderColumn, 'desc')
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
        $scheduleDateColumn = Schema::hasColumn('vehicle_maintenance_schedules', 'scheduled_date')
            ? 'scheduled_date'
            : 'next_due_date';

        $schedulesQuery = VehicleMaintenanceSchedule::with(['vehicle'])
            ->where($scheduleDateColumn, '>=', now())
            ->orderBy($scheduleDateColumn);

        if (Schema::hasColumn('vehicle_maintenance_schedules', 'status')) {
            $schedulesQuery->where('status', 'scheduled');
        }

        $schedules = $schedulesQuery->paginate($request->per_page ?? 15);

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

        $recordData = [
            'vehicle_id' => $schedule->vehicle_id,
            'schedule_id' => $schedule->id,
            'performed_date' => $request->completion_date,
            'cost' => $request->cost,
            'status' => 'completed',
            'notes' => $request->notes,
        ];

        if (Schema::hasColumn('vehicle_maintenance_records', 'maintenance_type_id') && isset($schedule->maintenance_type_id)) {
            $recordData['maintenance_type_id'] = $schedule->maintenance_type_id;
        }

        // Create maintenance record
        $record = VehicleMaintenanceRecord::create($recordData);

        // Update schedule status
        if (Schema::hasColumn('vehicle_maintenance_schedules', 'status')) {
            $schedule->update(['status' => 'completed']);
        }

        return response()->json([
            'status' => 'success',
            'data' => $record->load(['vehicle', 'schedule'])
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
