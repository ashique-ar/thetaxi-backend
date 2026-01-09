<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AssignmentService;
use App\Services\BookingFlowService;
use App\Models\Booking\Booking;
use App\Models\Vehicle\VehicleAddon;
use App\Models\Booking\BookingAddon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AssignmentController extends Controller
{
    protected AssignmentService $assignmentService;
    protected BookingFlowService $bookingFlowService;

    public function __construct(
        AssignmentService $assignmentService,
        BookingFlowService $bookingFlowService
    ) {
        $this->assignmentService = $assignmentService;
        $this->bookingFlowService = $bookingFlowService;
    }

    /**
     * Get assignment details for booking (for Assignment Management screen)
     */
    public function getAssignmentDetails(string $bookingId): JsonResponse
    {
        try {
            $booking = Booking::with([
                'customer',
                'vehicle.vehicleGroup',
                'driver.user',
                'vehicleAssignments.vehicle',
                'driverAssignments.driver.user',
                'bookingItems'
            ])->findOrFail($bookingId);

            $result = [
                'booking' => [
                    'id' => $booking->id,
                    'customer_name' => $booking->customer->name ?? 'Unknown Customer',
                    'service_type' => $booking->serviceType,
                    'from_date' => $booking->from_date,
                    'to_date' => $booking->to_date,
                    'from_time' => $booking->from_time,
                    'to_time' => $booking->to_time,
                    'pickup_location' => $booking->pickup_location,
                    'dropoff_location' => $booking->dropoff_location,
                    'status' => $booking->status,
                ],
                'current_vehicle' => $booking->vehicle ? [
                    'id' => $booking->vehicle->id,
                    'name' => $booking->vehicle->title,
                    'license_plate' => $booking->vehicle->license_plate,
                    'vehicle_group' => $booking->vehicle->vehicleGroup ? [
                        'id' => $booking->vehicle->vehicleGroup->id,
                        'name' => $booking->vehicle->vehicleGroup->name,
                    ] : null,
                ] : null,
                'current_driver' => $booking->driver ? [
                    'id' => $booking->driver->id,
                    'name' => $booking->driver->user ? 
                        $booking->driver->user->first_name . ' ' . $booking->driver->user->last_name 
                        : 'Unknown Driver',
                    'license_number' => $booking->driver->license_no,
                ] : null,
                'assignments' => [
                    'vehicle' => $booking->vehicleAssignments->map(function($assignment) {
                        return [
                            'id' => $assignment->id,
                            'status' => $assignment->status,
                            'assignment_type' => $assignment->assignment_type,
                            'requires_approval' => $assignment->requires_approval,
                            'manually_confirmed' => $assignment->manually_confirmed,
                            'assigned_from' => $assignment->assigned_from,
                            'assigned_to' => $assignment->assigned_to,
                            'vehicle' => [
                                'id' => $assignment->vehicle->id,
                                'name' => $assignment->vehicle->title,
                                'license_plate' => $assignment->vehicle->license_plate,
                            ],
                        ];
                    }),
                    'driver' => $booking->driverAssignments->map(function($assignment) {
                        return [
                            'id' => $assignment->id,
                            'status' => $assignment->status,
                            'assignment_type' => $assignment->assignment_type,
                            'requires_approval' => $assignment->requires_approval,
                            'manually_confirmed' => $assignment->manually_confirmed,
                            'assigned_from' => $assignment->assigned_from,
                            'assigned_to' => $assignment->assigned_to,
                            'driver' => [
                                'id' => $assignment->driver->id,
                                'name' => $assignment->driver->user ? 
                                    $assignment->driver->user->first_name . ' ' . $assignment->driver->user->last_name 
                                    : 'Unknown Driver',
                                'license_number' => $assignment->driver->license_no,
                            ],
                        ];
                    }),
                ],
            ];

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Assignment details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting assignment details: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get assignment details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Perform vehicle/driver swap with financial calculations
     */
    public function performSwap(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string|exists:bookings,id',
            'swap_type' => 'required|string|in:vehicle,driver,both',
            'new_vehicle_id' => 'nullable|string|exists:vehicles,id',
            'new_driver_id' => 'nullable|string|exists:drivers,id',
            'reason' => 'required|string|in:customer_request,vehicle_breakdown,customer_fault,accident,general',
            'notes' => 'nullable|string',
            'carrier_cost' => 'nullable|numeric|min:0',
            'mechanic_cost' => 'nullable|numeric|min:0',
            'swap_fee' => 'nullable|numeric|min:0',
            'photos' => 'nullable|array',
            'documents' => 'nullable|array',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $booking = Booking::findOrFail($request->booking_id);
                $swapTime = now();
                $oldVehicleId = $booking->vehicle_id;
                $oldDriverId = $booking->driver_id;
                $newVehicleId = $request->new_vehicle_id;
                $newDriverId = $request->new_driver_id;
                $reason = $request->reason;

                // Close current assignments at swap time
                if ($oldVehicleId && ($request->swap_type === 'vehicle' || $request->swap_type === 'both')) {
                    $booking->vehicleAssignments()
                        ->where('status', 'active')
                        ->update(['actual_end' => $swapTime]);
                }

                if ($oldDriverId && ($request->swap_type === 'driver' || $request->swap_type === 'both')) {
                    $booking->driverAssignments()
                        ->where('status', 'active')
                        ->update(['actual_end' => $swapTime]);
                }

                // Calculate remaining period from now to original end
                $originalEnd = Carbon::parse($booking->to_date);
                $remainingHours = $swapTime->diffInHours($originalEnd);
                $remainingDays = ceil($remainingHours / 24);

                $addons = [];

                // Create new assignments for remaining period
                if ($newVehicleId && ($request->swap_type === 'vehicle' || $request->swap_type === 'both')) {
                    $this->assignmentService->createVehicleAssignment([
                        'vehicle_id' => $newVehicleId,
                        'booking_id' => $booking->id,
                        'customer_name' => $booking->customer->name ?? 'Unknown',
                        'service_type' => $booking->service_type,
                        'assigned_from' => $swapTime,
                        'assigned_to' => $originalEnd,
                        'assignment_type' => 'primary',
                        'status' => 'active',
                        'requires_approval' => false,
                        'assignment_notes' => "Swap from vehicle {$oldVehicleId}. Reason: {$reason}",
                    ]);

                    // Update booking's vehicle_id
                    $booking->update(['vehicle_id' => $newVehicleId]);

                    // Calculate price difference for remaining period
                    $oldVehiclePricing = $this->calculateVehiclePricingForPeriod($oldVehicleId, $swapTime, $originalEnd);
                    $newVehiclePricing = $this->calculateVehiclePricingForPeriod($newVehicleId, $swapTime, $originalEnd);
                    $priceDiff = $newVehiclePricing - $oldVehiclePricing;

                    if ($priceDiff != 0) {
                        $addons[] = $this->createSwapAddon($booking->id, 'vehicle_price_difference', $priceDiff, $remainingDays);
                    }
                }

                if ($newDriverId && ($request->swap_type === 'driver' || $request->swap_type === 'both')) {
                    $this->assignmentService->createDriverAssignment([
                        'driver_id' => $newDriverId,
                        'booking_id' => $booking->id,
                        'customer_name' => $booking->customer->name ?? 'Unknown',
                        'service_type' => $booking->service_type,
                        'assigned_from' => $swapTime,
                        'assigned_to' => $originalEnd,
                        'assignment_type' => 'primary',
                        'status' => 'active',
                        'requires_approval' => false,
                        'assignment_notes' => "Swap from driver {$oldDriverId}. Reason: {$reason}",
                    ]);

                    // Update booking's driver_id
                    $booking->update(['driver_id' => $newDriverId]);
                }

                // Add financial add-ons based on reason and costs
                if ($request->carrier_cost > 0) {
                    $customerPays = in_array($reason, ['customer_request', 'customer_fault', 'accident']);
                    $amount = $customerPays ? $request->carrier_cost : -$request->carrier_cost;
                    $addons[] = $this->createSwapAddon($booking->id, 'carrier_cost', $amount, 1);
                }

                if ($request->mechanic_cost > 0) {
                    $customerPays = in_array($reason, ['customer_fault']);
                    $amount = $customerPays ? $request->mechanic_cost : -$request->mechanic_cost;
                    $addons[] = $this->createSwapAddon($booking->id, 'mechanic_cost', $amount, 1);
                }

                if ($request->swap_fee > 0) {
                    $customerPays = in_array($reason, ['customer_request', 'customer_fault']);
                    $amount = $customerPays ? $request->swap_fee : -$request->swap_fee;
                    $addons[] = $this->createSwapAddon($booking->id, 'swap_fee', $amount, 1);
                }

                // Store swap record for audit
                $swapRecord = [
                    'booking_id' => $booking->id,
                    'swap_type' => $request->swap_type,
                    'reason' => $reason,
                    'old_vehicle_id' => $oldVehicleId,
                    'new_vehicle_id' => $newVehicleId,
                    'old_driver_id' => $oldDriverId,
                    'new_driver_id' => $newDriverId,
                    'swap_time' => $swapTime,
                    'initiated_by' => Auth::id(),
                    'notes' => $request->notes,
                    'photos' => $request->photos,
                    'documents' => $request->documents,
                    'carrier_cost' => $request->carrier_cost,
                    'mechanic_cost' => $request->mechanic_cost,
                    'swap_fee' => $request->swap_fee,
                ];

                // Log the swap (you might want to create a swaps table for this)
                Log::info('Vehicle/Driver swap performed', $swapRecord);

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'booking_id' => $booking->id,
                        'swap_record' => $swapRecord,
                        'addons_created' => $addons,
                        'message' => 'Swap completed successfully',
                    ],
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error performing swap: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to perform swap',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Record breakdown incident and execute selected action
     */
    public function recordBreakdown(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string|exists:bookings,id',
            'action' => 'required|string|in:customer_reimburse,workshop,send_mechanic,send_driver,replace_vehicle',
            'description' => 'required|string',
            'location' => 'nullable|string',
            'photos' => 'nullable|array',
            'estimated_cost' => 'nullable|numeric|min:0',
            'workshop_details' => 'nullable|array',
            'replacement_vehicle_id' => 'nullable|string|exists:vehicles,id',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $booking = Booking::findOrFail($request->booking_id);
                $addons = [];

                switch ($request->action) {
                    case 'customer_reimburse':
                        $addons[] = $this->createSwapAddon(
                            $booking->id, 
                            'reimbursement', 
                            -($request->estimated_cost ?? 0), 
                            1
                        );
                        break;

                    case 'workshop':
                        $addons[] = $this->createSwapAddon(
                            $booking->id, 
                            'workshop_charge', 
                            -($request->estimated_cost ?? 0), 
                            1
                        );
                        break;

                    case 'send_mechanic':
                        $addons[] = $this->createSwapAddon(
                            $booking->id, 
                            'mechanic_callout', 
                            -($request->estimated_cost ?? 0), 
                            1
                        );
                        break;

                    case 'send_driver':
                        $addons[] = $this->createSwapAddon(
                            $booking->id, 
                            'driver_callout', 
                            -($request->estimated_cost ?? 0), 
                            1
                        );
                        break;

                    case 'replace_vehicle':
                        if ($request->replacement_vehicle_id) {
                            // This would internally call the swap method
                            return $this->performSwap(new Request([
                                'booking_id' => $request->booking_id,
                                'swap_type' => 'vehicle',
                                'new_vehicle_id' => $request->replacement_vehicle_id,
                                'reason' => 'vehicle_breakdown',
                                'notes' => $request->description,
                                'photos' => $request->photos,
                            ]));
                        }
                        break;
                }

                // Log breakdown incident
                $incidentRecord = [
                    'booking_id' => $booking->id,
                    'incident_type' => 'breakdown',
                    'action_taken' => $request->action,
                    'description' => $request->description,
                    'location' => $request->location,
                    'estimated_cost' => $request->estimated_cost,
                    'photos' => $request->photos,
                    'workshop_details' => $request->workshop_details,
                    'reported_by' => Auth::id(),
                    'reported_at' => now(),
                ];

                Log::info('Breakdown incident recorded', $incidentRecord);

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'incident_record' => $incidentRecord,
                        'addons_created' => $addons,
                        'message' => 'Breakdown incident recorded and processed successfully',
                    ],
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error recording breakdown: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to record breakdown',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate vehicle pricing for a specific period (helper method)
     */
    private function calculateVehiclePricingForPeriod(string $vehicleId, Carbon $from, Carbon $to): float
    {
        try {
            $hours = $from->diffInHours($to);
            $days = ceil($hours / 24);
            
            // This is a simplified calculation - you might want to use your actual pricing service
            // For now, return a base rate per day (you should integrate with your pricing system)
            return $days * 100; // $100 per day as example
        } catch (\Exception $e) {
            Log::warning('Error calculating vehicle pricing: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Create addon for swap-related charges/credits
     */
    private function createSwapAddon(string $bookingId, string $type, float $amount, int $quantity): array
    {
        try {
            // Create or find vehicle addon for this type
            $vehicleAddon = VehicleAddon::firstOrCreate([
                'name' => $this->getAddonName($type),
            ], [
                'description' => $this->getAddonDescription($type),
                'amount' => abs($amount),
                'rate_type' => 'flat',
                'billing_type' => 'one_time',
            ]);

            // Create booking addon
            $bookingAddon = BookingAddon::create([
                'booking_id' => $bookingId,
                'vehicle_addon_id' => $vehicleAddon->id,
                'quantity' => $quantity,
                'unit_price' => $amount, // Can be negative for credits
                'total_amount' => $amount * $quantity,
                'notes' => "Auto-generated for swap/incident",
                'created_user_id' => Auth::id(),
            ]);

            return [
                'addon_id' => $bookingAddon->id,
                'type' => $type,
                'amount' => $amount * $quantity,
                'description' => $vehicleAddon->description,
            ];
        } catch (\Exception $e) {
            Log::error('Error creating swap addon: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get human-readable addon names
     */
    private function getAddonName(string $type): string
    {
        $names = [
            'vehicle_price_difference' => 'Vehicle Price Difference',
            'carrier_cost' => 'Carrier Service',
            'mechanic_cost' => 'Mechanic Service',
            'swap_fee' => 'Vehicle/Driver Swap Fee',
            'reimbursement' => 'Customer Reimbursement',
            'workshop_charge' => 'Workshop Service',
            'mechanic_callout' => 'Mechanic Call-out',
            'driver_callout' => 'Driver Call-out',
        ];

        return $names[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Get addon descriptions
     */
    private function getAddonDescription(string $type): string
    {
        $descriptions = [
            'vehicle_price_difference' => 'Price difference for vehicle swap (remaining period)',
            'carrier_cost' => 'Carrier service for vehicle transport',
            'mechanic_cost' => 'Mechanic service for vehicle repair',
            'swap_fee' => 'Administrative fee for vehicle/driver change',
            'reimbursement' => 'Reimbursement to customer for expenses',
            'workshop_charge' => 'Workshop service charges',
            'mechanic_callout' => 'Emergency mechanic call-out service',
            'driver_callout' => 'Emergency driver dispatch service',
        ];

        return $descriptions[$type] ?? "Service charges for {$type}";
    }
}
