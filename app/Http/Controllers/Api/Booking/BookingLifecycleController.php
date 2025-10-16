<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Services\BookingLifecycleService;
use App\Models\Booking\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class BookingLifecycleController extends Controller
{
    protected $lifecycleService;

    public function __construct(BookingLifecycleService $lifecycleService)
    {
        $this->lifecycleService = $lifecycleService;
    }

    /**
     * Get booking lifecycle summary
     */
    public function getLifecycleSummary(string $bookingId): JsonResponse
    {
        try {
            $summary = $this->lifecycleService->getLifecycleSummary($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $summary,
                'message' => 'Lifecycle summary retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting lifecycle summary', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve lifecycle summary'
            ], 500);
        }
    }

    /**
     * Dispatch vehicle for booking
     */
    public function dispatchVehicle(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string',
            'vehicle_condition_notes' => 'required|string',
            'fuel_level' => 'required|integer|min:0|max:10',
            'mileage' => 'required|integer|min:0',
            'dispatched_notes' => 'nullable|string',
            'handover_time' => 'required|date',
            'handover_location' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            // Check authorization
            $booking = Booking::findOrFail($request->booking_id);
            Gate::authorize('manage-booking', $booking);

            $dispatch = $this->lifecycleService->dispatchVehicle(
                $request->booking_id,
                array_merge($request->only([
                    'vehicle_condition_notes',
                    'fuel_level',
                    'mileage',
                    'dispatched_notes',
                    'handover_time',
                    'handover_location'
                ]), [
                    'dispatched_by' => Auth::id()
                ])
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $dispatch,
                'message' => 'Vehicle dispatched successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error dispatching vehicle', [
                'booking_id' => $request->booking_id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to dispatch vehicle: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process vehicle return
     */
    public function processReturn(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string',
            'return_condition_notes' => 'required|string',
            'fuel_level' => 'required|integer|min:0|max:10',
            'mileage' => 'required|integer|min:0',
            'return_notes' => 'nullable|string',
            'actual_return_time' => 'required|date',
            'damages' => 'nullable|array',
            'damages.*.type' => 'required_with:damages|string',
            'damages.*.description' => 'required_with:damages|string',
            'damages.*.severity' => 'required_with:damages|in:minor,moderate,major',
            'charges' => 'nullable|array',
            'charges.*.type' => 'required_with:charges|string',
            'charges.*.amount' => 'required_with:charges|numeric|min:0',
            'charges.*.description' => 'required_with:charges|string',
        ]);

        try {
            DB::beginTransaction();

            // Check authorization
            $booking = Booking::findOrFail($request->booking_id);
            Gate::authorize('manage-booking', $booking);

            $dispatch = $this->lifecycleService->processReturn(
                $request->booking_id,
                array_merge($request->only([
                    'return_condition_notes',
                    'fuel_level',
                    'mileage',
                    'return_notes',
                    'actual_return_time',
                    'damages',
                    'charges'
                ]), [
                    'returned_by' => Auth::id()
                ])
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $dispatch,
                'message' => 'Vehicle return processed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing return', [
                'booking_id' => $request->booking_id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process return: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start QC inspection
     */
    public function startQCInspection(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string',
            'inspector_id' => 'required|exists:users,id',
        ]);

        try {
            DB::beginTransaction();

            // Check authorization
            $booking = Booking::findOrFail($request->booking_id);
            Gate::authorize('manage-booking', $booking);

            $qc = $this->lifecycleService->startQCInspection(
                $request->booking_id,
                $request->inspector_id
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $qc,
                'message' => 'QC inspection started successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error starting QC inspection', [
                'booking_id' => $request->booking_id,
                'inspector_id' => $request->inspector_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to start QC inspection: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete QC inspection
     */
    public function completeQCInspection(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string',
            'cleanliness_rating' => 'required|integer|min:1|max:5',
            'fuel_level' => 'required|integer|min:0|max:10',
            'mileage' => 'required|integer|min:0',
            'interior_condition' => 'required|array',
            'interior_condition.cleanliness' => 'required|in:excellent,good,fair,poor',
            'interior_condition.wear_tear' => 'required|in:none,minor,moderate,major',
            'interior_condition.damages' => 'required|in:none,minor,moderate,major',
            'exterior_condition' => 'required|array',
            'exterior_condition.body_condition' => 'required|in:excellent,good,fair,poor',
            'exterior_condition.paint_condition' => 'required|in:excellent,good,fair,poor',
            'exterior_condition.tire_condition' => 'required|in:excellent,good,fair,poor',
            'mechanical_condition' => 'required|array',
            'mechanical_condition.engine' => 'required|in:excellent,good,fair,poor',
            'mechanical_condition.brakes' => 'required|in:excellent,good,fair,poor',
            'mechanical_condition.transmission' => 'required|in:excellent,good,fair,poor',
            'repair_required' => 'required|boolean',
            'estimated_repair_cost' => 'nullable|numeric|min:0',
            'repair_notes' => 'nullable|string',
            'qc_notes' => 'nullable|string',
            'requires_maintenance' => 'required|boolean',
            'next_maintenance_due' => 'nullable|date',
        ]);

        try {
            DB::beginTransaction();

            // Check authorization
            $booking = Booking::findOrFail($request->booking_id);
            Gate::authorize('manage-booking', $booking);

            $qc = $this->lifecycleService->completeQCInspection(
                $request->booking_id,
                array_merge($request->except(['booking_id']), [
                    'completed_by' => Auth::id()
                ])
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $qc,
                'message' => 'QC inspection completed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error completing QC inspection', [
                'booking_id' => $request->booking_id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete QC inspection: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete repairs
     */
    public function completeRepairs(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            // Check authorization
            $booking = Booking::findOrFail($request->booking_id);
            Gate::authorize('manage-booking', $booking);

            $qc = $this->lifecycleService->completeRepairs(
                $request->booking_id,
                [
                    'completed_by' => Auth::id()
                ]
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $qc,
                'message' => 'Repairs completed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error completing repairs', [
                'booking_id' => $request->booking_id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete repairs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete booking lifecycle
     */
    public function completeBooking(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            // Check authorization
            $booking = Booking::findOrFail($request->booking_id);
            Gate::authorize('manage-booking', $booking);

            $result = $this->lifecycleService->completeBooking(
                $request->booking_id,
                [
                    'completed_by' => Auth::id()
                ]
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Booking completed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error completing booking', [
                'booking_id' => $request->booking_id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete booking: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available inspectors
     */
    public function getAvailableInspectors(): JsonResponse
    {
        try {
            $inspectors = $this->lifecycleService->getAvailableInspectors();

            return response()->json([
                'status' => 'success',
                'data' => $inspectors,
                'message' => 'Available inspectors retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting available inspectors', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve available inspectors'
            ], 500);
        }
    }

    /**
     * Get ongoing details for a booking
     */
    public function getOngoingDetails(string $bookingId): JsonResponse
    {
        try {
            $details = $this->lifecycleService->getOngoingDetails($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $details,
                'message' => 'Ongoing details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting ongoing details', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve ongoing details'
            ], 500);
        }
    }

    /**
     * Get dispatch details for a booking
     */
    public function getDispatchDetails(string $bookingId): JsonResponse
    {
        try {
            $details = $this->lifecycleService->getDispatchDetails($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $details,
                'message' => 'Dispatch details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting dispatch details', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dispatch details'
            ], 500);
        }
    }

    /**
     * Get QC details for a booking
     */
    public function getQCDetails(string $bookingId): JsonResponse
    {
        try {
            $details = $this->lifecycleService->getQCDetails($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $details,
                'message' => 'QC details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting QC details', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve QC details'
            ], 500);
        }
    }

    /**
     * Process vehicle replacement (temporary or override)
     */
    public function processReplacement(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string',
            'replacement_type' => 'required|in:temporary,override',
            'new_vehicle_id' => 'required|string',
            'new_driver_id' => 'nullable|string',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
            'start_time' => 'nullable|date',
            'end_time' => 'nullable|date',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($request->booking_id);
            Gate::authorize('manage-booking', $booking);

            $replacement = $this->lifecycleService->processReplacement(
                $request->booking_id,
                array_merge($request->only([
                    'new_vehicle_id',
                    'new_driver_id', 
                    'reason',
                    'notes',
                    'start_time',
                    'end_time'
                ]), [
                    'replacement_type' => $request->replacement_type
                ])
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $replacement,
                'message' => 'Vehicle replacement processed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing replacement', [
                'booking_id' => $request->booking_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process replacement'
            ], 500);
        }
    }

    /**
     * Check vehicle availability with maintenance blocking
     */
    public function checkVehicleAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_id' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        try {
            $availability = $this->lifecycleService->checkVehicleAvailability(
                $request->vehicle_id,
                $request->start_date,
                $request->end_date
            );

            return response()->json([
                'status' => 'success',
                'data' => $availability,
                'message' => 'Availability checked successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error checking vehicle availability', [
                'vehicle_id' => $request->vehicle_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check availability'
            ], 500);
        }
    }

    /**
     * Get vehicles blocked by maintenance
     */
    public function getMaintenanceBlocks(): JsonResponse
    {
        try {
            $blocks = $this->lifecycleService->getMaintenanceBlocks();

            return response()->json([
                'status' => 'success',
                'data' => $blocks,
                'message' => 'Maintenance blocks retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting maintenance blocks', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve maintenance blocks'
            ], 500);
        }
    }

    /**
     * Trigger availability pool update after QC completion
     */
    public function updateAvailabilityPool(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string',
        ]);

        try {
            $poolUpdate = $this->lifecycleService->updateAvailabilityPool($request->booking_id);

            return response()->json([
                'status' => 'success',
                'data' => $poolUpdate,
                'message' => 'Availability pool updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating availability pool', [
                'booking_id' => $request->booking_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update availability pool'
            ], 500);
        }
    }
}
