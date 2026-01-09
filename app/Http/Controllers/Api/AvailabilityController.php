<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\Vehicle;
use App\Models\Driver\Driver;
use App\Models\Booking\Booking;
use App\Models\VehicleBlock;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class AvailabilityController extends Controller
{
    /**
     * Check vehicle availability for given date range
     */
    public function checkVehicleAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'vehicle_ids' => 'nullable|array',
            'vehicle_ids.*' => 'exists:vehicles,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'booking_id' => 'nullable|exists:bookings,id' // Exclude this booking from conflicts
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        if ($request->vehicle_id) {
            $vehicleIds = [$request->vehicle_id];
        } elseif ($request->vehicle_ids) {
            $vehicleIds = $request->vehicle_ids;
        } else {
            $vehicleIds = Vehicle::where('status', 'available')->pluck('id')->toArray();
        }

        $availability = [];

        foreach ($vehicleIds as $vehicleId) {
            $vehicle = Vehicle::find($vehicleId);
            if (!$vehicle)
                continue;

            $conflicts = $this->getVehicleConflicts($vehicleId, $startDate, $endDate, $request->booking_id);
            $blocks = $this->getVehicleBlocks($vehicleId, $startDate, $endDate);

            $availability[] = [
                'vehicle_id' => $vehicleId,
                'vehicle' => $vehicle,
                'available' => count($conflicts) === 0 && count($blocks) === 0,
                'conflicts' => $conflicts,
                'blocks' => $blocks,
                'next_available_slot' => $this->getNextAvailableSlot($vehicleId, $endDate)
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $availability
        ]);
    }

    /**
     * Check driver availability
     */
    public function checkDriverAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'driver_id' => 'nullable|exists:drivers,id',
            'driver_ids' => 'nullable|array',
            'driver_ids.*' => 'exists:drivers,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'booking_id' => 'nullable|exists:bookings,id'
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        if ($request->driver_id) {
            $driverIds = [$request->driver_id];
        } elseif ($request->driver_ids) {
            $driverIds = $request->driver_ids;
        } else {
            $driverIds = Driver::where('status', 'active')->pluck('id')->toArray();
        }

        $availability = [];

        foreach ($driverIds as $driverId) {
            $driver = Driver::find($driverId);
            if (!$driver)
                continue;

            $conflicts = $this->getDriverConflicts($driverId, $startDate, $endDate, $request->booking_id);
            $timeOff = $this->getDriverTimeOff($driverId, $startDate, $endDate);

            $availability[] = [
                'driver_id' => $driverId,
                'driver' => $driver,
                'available' => count($conflicts) === 0 && count($timeOff) === 0,
                'conflicts' => $conflicts,
                'time_off' => $timeOff,
                'next_available_slot' => $this->getDriverNextAvailableSlot($driverId, $endDate)
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $availability
        ]);
    }

    /**
     * Get available vehicles for a date range
     */
    public function getAvailableVehicles(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'vehicle_type' => 'nullable|string',
            'transmission' => 'nullable|in:manual,automatic',
            'fuel_type' => 'nullable|string',
            'capacity_min' => 'nullable|integer|min:1',
            'include_maintenance' => 'nullable|boolean'
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $query = Vehicle::query();

        // Apply filters
        if ($request->vehicle_type) {
            $query->where('type', $request->vehicle_type);
        }

        if ($request->transmission) {
            $query->where('transmission', $request->transmission);
        }

        if ($request->fuel_type) {
            $query->where('fuel_type', $request->fuel_type);
        }

        if ($request->capacity_min) {
            $query->where('capacity', '>=', $request->capacity_min);
        }

        if (!$request->include_maintenance) {
            $query->where('status', '!=', 'maintenance');
        }

        $vehicles = $query->with(['category', 'features'])->get();

        $availableVehicles = [];

        foreach ($vehicles as $vehicle) {
            $conflicts = $this->getVehicleConflicts($vehicle->id, $startDate, $endDate);
            $blocks = $this->getVehicleBlocks($vehicle->id, $startDate, $endDate);

            if (count($conflicts) === 0 && count($blocks) === 0) {
                $availableVehicles[] = [
                    'vehicle' => $vehicle,
                    'pricing' => $this->getVehiclePricing($vehicle->id, $startDate, $endDate),
                    'last_service_date' => $vehicle->last_service_date,
                    'next_service_due' => $vehicle->next_service_due
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $availableVehicles,
            'total_available' => count($availableVehicles)
        ]);
    }

    /**
     * Block vehicle for maintenance or other reasons
     */
    public function blockVehicle(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'block_type' => 'required|in:maintenance,repair,inspection,unavailable',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
            'priority' => 'required|in:low,normal,high,urgent'
        ]);

        // Check for existing bookings in the block period
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $conflicts = $this->getVehicleConflicts($request->vehicle_id, $startDate, $endDate);

        if (count($conflicts) > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot block vehicle - existing bookings found',
                'conflicts' => $conflicts
            ], 400);
        }

        $block = VehicleBlock::create([
            'vehicle_id' => $request->vehicle_id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'block_type' => $request->block_type,
            'reason' => $request->reason,
            'notes' => $request->notes,
            'priority' => $request->priority,
            'status' => 'active',
            'created_by' => auth()->id()
        ]);

        // Update vehicle status if it's a maintenance block
        if ($request->block_type === 'maintenance') {
            Vehicle::where('id', $request->vehicle_id)
                ->update(['status' => 'maintenance']);
        }

        return response()->json([
            'status' => 'success',
            'data' => $block->load('vehicle')
        ], 201);
    }

    /**
     * Remove vehicle block
     */
    public function removeVehicleBlock(Request $request, string $blockId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string',
            'notes' => 'nullable|string'
        ]);

        $block = VehicleBlock::findOrFail($blockId);

        $block->update([
            'status' => 'removed',
            'removal_reason' => $request->reason,
            'removal_notes' => $request->notes,
            'removed_by' => auth()->id(),
            'removed_at' => now()
        ]);

        // Update vehicle status back to available if it was maintenance
        if ($block->block_type === 'maintenance') {
            Vehicle::where('id', $block->vehicle_id)
                ->update(['status' => 'available']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Vehicle block removed successfully'
        ]);
    }

    /**
     * Get availability calendar for a date range
     */
    public function getAvailabilityCalendar(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'resource_type' => 'required|in:vehicles,drivers',
            'resource_ids' => 'nullable|array'
        ]);

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);
        $calendar = [];

        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $dayData = [
                'date' => $currentDate->format('Y-m-d'),
                'resources' => []
            ];

            if ($request->resource_type === 'vehicles') {
                $resourceQuery = Vehicle::query();
                if ($request->resource_ids) {
                    $resourceQuery->whereIn('id', $request->resource_ids);
                }
                $resources = $resourceQuery->get();

                foreach ($resources as $resource) {
                    $dayStart = $currentDate->copy()->startOfDay();
                    $dayEnd = $currentDate->copy()->endOfDay();

                    $conflicts = $this->getVehicleConflicts($resource->id, $dayStart, $dayEnd);
                    $blocks = $this->getVehicleBlocks($resource->id, $dayStart, $dayEnd);

                    $dayData['resources'][] = [
                        'id' => $resource->id,
                        'name' => $resource->make . ' ' . $resource->model,
                        'available' => count($conflicts) === 0 && count($blocks) === 0,
                        'utilization' => $this->calculateDayUtilization($conflicts, $blocks)
                    ];
                }
            }

            $calendar[] = $dayData;
            $currentDate->addDay();
        }

        return response()->json([
            'status' => 'success',
            'data' => $calendar
        ]);
    }

    // Private helper methods

    private function getVehicleConflicts(string $vehicleId, Carbon $startDate, Carbon $endDate, ?string $excludeBookingId = null): array
    {
        $query = Booking::where('vehicle_id', $vehicleId)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            });

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        return $query->with('customer')->get()->toArray();
    }

    private function getVehicleBlocks(string $vehicleId, Carbon $startDate, Carbon $endDate): array
    {
        return VehicleBlock::where('vehicle_id', $vehicleId)
            ->where('status', 'active')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })
            ->get()
            ->toArray();
    }

    private function getDriverConflicts(string $driverId, Carbon $startDate, Carbon $endDate, ?string $excludeBookingId = null): array
    {
        // Import needed: use App\Models\DriverAssignment or similar
        // Query through DriverAssignment instead of bookings directly
        $query = \App\Models\DriverAssignment::where('driver_id', $driverId)
            ->join('bookings', 'driver_assignments.booking_id', '=', 'bookings.id')
            ->where('bookings.status', '!=', 'cancelled')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('driver_assignments.from_date', [$startDate, $endDate])
                    ->orWhereBetween('driver_assignments.to_date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('driver_assignments.from_date', '<=', $startDate)
                            ->where('driver_assignments.to_date', '>=', $endDate);
                    });
            });

        if ($excludeBookingId) {
            $query->where('bookings.id', '!=', $excludeBookingId);
        }

        return $query->with('booking.customer')->select('driver_assignments.*')->get()->map(fn($item) => $item->booking)->toArray();
    }

    private function getDriverTimeOff(string $driverId, Carbon $startDate, Carbon $endDate): array
    {
        // This would query a driver_time_off table
        // For now, returning empty array
        return [];
    }

    private function getNextAvailableSlot(string $vehicleId, Carbon $afterDate): ?Carbon
    {
        // Logic to find next available slot for vehicle
        return $afterDate->copy()->addDays(1);
    }

    private function getDriverNextAvailableSlot(string $driverId, Carbon $afterDate): ?Carbon
    {
        // Logic to find next available slot for driver
        return $afterDate->copy()->addDays(1);
    }

    private function getVehiclePricing(string $vehicleId, Carbon $startDate, Carbon $endDate): array
    {
        // Calculate pricing based on vehicle, dates, duration
        $days = $startDate->diffInDays($endDate) + 1;
        $vehicle = Vehicle::find($vehicleId);

        return [
            'daily_rate' => $vehicle->daily_rate ?? 0,
            'total_days' => $days,
            'base_total' => ($vehicle->daily_rate ?? 0) * $days,
            'currency' => 'USD'
        ];
    }

    private function calculateDayUtilization(array $conflicts, array $blocks): float
    {
        // Calculate percentage of day that's occupied
        $totalMinutes = 24 * 60; // Total minutes in a day
        $occupiedMinutes = 0;

        // Add conflict minutes
        foreach ($conflicts as $conflict) {
            $start = Carbon::parse($conflict['start_date']);
            $end = Carbon::parse($conflict['end_date']);
            $occupiedMinutes += $start->diffInMinutes($end);
        }

        // Add block minutes
        foreach ($blocks as $block) {
            $start = Carbon::parse($block['start_date']);
            $end = Carbon::parse($block['end_date']);
            $occupiedMinutes += $start->diffInMinutes($end);
        }

        return min(100, ($occupiedMinutes / $totalMinutes) * 100);
    }
}
