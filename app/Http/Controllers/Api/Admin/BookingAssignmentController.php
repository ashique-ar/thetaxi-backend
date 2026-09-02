<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver\Driver;
use App\Models\Vehicle\Vehicle;
use App\Models\DriverAssignment;
use App\Models\Vehicle\VehicleAssignment;
use App\Models\Booking\BookingItem;
use App\Enums\TripPhase;
use App\Services\AssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookingAssignmentController extends Controller
{
    protected AssignmentService $assignmentService;

    public function __construct(
        AssignmentService $assignmentService
    ) {
        $this->assignmentService = $assignmentService;

        $this->middleware('permission:vehicles.edit')->only([
            'getVehicleDefaultDriver', 'updateVehicleDefaultDriver',
        ]);
        $this->middleware('permission:drivers.edit')->only([
            'getDriverDefaultVehicle', 'updateDriverDefaultVehicle',
        ]);
        $this->middleware('permission:drivers.view')->only(['driversWithStatus']);
        $this->middleware('permission:bookings.create')->only(['createBookingAssignment']);
    }

    // ---------------------------------------------------------------
    // 7.1 — Default Driver-Vehicle Management
    // ---------------------------------------------------------------

    /**
     * Get the default driver for a vehicle.
     *
     * GET /api/vehicles/{vehicle}/default-driver
     */
    public function getVehicleDefaultDriver(Vehicle $vehicle): JsonResponse
    {
        $vehicle->load('defaultDriver.user');

        return response()->json([
            'status' => 'success',
            'data' => [
                'vehicle_id' => $vehicle->id,
                'default_driver_id' => $vehicle->default_driver_id,
                'default_driver' => $vehicle->defaultDriver ? [
                    'id' => $vehicle->defaultDriver->id,
                    'name' => $vehicle->defaultDriver->user
                        ? trim($vehicle->defaultDriver->user->first_name . ' ' . $vehicle->defaultDriver->user->last_name)
                        : null,
                    'code' => $vehicle->defaultDriver->code,
                    'license_no' => $vehicle->defaultDriver->license_no,
                ] : null,
            ],
        ]);
    }

    /**
     * Update the default driver for a vehicle.
     *
     * PUT /api/vehicles/{vehicle}/default-driver
     */
    public function updateVehicleDefaultDriver(Request $request, Vehicle $vehicle): JsonResponse
    {
        $request->validate([
            'default_driver_id' => 'nullable|uuid|exists:drivers,id',
        ]);

        $vehicle->update([
            'default_driver_id' => $request->default_driver_id,
            'updated_user_id' => $request->user()->id,
        ]);

        $vehicle->load('defaultDriver.user');

        return response()->json([
            'status' => 'success',
            'message' => 'Default driver updated',
            'data' => [
                'vehicle_id' => $vehicle->id,
                'default_driver_id' => $vehicle->default_driver_id,
                'default_driver' => $vehicle->defaultDriver ? [
                    'id' => $vehicle->defaultDriver->id,
                    'name' => $vehicle->defaultDriver->user
                        ? trim($vehicle->defaultDriver->user->first_name . ' ' . $vehicle->defaultDriver->user->last_name)
                        : null,
                ] : null,
            ],
        ]);
    }

    /**
     * Get the default vehicle for a driver.
     *
     * GET /api/drivers/{driver}/default-vehicle
     */
    public function getDriverDefaultVehicle(Driver $driver): JsonResponse
    {
        $driver->load('defaultVehicle');

        return response()->json([
            'status' => 'success',
            'data' => [
                'driver_id' => $driver->id,
                'default_vehicle_id' => $driver->default_vehicle_id,
                'default_vehicle' => $driver->defaultVehicle ? [
                    'id' => $driver->defaultVehicle->id,
                    'title' => $driver->defaultVehicle->title,
                    'license_plate' => $driver->defaultVehicle->license_plate,
                    'registration_no' => $driver->defaultVehicle->registration_no,
                ] : null,
            ],
        ]);
    }

    /**
     * Update the default vehicle for a driver.
     *
     * PUT /api/drivers/{driver}/default-vehicle
     */
    public function updateDriverDefaultVehicle(Request $request, Driver $driver): JsonResponse
    {
        $request->validate([
            'default_vehicle_id' => 'nullable|uuid|exists:vehicles,id',
        ]);

        $driver->update([
            'default_vehicle_id' => $request->default_vehicle_id,
            'updated_user_id' => $request->user()->id,
        ]);

        $driver->load('defaultVehicle');

        return response()->json([
            'status' => 'success',
            'message' => 'Default vehicle updated',
            'data' => [
                'driver_id' => $driver->id,
                'default_vehicle_id' => $driver->default_vehicle_id,
                'default_vehicle' => $driver->defaultVehicle ? [
                    'id' => $driver->defaultVehicle->id,
                    'title' => $driver->defaultVehicle->title,
                    'license_plate' => $driver->defaultVehicle->license_plate,
                ] : null,
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // 7.2 — Drivers with Status
    // ---------------------------------------------------------------

    /**
     * Get all drivers with computed Driver_Status (online, offline, on_hire).
     *
     * GET /api/drivers/with-status
     *
     * Includes default_vehicle_id in each driver response.
     */
    public function driversWithStatus(Request $request): JsonResponse
    {
        $query = Driver::with(['user', 'defaultVehicle', 'activeSession']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('first_name', 'like', "%{$search}%")
                          ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        $drivers = $query->get();

        // Batch-load in-progress assignment IDs for on_hire detection
        $onHireDriverIds = DriverAssignment::whereIn('trip_phase', [
                TripPhase::ACCEPTED,
                TripPhase::PICKUP_ARRIVED,
                TripPhase::IN_PROGRESS,
            ])
            ->whereIn('status', ['active', 'confirmed'])
            ->pluck('driver_id')
            ->unique()
            ->toArray();

        $data = $drivers->map(function (Driver $driver) use ($onHireDriverIds) {
            $isOnline = (bool) $driver->is_online;
            $isOnHire = in_array($driver->id, $onHireDriverIds);

            if ($isOnHire) {
                $status = 'on_hire';
            } elseif ($isOnline) {
                $status = 'online';
            } else {
                $status = 'offline';
            }

            return [
                'id' => $driver->id,
                'name' => $driver->user
                    ? trim($driver->user->first_name . ' ' . $driver->user->last_name)
                    : ($driver->code ?? 'Unknown'),
                'code' => $driver->code,
                'license_no' => $driver->license_no,
                'driver_status' => $status,
                'is_online' => $isOnline,
                'last_active_at' => $driver->last_active_at?->toIso8601String(),
                'default_vehicle_id' => $driver->default_vehicle_id,
                'default_vehicle' => $driver->defaultVehicle ? [
                    'id' => $driver->defaultVehicle->id,
                    'title' => $driver->defaultVehicle->title,
                    'license_plate' => $driver->defaultVehicle->license_plate,
                ] : null,
            ];
        });

        // Group counts
        $grouped = $data->groupBy('driver_status');

        return response()->json([
            'status' => 'success',
            'data' => $data->values(),
            'meta' => [
                'total' => $data->count(),
                'online' => $grouped->get('online', collect())->count(),
                'offline' => $grouped->get('offline', collect())->count(),
                'on_hire' => $grouped->get('on_hire', collect())->count(),
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // 7.3 — Booking Assignment Creation
    // ---------------------------------------------------------------

    /**
     * Create or update Vehicle_Assignment and/or Driver_Assignment for a booking item.
     *
     * POST /api/booking-items/{bookingItem}/assign
     *
     * Driver mobile notification is intentionally not sent here; dispatch sends it.
     */
    public function createBookingAssignment(Request $request, BookingItem $bookingItem): JsonResponse
    {
        $bookingItem->loadMissing('serviceType');
        $request->validate([
            'vehicle_id' => 'nullable|uuid|exists:vehicles,id',
            'driver_id' => 'nullable|uuid|exists:drivers,id',
        ]);

        if (!$request->vehicle_id && !$request->driver_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'At least one of vehicle_id or driver_id is required',
            ], 422);
        }

        if ($request->driver_id && $bookingItem->serviceType?->type === 'self_drive') {
            return response()->json([
                'status' => 'error',
                'message' => 'A driver cannot be assigned to a self-drive service.',
            ], 422);
        }

        try {
            return DB::transaction(function () use ($request, $bookingItem) {
                $booking = $bookingItem->booking;
                $results = [];

                // --- Vehicle Assignment ---
                if ($request->vehicle_id) {
                    $existingVA = VehicleAssignment::where('booking_id', $booking->id)
                        ->where('vehicle_id', $request->vehicle_id)
                        ->where('status', 'active')
                        ->first();

                    if (!$existingVA) {
                        $va = $this->assignmentService->createVehicleAssignment([
                            'vehicle_id' => $request->vehicle_id,
                            'booking_id' => $booking->id,
                            'customer_name' => $booking->customer->name ?? 'Unknown',
                            'service_type' => $bookingItem->serviceType?->name ?? $booking->service_type ?? null,
                            'assigned_from' => $bookingItem->from_date ?? $booking->from_date,
                            'assigned_to' => $bookingItem->to_date ?? $booking->to_date,
                            'assignment_type' => 'primary',
                            'status' => 'active',
                            'requires_approval' => false,
                        ]);
                        $results['vehicle_assignment'] = $va;
                    } else {
                        $results['vehicle_assignment'] = $existingVA;
                    }

                    // Update booking item vehicle reference
                    $bookingItem->update(['vehicle_id' => $request->vehicle_id]);
                }

                // --- Driver Assignment ---
                if ($request->driver_id) {
                    $existingDA = DriverAssignment::where('booking_id', $booking->id)
                        ->where('driver_id', $request->driver_id)
                        ->where('status', 'active')
                        ->first();

                    if (!$existingDA) {
                        $da = $this->assignmentService->createDriverAssignment([
                            'driver_id' => $request->driver_id,
                            'booking_id' => $booking->id,
                            'booking_item_id' => $bookingItem->id,
                            'customer_name' => $booking->customer->name ?? 'Unknown',
                            'service_type' => $bookingItem->serviceType?->name ?? $booking->service_type ?? null,
                            'assigned_from' => $bookingItem->from_date ?? $booking->from_date,
                            'assigned_to' => $bookingItem->to_date ?? $booking->to_date,
                            'assignment_type' => 'primary',
                            'status' => 'active',
                            'requires_approval' => false,
                        ]);

                        $results['driver_assignment'] = $da;
                    } else {
                        $results['driver_assignment'] = $existingDA;
                    }

                    // Update booking item driver reference
                    $bookingItem->update(['driver_id' => $request->driver_id]);
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Assignment created successfully',
                    'data' => $results,
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error('Failed to create booking assignment', [
                'booking_item_id' => $bookingItem->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create assignment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
