<?php
// app/Http/Controllers/Api/Vehicle/VehicleController.php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Corporate\Corporate;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\DriverAssignment;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleLease;
use App\Models\Vehicle\VehicleMaintenanceRecord;
use App\Models\Vehicle\VehicleMaintenanceSchedule;
use App\Models\Vehicle\VehicleOwnershipHistory;
use App\Http\Requests\Vehicle\Vehicle\CreateVehicleRequest;
use App\Http\Requests\Vehicle\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\Vehicle\VehicleResource;
use App\Rules\UniqueVehiclePlate;
use App\Services\VehicleLeaseAccountingService;
use App\Services\VehicleLeaseService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $q = Vehicle::with(['owner.driver.user', 'owner.paymentMethods', 'ownerPaymentMethod', 'grade', 'group.class', 'group.fuelType', 'group.transmission', 'group.category', 'group.make', 'group.model', 'group.grade', 'contractType', 'activeCommission', 'insurances.provider', 'insurances.insuranceType', 'revenueLicenses', 'activeInsurance.provider', 'activeInsurance.insuranceType', 'activeRevenueLicense']);
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
            'vehicle_group_id',
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

        // Backward compatibility for older portal clients; the database column is vehicle_group_id.
        if ($request->filled('group_id') && ! $request->filled('vehicle_group_id')) {
            $q->where('vehicle_group_id', $request->group_id);
        }




        if ($request->filled('sort') && $request->filled('direction')) {
            $direction = strtolower($request->direction) === 'desc' ? 'desc' : 'asc';
            $q->orderBy($request->sort, $direction);
        }


        return VehicleResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateVehicleRequest $request, VehicleLeaseService $leaseService): JsonResponse
    {
        $data = $request->validated();
        $currentLeaseData = $this->pullCurrentLeaseData($data);
        $data = $this->normalizeVehicleIdentifierPayload($data);
        $data['current_mileage'] = $data['initial_mileage'] ?? null;
        $data['created_user_id'] = $request->user()->id;
        $vehicle = DB::transaction(function () use ($data, $currentLeaseData, $request, $leaseService) {
            $vehicle = Vehicle::create($data);
            VehicleOwnershipHistory::create([
                'vehicle_id' => $vehicle->id,
                'from_owner_id' => null,
                'to_owner_id' => $vehicle->owner_id,
                'from_ownership_type' => 'unregistered',
                'to_ownership_type' => $vehicle->ownership_type ?: 'company_owned',
                'transfer_type' => 'initial_registration',
                'effective_at' => now(),
                'reference' => 'INITIAL-' . $vehicle->id,
                'notes' => 'Initial legal ownership recorded with the vehicle master.',
                'performed_by' => $request->user()->id,
            ]);
            $this->syncCurrentLease($vehicle, $currentLeaseData, $request, $leaseService);

            return $vehicle->fresh();
        });
        $vehicle->load('currentLease.financeProvider');

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle created',
            'data' => ['vehicle' => new VehicleResource($vehicle)]
        ], 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $vehicle->load([
            'owner.driver.user',
            'owner.paymentMethods',
            'ownerPaymentMethod',
            'grade',
            'group.class',
            'group.fuelType',
            'group.transmission',
            'group.category',
            'group.make',
            'group.model',
            'group.grade',
            'contractType',
            'activeCommission',
            'insurances.provider',
            'insurances.insuranceType',
            'revenueLicenses',
            'activeInsurance.provider',
            'activeInsurance.insuranceType',
            'activeRevenueLicense',
            'currentLease.financeProvider',
            'ownershipHistory.fromOwner.user',
            'ownershipHistory.toOwner.user',
            'ownershipHistory.document',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => new VehicleResource($vehicle)
        ]);
    }

    public function checkPlateAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plate' => ['required', 'string', 'max:255'],
            'exclude_vehicle_id' => ['nullable', 'uuid', 'exists:vehicles,id'],
        ]);

        $validator = validator(
            ['license_plate' => $validated['plate']],
            ['license_plate' => [new UniqueVehiclePlate($validated['exclude_vehicle_id'] ?? null)]]
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'available' => !$validator->fails(),
                'normalized_plate' => UniqueVehiclePlate::normalize($validated['plate']),
            ],
        ]);
    }

    public function operationsDashboard(
        Request $request,
        VehicleLeaseAccountingService $leaseAccountingService
    ): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'ownership_type' => ['nullable', 'string'],
            'usage_type' => ['nullable', 'string'],
            'payment_model' => ['nullable', 'string'],
            'vehicle_group_id' => ['nullable', 'uuid'],
            'driver_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'uuid'],
            'hire_status' => ['nullable', 'string'],
        ]);

        $providedStart = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : null;
        $providedEnd = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])
            : null;
        $start = ($providedStart ?? $providedEnd?->copy()->startOfMonth() ?? now()->startOfMonth())->startOfDay();
        $end = ($providedEnd ?? $providedStart?->copy()->endOfMonth() ?? now()->endOfMonth())->endOfDay();
        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'end_date' => ['The end date must be on or after the start date.'],
            ]);
        }

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
        $canViewLeaseAccounting = $request->user()?->can('vehicle-leases.view') === true;
        $leaseAccounting = $canViewLeaseAccounting
            ? $leaseAccountingService->portfolioSummary($start, $end, $vehicleIds)
            : ['by_currency' => [], 'per_vehicle' => []];
        $leaseAccountingByVehicle = $leaseAccounting['per_vehicle'];

        $activeVehicleIds = DriverAssignment::query()
            ->join('booking_items', 'driver_assignments.booking_item_id', '=', 'booking_items.id')
            ->whereIn('booking_items.vehicle_id', $vehicleIds)
            ->whereIn('trip_phase', ['active', 'confirmed', 'accepted', 'pickup_arrived', 'in_progress'])
            ->where('driver_assignments.status', '!=', 'cancelled')
            ->pluck('booking_items.vehicle_id')
            ->unique();

        $activeHireMovements = DB::table('driver_assignments')
            ->join('booking_items', 'driver_assignments.booking_item_id', '=', 'booking_items.id')
            ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->whereIn('booking_items.vehicle_id', $vehicleIds)
            ->whereIn('driver_assignments.trip_phase', ['active', 'confirmed', 'accepted', 'pickup_arrived', 'in_progress'])
            ->where('driver_assignments.status', '!=', 'cancelled')
            ->orderByDesc('driver_assignments.assigned_from')
            ->select([
                'booking_items.vehicle_id',
                'booking_items.booking_id',
                'driver_assignments.driver_id',
                'driver_assignments.trip_phase',
                'driver_assignments.assigned_from',
                'driver_assignments.trip_started_at',
                'driver_assignments.pickup_arrived_at',
                'driver_assignments.total_distance_km',
                'bookings.booking_number',
                'bookings.status as booking_status',
            ])
            ->get()
            ->unique('vehicle_id')
            ->keyBy('vehicle_id');

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

        $vehicleRows = $vehicles->map(function (Vehicle $vehicle) use (
            $performance,
            $activeVehicleIds,
            $activeHireMovements,
            $leaseAccountingByVehicle,
            $canViewLeaseAccounting
        ) {
            $row = $performance->get($vehicle->id);
            $hireCount = (int) ($row->hire_count ?? 0);
            $revenue = (float) ($row->revenue ?? 0);
            $usedMileage = (float) ($row->used_mileage ?? 0);
            $limit = $vehicle->monthly_mileage_limit !== null ? (float) $vehicle->monthly_mileage_limit : null;
            $usesOwnerFixedPayment = in_array(
                $vehicle->payment_model,
                ['fixed_monthly', 'commission_plus_fixed'],
                true
            );
            $ownerMonthlyCommitment = $usesOwnerFixedPayment
                ? round((float) ($vehicle->monthly_payment_commitment ?? 0), 2)
                : 0;
            $leaseMetrics = $leaseAccountingByVehicle[$vehicle->id] ?? [];
            $leaseMonthlyCommitment = round((float) ($leaseMetrics['monthly_run_rate'] ?? 0), 2);
            $leaseCurrency = $leaseMetrics['currency'] ?? null;
            $hasMixedCommitmentCurrency = $leaseCurrency !== null && $leaseCurrency !== 'LKR';
            $totalMonthlyCommitment = $hasMixedCommitmentCurrency
                ? $ownerMonthlyCommitment
                : round($ownerMonthlyCommitment + $leaseMonthlyCommitment, 2);
            $commission = $vehicle->activeCommission;
            $commissionPayable = 0.0;
            $movement = $activeHireMovements->get($vehicle->id);

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
                'movement_status' => $movement?->trip_phase ?? 'not_on_hire',
                'movement_status_label' => $movement?->trip_phase ? str_replace('_', ' ', $movement->trip_phase) : 'Not on hire',
                'movement_booking_id' => $movement?->booking_id,
                'movement_booking_number' => $movement?->booking_number,
                'movement_driver_id' => $movement?->driver_id,
                'movement_started_at' => $movement?->trip_started_at ?? $movement?->assigned_from,
                'movement_pickup_arrived_at' => $movement?->pickup_arrived_at,
                'movement_distance_km' => $movement?->total_distance_km !== null ? (float) $movement->total_distance_km : null,
                'default_driver' => $vehicle->defaultDriver?->user ? trim($vehicle->defaultDriver->user->first_name . ' ' . $vehicle->defaultDriver->user->last_name) : null,
                'owner' => $vehicle->owner?->user ? trim($vehicle->owner->user->first_name . ' ' . $vehicle->owner->user->last_name) : null,
                'hire_count' => $hireCount,
                'revenue' => $revenue,
                'commission_payable' => $commissionPayable,
                'monthly_payment_commitment' => $usesOwnerFixedPayment
                    && $vehicle->monthly_payment_commitment !== null
                    ? $ownerMonthlyCommitment
                    : null,
                'owner_monthly_commitment' => $ownerMonthlyCommitment,
                'total_monthly_commitment' => $totalMonthlyCommitment,
                ...($canViewLeaseAccounting ? [
                    'lease_monthly_commitment' => $leaseMonthlyCommitment,
                    'lease_currency' => $leaseCurrency,
                    'has_mixed_commitment_currency' => $hasMixedCommitmentCurrency,
                    'lease_outstanding' => round((float) ($leaseMetrics['outstanding'] ?? 0), 2),
                    'lease_overdue' => round((float) ($leaseMetrics['overdue'] ?? 0), 2),
                ] : []),
                'monthly_mileage_limit' => $limit,
                'used_mileage' => $usedMileage,
                'remaining_mileage' => $limit !== null ? max($limit - $usedMileage, 0) : null,
                'excess_mileage' => $limit !== null ? max($usedMileage - $limit, 0) : null,
                'underutilized_mileage' => $limit !== null ? max($limit - $usedMileage, 0) : null,
            ];
        })->values();

        $ownerMonthlyCommitments = round($vehicleRows->sum('owner_monthly_commitment'), 2);
        $lkrLeaseMonthlyCommitments = round(
            (float) ($leaseAccounting['by_currency']['LKR']['monthly_run_rate'] ?? 0),
            2
        );
        $totalMonthlyCommitments = round(
            $ownerMonthlyCommitments + $lkrLeaseMonthlyCommitments,
            2
        );

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
                    'owner_monthly_commitments' => $ownerMonthlyCommitments,
                    'fixed_monthly_commitments' => $totalMonthlyCommitments,
                    'total_monthly_commitments' => $totalMonthlyCommitments,
                    'commitment_currency' => 'LKR',
                    ...($canViewLeaseAccounting ? [
                        'lease_accounting_by_currency' => $leaseAccounting['by_currency'],
                        'has_mixed_commitment_currencies' => collect($leaseAccounting['by_currency'])
                            ->except('LKR')
                            ->contains(fn ($totals) => (float) ($totals['monthly_run_rate'] ?? 0) > 0),
                    ] : []),
                    'commission_based_vehicles' => $vehicleRows->whereIn('payment_model', ['commission', 'commission_plus_fixed'])->count(),
                    'fixed_monthly_vehicles' => $vehicleRows->whereIn('payment_model', ['fixed_monthly', 'commission_plus_fixed'])->count(),
                ],
                'by_ownership_type' => $vehicleRows->groupBy('ownership_type')->map->count()->all(),
                'by_usage_type' => $vehicleRows->groupBy('usage_type')->map->count()->all(),
                'by_payment_model' => $vehicleRows->groupBy('payment_model')->map->count()->all(),
                'highest_used_vehicles' => $vehicleRows->sortByDesc('hire_count')->take(10)->values(),
                'least_used_vehicles' => $vehicleRows->sortBy('hire_count')->take(10)->values(),
                'low_utilization_fixed_costs' => $vehicleRows
                    ->filter(fn ($row) => ($row['owner_monthly_commitment'] || ($row['lease_monthly_commitment'] ?? 0))
                        && $row['monthly_mileage_limit']
                        && $row['used_mileage'] < ($row['monthly_mileage_limit'] * 0.5))
                    ->values(),
                'vehicles' => $vehicleRows,
            ],
        ]);
    }

    public function availabilityAnalytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'granularity' => ['nullable', 'in:day,month,year'],
            'search' => ['nullable', 'string'],
            'ownership_type' => ['nullable', 'string'],
            'usage_type' => ['nullable', 'string'],
            'payment_model' => ['nullable', 'string'],
            'vehicle_group_id' => ['nullable', 'uuid'],
            'driver_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'uuid'],
            'hire_status' => ['nullable', 'string'],
        ]);

        $granularity = $validated['granularity'] ?? 'day';
        $start = isset($validated['start_date'])
            ? Carbon::parse($validated['start_date'])->startOfDay()
            : now()->startOfMonth();
        $end = isset($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->endOfDay()
            : now()->endOfMonth();

        $vehicles = Vehicle::query()
            ->with(['owner.driver.user', 'group.category', 'defaultDriver.user'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($query) use ($request) {
                    $query->whereLikeInsensitive('title', $request->search)
                        ->orWhereLikeInsensitive('license_plate', $request->search)
                        ->orWhereLikeInsensitive('registration_no', $request->search);
                });
            })
            ->when($request->filled('ownership_type'), fn ($q) => $q->where('ownership_type', $request->ownership_type))
            ->when($request->filled('usage_type'), fn ($q) => $q->where('usage_type', $request->usage_type))
            ->when($request->filled('payment_model'), fn ($q) => $q->where('payment_model', $request->payment_model))
            ->when($request->filled('vehicle_group_id'), fn ($q) => $q->where('vehicle_group_id', $request->vehicle_group_id))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('owner_id', $request->owner_id))
            ->when($request->filled('driver_id'), fn ($q) => $q->where('default_driver_id', $request->driver_id))
            ->orderBy('title')
            ->get();

        $vehicleIds = $vehicles->pluck('id')->values();
        $periods = $this->buildAvailabilityPeriods($start, $end, $granularity);

        $bookingItems = BookingItem::query()
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->whereIn('booking_items.vehicle_id', $vehicleIds)
            ->whereNotIn('bookings.status', ['cancelled', 'rejected'])
            ->when($request->filled('hire_status'), fn ($q) => $q->where('bookings.status', $request->hire_status))
            ->where(function ($q) use ($start, $end) {
                $q->whereDate('booking_items.from_date', '<=', $end->toDateString())
                    ->whereDate(DB::raw('COALESCE(booking_items.to_date, booking_items.from_date)'), '>=', $start->toDateString());
            })
            ->select([
                'booking_items.id',
                'booking_items.vehicle_id',
                'booking_items.from_date',
                'booking_items.to_date',
                'bookings.id as booking_id',
                'bookings.booking_number',
                'bookings.status as booking_status',
            ])
            ->get()
            ->groupBy('vehicle_id');

        $maintenanceByVehicle = $this->maintenanceDatesByVehicle($vehicleIds, $start, $end);

        $rows = $vehicles->map(function (Vehicle $vehicle) use ($periods, $bookingItems, $maintenanceByVehicle) {
            $vehicleBookings = $bookingItems->get($vehicle->id, collect());
            $maintenanceDates = $maintenanceByVehicle->get($vehicle->id, collect());
            $availability = collect($periods)->map(function ($period) use ($vehicle, $vehicleBookings, $maintenanceDates) {
                $periodStart = Carbon::parse($period['start_date'])->startOfDay();
                $periodEnd = Carbon::parse($period['end_date'])->endOfDay();

                $matchedBookings = $vehicleBookings->filter(function ($booking) use ($periodStart, $periodEnd) {
                    $bookingStart = Carbon::parse($booking->from_date)->startOfDay();
                    $bookingEnd = $booking->to_date ? Carbon::parse($booking->to_date)->endOfDay() : $bookingStart->copy()->endOfDay();

                    return $bookingStart->lte($periodEnd) && $bookingEnd->gte($periodStart);
                });

                $hasMaintenance = $maintenanceDates->contains(function ($date) use ($periodStart, $periodEnd) {
                    $maintenanceDate = Carbon::parse($date)->startOfDay();

                    return $maintenanceDate->betweenIncluded($periodStart, $periodEnd);
                });

                $status = 'available';
                if (!$vehicle->is_active || in_array($vehicle->status, ['inactive', 'blocked', 'unavailable'], true)) {
                    $status = 'unavailable';
                } elseif ($hasMaintenance || $vehicle->status === 'maintenance') {
                    $status = 'maintenance';
                } elseif ($matchedBookings->isNotEmpty()) {
                    $status = 'booked';
                }

                return [
                    'period_key' => $period['key'],
                    'period_start' => $period['start_date'],
                    'period_end' => $period['end_date'],
                    'label' => $period['label'],
                    'status' => $status,
                    'available' => $status === 'available',
                    'booking_count' => $matchedBookings->count(),
                    'booking_numbers' => $matchedBookings->pluck('booking_number')->filter()->unique()->values(),
                ];
            });

            return [
                'id' => $vehicle->id,
                'title' => $vehicle->title,
                'license_plate' => $vehicle->license_plate ?? $vehicle->registration_no,
                'ownership_type' => $vehicle->ownership_type,
                'usage_type' => $vehicle->usage_type,
                'payment_model' => $vehicle->payment_model,
                'status' => $vehicle->status,
                'is_active' => (bool) $vehicle->is_active,
                'default_driver' => $vehicle->defaultDriver?->user ? trim($vehicle->defaultDriver->user->first_name . ' ' . $vehicle->defaultDriver->user->last_name) : null,
                'owner' => $vehicle->owner?->user ? trim($vehicle->owner->user->first_name . ' ' . $vehicle->owner->user->last_name) : null,
                'available_periods' => $availability->where('status', 'available')->count(),
                'booked_periods' => $availability->where('status', 'booked')->count(),
                'maintenance_periods' => $availability->where('status', 'maintenance')->count(),
                'unavailable_periods' => $availability->where('status', 'unavailable')->count(),
                'availability' => $availability->values(),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => [
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'granularity' => $granularity,
                ],
                'periods' => $periods,
                'summary' => [
                    'total_vehicles' => $rows->count(),
                    'fully_available' => $rows->filter(fn ($row) => $row['booked_periods'] === 0 && $row['maintenance_periods'] === 0 && $row['unavailable_periods'] === 0)->count(),
                    'has_bookings' => $rows->where('booked_periods', '>', 0)->count(),
                    'has_maintenance' => $rows->where('maintenance_periods', '>', 0)->count(),
                    'unavailable' => $rows->where('unavailable_periods', '>', 0)->count(),
                ],
                'vehicles' => $rows,
            ],
        ]);
    }

    public function update(
        UpdateVehicleRequest $request,
        Vehicle $vehicle,
        VehicleLeaseService $leaseService
    ): JsonResponse
    {
        $data = $request->validated();
        $currentLeaseData = $this->pullCurrentLeaseData($data);
        $data = $this->normalizeVehicleIdentifierPayload($data);
        $data['updated_user_id'] = $request->user()->id;
        $nextOwnerId = array_key_exists('owner_id', $data) ? $data['owner_id'] : $vehicle->owner_id;
        $nextOwnershipType = $data['ownership_type'] ?? $vehicle->ownership_type;
        $ownershipChanged = $nextOwnerId !== $vehicle->owner_id
            || $nextOwnershipType !== $vehicle->ownership_type;

        if ($ownershipChanged && $vehicle->leases()
            ->whereIn('status', ['active', 'expired', 'closure_pending'])
            ->exists()) {
            throw ValidationException::withMessages([
                'owner_id' => ['Ownership cannot be edited while a finance or lease contract is open. Complete the verified contract closure/transfer workflow first.'],
            ]);
        }
        if ($ownershipChanged
            && $vehicle->leases()->where('status', 'draft')->exists()
            && ! $request->user()->can('vehicle-leases.edit')) {
            abort(403, 'Editing vehicle ownership also changes the current lease draft and requires vehicle lease edit permission.');
        }

        DB::transaction(function () use (
            $vehicle,
            $data,
            $ownershipChanged,
            $nextOwnerId,
            $nextOwnershipType,
            $request,
            $currentLeaseData,
            $leaseService
        ) {
            $fromOwnerId = $vehicle->owner_id;
            $fromOwnershipType = $vehicle->ownership_type ?: 'company_owned';
            $vehicle->update($data);

            if ($ownershipChanged) {
                VehicleOwnershipHistory::create([
                    'vehicle_id' => $vehicle->id,
                    'from_owner_id' => $fromOwnerId,
                    'to_owner_id' => $nextOwnerId,
                    'from_ownership_type' => $fromOwnershipType,
                    'to_ownership_type' => $nextOwnershipType ?: 'company_owned',
                    'transfer_type' => 'vehicle_master_update',
                    'effective_at' => now(),
                    'reference' => 'OWNER-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) Str::uuid(), 0, 8)),
                    'notes' => 'Legal owner or ownership classification changed through the vehicle master.',
                    'performed_by' => $request->user()->id,
                ]);
                $vehicle->leases()->where('status', 'draft')->update([
                    'owner_id_at_start' => $nextOwnerId,
                    'ownership_type_at_start' => $nextOwnershipType ?: 'company_owned',
                    'updated_user_id' => $request->user()->id,
                ]);
            }

            $this->syncCurrentLease($vehicle->fresh(), $currentLeaseData, $request, $leaseService);
        });
        $vehicle->refresh()->load('currentLease.financeProvider');

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle updated',
            'data' => ['vehicle' => new VehicleResource($vehicle)]
        ]);
    }

    public function recordHandover(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'handover_mileage' => [
                'required',
                'integer',
                'min:' . (int) ($vehicle->current_mileage ?? $vehicle->initial_mileage ?? 0),
            ],
            'handover_at' => ['required', 'date'],
            'handover_location' => ['required', 'string', 'max:255'],
            'handover_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $vehicle->update([
            ...$data,
            'current_mileage' => $data['handover_mileage'],
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle handover recorded',
            'data' => ['vehicle' => new VehicleResource($vehicle->fresh())],
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

        if (array_key_exists('actual_vehicle_images', $data) && is_array($data['actual_vehicle_images'])) {
            $data['actual_vehicle_images'] = $this->normalizeVehicleImages($data['actual_vehicle_images']);

            $primaryImage = collect($data['actual_vehicle_images'])->firstWhere('is_primary', true)
                ?? ($data['actual_vehicle_images'][0] ?? null);

            if ($primaryImage) {
                $data['thumbnail'] = $primaryImage;
            }
        }

        if (!empty($data['owner_payment_method_id']) && !empty($data['owner_id'])) {
            $belongsToOwner = \App\Models\PaymentMethod::whereKey($data['owner_payment_method_id'])
                ->whereIn('payable_type', ['vehicle_owner', \App\Models\Vehicle\VehicleOwner::class])
                ->where('payable_id', $data['owner_id'])
                ->exists();

            abort_unless($belongsToOwner, 422, 'Selected payment method does not belong to the selected owner.');
        }

        return $data;
    }

    private function pullCurrentLeaseData(array &$data): ?array
    {
        $currentLease = $data['current_lease'] ?? null;
        unset($data['current_lease']);

        if (!is_array($currentLease) || !($currentLease['enabled'] ?? false)) {
            return null;
        }

        unset($currentLease['enabled']);
        $currentLease['currency'] = strtoupper((string) ($currentLease['currency'] ?? 'LKR'));

        return $currentLease;
    }

    private function syncCurrentLease(
        Vehicle $vehicle,
        ?array $data,
        Request $request,
        VehicleLeaseService $leaseService
    ): void {
        if ($data === null) {
            return;
        }

        $requestedLeaseId = $data['id'] ?? null;
        unset($data['id']);
        $currentLease = VehicleLease::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', ['draft', 'active', 'expired', 'closure_pending'])
            ->lockForUpdate()
            ->latest('start_date')
            ->first();

        if (!$requestedLeaseId && $currentLease) {
            throw ValidationException::withMessages([
                'current_lease.id' => ['A current contract was created or changed after this vehicle form was opened. Reload before editing it.'],
            ]);
        }

        if ($requestedLeaseId && (!$currentLease || $currentLease->id !== $requestedLeaseId)) {
            throw ValidationException::withMessages([
                'current_lease.id' => ['The selected contract is not the current open contract for this vehicle.'],
            ]);
        }

        if ($currentLease) {
            abort_unless($request->user()->can('vehicle-leases.edit'), 403);
            if ($currentLease->status !== 'draft') {
                throw ValidationException::withMessages([
                    'current_lease' => ['Activated finance terms cannot be overwritten from vehicle onboarding. Use Finance & Leasing for payments, discharge, release, or renewal.'],
                ]);
            }
            $leaseService->updateDraft($currentLease, $data, $request->user()->id);
            return;
        }

        abort_unless($request->user()->can('vehicle-leases.create'), 403);
        $leaseService->create($vehicle, $data, $request->user()->id);
    }

    private function normalizeVehicleImages(array $images): array
    {
        $uploadedAt = now()->toIso8601String();
        $primaryAssigned = false;

        $normalized = collect($images)
            ->filter(fn ($image) => is_array($image) || is_string($image))
            ->map(function ($image) use ($uploadedAt, &$primaryAssigned) {
                $payload = is_string($image) ? ['path' => $image] : $image;
                $payload['path'] = $payload['path'] ?? $payload['url'] ?? null;
                $payload['uploaded_at'] = $payload['uploaded_at'] ?? $uploadedAt;

                $isPrimary = filter_var($payload['is_primary'] ?? false, FILTER_VALIDATE_BOOL);
                $payload['is_primary'] = !$primaryAssigned && $isPrimary;
                $primaryAssigned = $primaryAssigned || $payload['is_primary'];

                return $payload;
            })
            ->filter(fn ($image) => !empty($image['path']) || !empty($image['url']))
            ->values()
            ->all();

        if (!$primaryAssigned && count($normalized) > 0) {
            $normalized[0]['is_primary'] = true;
        }

        return $normalized;
    }

    private function buildAvailabilityPeriods(Carbon $start, Carbon $end, string $granularity): array
    {
        if ($granularity === 'year') {
            $period = CarbonPeriod::create($start->copy()->startOfYear(), '1 year', $end->copy()->startOfYear());

            return collect($period)->map(function (Carbon $date) use ($start, $end) {
                $periodStart = $date->copy()->startOfYear()->max($start);
                $periodEnd = $date->copy()->endOfYear()->min($end);

                return [
                    'key' => $date->format('Y'),
                    'label' => $date->format('Y'),
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                ];
            })->values()->all();
        }

        if ($granularity === 'month') {
            $period = CarbonPeriod::create($start->copy()->startOfMonth(), '1 month', $end->copy()->startOfMonth());

            return collect($period)->map(function (Carbon $date) use ($start, $end) {
                $periodStart = $date->copy()->startOfMonth()->max($start);
                $periodEnd = $date->copy()->endOfMonth()->min($end);

                return [
                    'key' => $date->format('Y-m'),
                    'label' => $date->format('M Y'),
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                ];
            })->values()->all();
        }

        $period = CarbonPeriod::create($start->copy()->startOfDay(), '1 day', $end->copy()->startOfDay());

        return collect($period)->map(fn (Carbon $date) => [
            'key' => $date->format('Y-m-d'),
            'label' => $date->format('d M'),
            'start_date' => $date->toDateString(),
            'end_date' => $date->toDateString(),
        ])->values()->all();
    }

    private function maintenanceDatesByVehicle($vehicleIds, Carbon $start, Carbon $end)
    {
        $dates = collect();

        if (Schema::hasTable('vehicle_maintenance_records')) {
            $recordDateColumn = Schema::hasColumn('vehicle_maintenance_records', 'completion_date')
                ? 'completion_date'
                : 'performed_date';

            $dates = $dates->merge(
                VehicleMaintenanceRecord::query()
                    ->whereIn('vehicle_id', $vehicleIds)
                    ->whereBetween($recordDateColumn, [$start->toDateString(), $end->toDateString()])
                    ->get(['vehicle_id', $recordDateColumn])
                    ->map(fn ($record) => [
                        'vehicle_id' => $record->vehicle_id,
                        'date' => $record->{$recordDateColumn},
                    ])
            );
        }

        if (Schema::hasTable('vehicle_maintenance_schedules')) {
            $scheduleDateColumn = Schema::hasColumn('vehicle_maintenance_schedules', 'scheduled_date')
                ? 'scheduled_date'
                : 'next_due_date';

            $dates = $dates->merge(
                VehicleMaintenanceSchedule::query()
                    ->whereIn('vehicle_id', $vehicleIds)
                    ->whereBetween($scheduleDateColumn, [$start->toDateString(), $end->toDateString()])
                    ->get(['vehicle_id', $scheduleDateColumn])
                    ->map(fn ($schedule) => [
                        'vehicle_id' => $schedule->vehicle_id,
                        'date' => $schedule->{$scheduleDateColumn},
                    ])
            );
        }

        return $dates->groupBy('vehicle_id')->map(fn ($items) => $items->pluck('date')->filter()->values());
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
        $context = app(\App\Services\Pricing\PricingContextPolicyService::class)
            ->effectiveContext((string) $request->input('context', 'portal'));
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
