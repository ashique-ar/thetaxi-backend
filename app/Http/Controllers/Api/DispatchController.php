<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Dispatch;
use App\Models\Driver\Driver;
use App\Models\Vehicle\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DispatchController extends Controller
{
    /**
     * Schedule dispatch for a booking
     */
    public function scheduleDispatch(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id' => 'required|exists:drivers,id',
            'scheduled_departure_time' => 'required|date',
            'estimated_arrival_time' => 'required|date',
            'pickup_instructions' => 'nullable|string',
            'priority' => 'required|in:low,normal,high,urgent'
        ]);

        DB::beginTransaction();
        try {
            $booking = Booking::findOrFail($request->booking_id);
            
            // Check vehicle and driver availability
            $vehicleAvailable = $this->checkVehicleAvailability(
                $request->vehicle_id, 
                $request->scheduled_departure_time
            );
            
            $driverAvailable = $this->checkDriverAvailability(
                $request->driver_id, 
                $request->scheduled_departure_time
            );

            if (!$vehicleAvailable) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vehicle is not available at the scheduled time'
                ], 400);
            }

            if (!$driverAvailable) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Driver is not available at the scheduled time'
                ], 400);
            }

            // Create dispatch record
            $dispatch = Dispatch::create([
                'booking_id' => $request->booking_id,
                'vehicle_id' => $request->vehicle_id,
                'driver_id' => $request->driver_id,
                'scheduled_departure_time' => $request->scheduled_departure_time,
                'estimated_arrival_time' => $request->estimated_arrival_time,
                'pickup_instructions' => $request->pickup_instructions,
                'priority' => $request->priority,
                'status' => 'scheduled',
                'created_by' => auth()->id()
            ]);

            // Update booking status
            $booking->update([
                'status' => 'dispatched',
                'vehicle_id' => $request->vehicle_id,
                'driver_id' => $request->driver_id
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $dispatch->load(['booking', 'vehicle', 'driver'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to schedule dispatch: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm dispatch departure
     */
    public function confirmDispatch(Request $request, string $dispatchId): JsonResponse
    {
        $request->validate([
            'actual_departure_time' => 'nullable|date',
            'vehicle_condition_notes' => 'nullable|string',
            'driver_confirmation' => 'nullable|boolean',
            'photos' => 'nullable|array',
            'photos.*' => 'string', // Base64 or file paths
            'dispatcher_notes' => 'nullable|string'
        ]);

        $dispatch = Dispatch::findOrFail($dispatchId);

        $dispatch->update([
            'status' => 'confirmed',
            'actual_departure_time' => $request->actual_departure_time ?? now(),
            'vehicle_condition_notes' => $request->vehicle_condition_notes,
            'driver_confirmation' => $request->driver_confirmation ?? true,
            'dispatcher_notes' => $request->dispatcher_notes,
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now()
        ]);

        // Store photos if provided
        if ($request->photos) {
            foreach ($request->photos as $photo) {
                $dispatch->photos()->create([
                    'photo_path' => $photo,
                    'photo_type' => 'departure',
                    'uploaded_by' => auth()->id()
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $dispatch->load(['booking', 'vehicle', 'driver', 'photos'])
        ]);
    }

    /**
     * Complete dispatch (arrival at pickup location)
     */
    public function completeDispatch(Request $request, string $dispatchId): JsonResponse
    {
        $request->validate([
            'arrival_time' => 'required|date',
            'vehicle_handover_confirmed' => 'required|boolean',
            'customer_signature' => 'nullable|string',
            'photos' => 'nullable|array',
            'photos.*' => 'string',
            'handover_notes' => 'nullable|string',
            'vehicle_condition_on_delivery' => 'required|string',
            'fuel_level_on_delivery' => 'required|numeric|min:0|max:100'
        ]);

        $dispatch = Dispatch::findOrFail($dispatchId);

        DB::beginTransaction();
        try {
            $dispatch->update([
                'status' => 'completed',
                'actual_arrival_time' => $request->arrival_time,
                'vehicle_handover_confirmed' => $request->vehicle_handover_confirmed,
                'customer_signature' => $request->customer_signature,
                'handover_notes' => $request->handover_notes,
                'vehicle_condition_on_delivery' => $request->vehicle_condition_on_delivery,
                'fuel_level_on_delivery' => $request->fuel_level_on_delivery,
                'completed_by' => auth()->id(),
                'completed_at' => now()
            ]);

            // Store arrival photos
            if ($request->photos) {
                foreach ($request->photos as $photo) {
                    $dispatch->photos()->create([
                        'photo_path' => $photo,
                        'photo_type' => 'arrival',
                        'uploaded_by' => auth()->id()
                    ]);
                }
            }

            // Update booking status to in_progress
            $dispatch->booking->update(['status' => 'in_progress']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $dispatch->load(['booking', 'vehicle', 'driver', 'photos'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete dispatch: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dispatch history for a booking
     */
    public function getDispatchHistory(string $bookingId): JsonResponse
    {
        $dispatches = Dispatch::where('booking_id', $bookingId)
                             ->with(['vehicle', 'driver', 'photos'])
                             ->orderBy('created_at', 'desc')
                             ->get();

        return response()->json([
            'status' => 'success',
            'data' => $dispatches
        ]);
    }

    /**
     * Send instructions to driver
     */
    public function sendDriverInstructions(Request $request, string $dispatchId): JsonResponse
    {
        $request->validate([
            'message' => 'required|string',
            'priority' => 'required|in:normal,urgent,emergency',
            'requires_acknowledgment' => 'required|boolean',
            'photo_required' => 'nullable|boolean',
            'location_update_required' => 'nullable|boolean'
        ]);

        $dispatch = Dispatch::findOrFail($dispatchId);

        $instruction = $dispatch->instructions()->create([
            'message' => $request->message,
            'priority' => $request->priority,
            'requires_acknowledgment' => $request->requires_acknowledgment,
            'photo_required' => $request->photo_required ?? false,
            'location_update_required' => $request->location_update_required ?? false,
            'sent_by' => auth()->id(),
            'sent_at' => now(),
            'delivery_status' => 'sent'
        ]);

        // Here you would integrate with SMS/Push notification service
        // $this->sendNotificationToDriver($dispatch->driver, $instruction);

        return response()->json([
            'status' => 'success',
            'data' => [
                'message_id' => $instruction->id,
                'delivery_status' => 'sent',
                'estimated_delivery_time' => now()->addMinutes(2)
            ]
        ]);
    }

    /**
     * Bulk schedule dispatches
     */
    public function bulkScheduleDispatches(Request $request): JsonResponse
    {
        $request->validate([
            'requests' => 'required|array',
            'requests.*.booking_id' => 'required|exists:bookings,id',
            'requests.*.vehicle_id' => 'required|exists:vehicles,id',
            'requests.*.driver_id' => 'required|exists:drivers,id',
            'requests.*.scheduled_departure_time' => 'required|date',
            'requests.*.priority' => 'required|in:low,normal,high,urgent'
        ]);

        $successful = [];
        $failed = [];
        $warnings = [];

        DB::beginTransaction();
        try {
            foreach ($request->requests as $dispatchRequest) {
                try {
                    // Check availability
                    $vehicleAvailable = $this->checkVehicleAvailability(
                        $dispatchRequest['vehicle_id'],
                        $dispatchRequest['scheduled_departure_time']
                    );

                    $driverAvailable = $this->checkDriverAvailability(
                        $dispatchRequest['driver_id'],
                        $dispatchRequest['scheduled_departure_time']
                    );

                    if (!$vehicleAvailable || !$driverAvailable) {
                        $failed[] = [
                            'request' => $dispatchRequest,
                            'error' => 'Vehicle or driver not available'
                        ];
                        continue;
                    }

                    $dispatch = Dispatch::create([
                        'booking_id' => $dispatchRequest['booking_id'],
                        'vehicle_id' => $dispatchRequest['vehicle_id'],
                        'driver_id' => $dispatchRequest['driver_id'],
                        'scheduled_departure_time' => $dispatchRequest['scheduled_departure_time'],
                        'priority' => $dispatchRequest['priority'],
                        'status' => 'scheduled',
                        'created_by' => auth()->id()
                    ]);

                    $successful[] = $dispatch;

                } catch (\Exception $e) {
                    $failed[] = [
                        'request' => $dispatchRequest,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'successful' => $successful,
                    'failed' => $failed,
                    'warnings' => $warnings
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Bulk dispatch scheduling failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active dispatches
     */
    public function getActiveDispatches(Request $request): JsonResponse
    {
        $query = Dispatch::with(['booking.customer', 'vehicle', 'driver'])
                        ->whereIn('status', ['scheduled', 'confirmed', 'in_transit']);

        if ($request->driver_id) {
            $query->where('driver_id', $request->driver_id);
        }

        if ($request->vehicle_id) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        if ($request->priority) {
            $query->where('priority', $request->priority);
        }

        $dispatches = $query->orderBy('scheduled_departure_time')
                           ->paginate($request->per_page ?? 15);

        return response()->json([
            'status' => 'success',
            'data' => $dispatches
        ]);
    }

    // Private helper methods

    /**
     * Check if vehicle is available at given time
     */
    private function checkVehicleAvailability(string $vehicleId, string $dateTime): bool
    {
        $conflicts = Dispatch::where('vehicle_id', $vehicleId)
                            ->where('status', '!=', 'cancelled')
                            ->where('scheduled_departure_time', '<=', $dateTime)
                            ->where('estimated_completion_time', '>=', $dateTime)
                            ->exists();

        return !$conflicts;
    }

    /**
     * Check if driver is available at given time
     */
    private function checkDriverAvailability(string $driverId, string $dateTime): bool
    {
        $conflicts = Dispatch::where('driver_id', $driverId)
                            ->where('status', '!=', 'cancelled')
                            ->where('scheduled_departure_time', '<=', $dateTime)
                            ->where('estimated_completion_time', '>=', $dateTime)
                            ->exists();

        return !$conflicts;
    }
}
