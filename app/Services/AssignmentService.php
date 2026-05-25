<?php

namespace App\Services;

use App\Models\Vehicle\Vehicle;
use App\Models\Driver\Driver;
use App\Models\Booking\Booking;
use App\Models\Vehicle\VehicleAssignment;
use App\Models\DriverAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AssignmentService
{
    public function __construct(
        private readonly AvailabilityEnforcementService $availabilityEnforcement,
    ) {}

    /**
     * Get enhanced vehicle availability with assignment information
     */
    public function getEnhancedVehicleAvailability(string $vehicleId, Carbon $fromDate, Carbon $toDate, ?string $excludeBookingId = null): array
    {
        $vehicle = Vehicle::with([
            'defaultDriver.user', 
            'assignments.booking.customer',
            'assignments.booking.driver.user'
        ])->findOrFail($vehicleId);

        $conflicts = $vehicle->getAssignmentConflicts($fromDate, $toDate, $excludeBookingId);
        $currentDriver = $vehicle->getCurrentDriver();
        $hasForceDefault = $vehicle->hasForceDefaultDriver();

        $enforcementResult = $this->availabilityEnforcement->checkVehicle($vehicle, $fromDate, $toDate);

        return [
            'vehicle_id' => $vehicle->id,
            'vehicle_name' => $vehicle->title,
            'license_plate' => $vehicle->license_plate,
            'status' => $vehicle->status,
            'availability_status' => $enforcementResult['available']
                ? $this->determineVehicleAvailabilityStatus($vehicle, $conflicts, $fromDate, $toDate)
                : 'blocked',
            'allows_concurrent' => $vehicle->allowsConcurrentAssignments(),
            'has_conflicts' => count($conflicts) > 0,
            'conflicts' => $conflicts,
            'enforcement' => $enforcementResult,
            'current_driver' => $currentDriver ? [
                'id' => $currentDriver->id,
                'name' => $currentDriver->user ? $currentDriver->user->first_name . ' ' . $currentDriver->user->last_name : 'Unknown Driver',
                'license_number' => $currentDriver->license_no,
                'is_default' => $currentDriver->id === $vehicle->default_driver_id,
                'is_force_default' => $hasForceDefault && $currentDriver->id === $vehicle->default_driver_id,
            ] : null,
            'default_driver' => $vehicle->defaultDriver ? [
                'id' => $vehicle->defaultDriver->id,
                'name' => $vehicle->defaultDriver->user ? $vehicle->defaultDriver->user->first_name . ' ' . $vehicle->defaultDriver->user->last_name : 'Unknown Driver',
                'license_number' => $vehicle->defaultDriver->license_no,
                'is_force_default' => $hasForceDefault,
                'available_for_period' => $vehicle->defaultDriver->isAvailableForPeriod($fromDate, $toDate, $excludeBookingId),
            ] : null,
            'assignment_recommendations' => $this->getVehicleAssignmentRecommendations($vehicle, $conflicts, $fromDate, $toDate),
        ];
    }

    /**
     * Get enhanced driver availability with assignment information
     */
    public function getEnhancedDriverAvailability(string $driverId, Carbon $fromDate, Carbon $toDate, ?string $excludeBookingId = null): array
    {
        $driver = Driver::with([
            'defaultVehicle', 
            'assignments.booking.customer',
            'assignments.booking.vehicle'
        ])->findOrFail($driverId);

        $conflicts = $driver->getAssignmentConflicts($fromDate, $toDate, $excludeBookingId);
        $currentVehicle = $driver->getCurrentVehicle();

        $enforcementResult = $this->availabilityEnforcement->checkDriver($driver, $fromDate, $toDate);

        return [
            'driver_id' => $driver->id,
            'driver_name' => $driver->user ? $driver->user->first_name . ' ' . $driver->user->last_name : 'Unknown Driver',
            'license_number' => $driver->license_no,
            'status' => $driver->status ?? 'active',
            'availability_status' => $enforcementResult['available']
                ? $this->determineDriverAvailabilityStatus($driver, $conflicts, $fromDate, $toDate)
                : 'blocked',
            'has_conflicts' => count($conflicts) > 0,
            'conflicts' => $conflicts,
            'enforcement' => $enforcementResult,
            'current_vehicle' => $currentVehicle ? [
                'id' => $currentVehicle->id,
                'name' => $currentVehicle->title,
                'license_plate' => $currentVehicle->license_plate,
                'is_default' => $currentVehicle->id === $driver->default_vehicle_id,
            ] : null,
            'default_vehicle' => $driver->defaultVehicle ? [
                'id' => $driver->defaultVehicle->id,
                'name' => $driver->defaultVehicle->title,
                'license_plate' => $driver->defaultVehicle->license_plate,
            ] : null,
            'assignment_recommendations' => $this->getDriverAssignmentRecommendations($driver, $conflicts, $fromDate, $toDate),
        ];
    }

    /**
     * Create vehicle assignment for booking
     */
    public function createVehicleAssignment(array $params): VehicleAssignment
    {
        return DB::transaction(function () use ($params) {
            $userId = Auth::id() ?? ($params['assigned_by'] ?? ($params['created_user_id'] ?? null));
            
            $assignment = VehicleAssignment::create([
                'vehicle_id' => $params['vehicle_id'],
                'booking_id' => $params['booking_id'],
                'parent_assignment_id' => $params['parent_assignment_id'] ?? null,
                'customer_name' => $params['customer_name'],
                'service_type' => $params['service_type'],
                'assigned_from' => $params['assigned_from'],
                'assigned_to' => $params['assigned_to'],
                'assignment_type' => $params['assignment_type'] ?? 'primary',
                'status' => $params['status'] ?? 'active',
                'overlap_type' => $params['overlap_type'] ?? null,
                'overlap_details' => $params['overlap_details'] ?? [],
                'requires_approval' => $params['requires_approval'] ?? false,
                'override_reasons' => $params['override_reasons'] ?? [],
                'assigned_by' => $userId,
                'created_user_id' => $userId,
            ]);

            return $assignment;
        });
    }

    /**
     * Create driver assignment for booking
     */
    public function createDriverAssignment(array $params): DriverAssignment
    {
        return DB::transaction(function () use ($params) {
            $userId = Auth::id() ?? ($params['assigned_by'] ?? ($params['created_user_id'] ?? null));
            
            $assignment = DriverAssignment::create([
                'id' => Str::uuid(),
                'driver_id' => $params['driver_id'],
                'booking_id' => $params['booking_id'],
                'booking_item_id' => $params['booking_item_id'] ?? null,
                'parent_assignment_id' => $params['parent_assignment_id'] ?? null,
                'customer_name' => $params['customer_name'],
                'service_type' => $params['service_type'],
                'assigned_from' => $params['assigned_from'],
                'assigned_to' => $params['assigned_to'],
                'assignment_type' => $params['assignment_type'] ?? 'primary',
                'status' => $params['status'] ?? 'active',
                'overlap_type' => $params['overlap_type'] ?? null,
                'overlap_details' => $params['overlap_details'] ?? [],
                'requires_approval' => $params['requires_approval'] ?? false,
                'override_reasons' => $params['override_reasons'] ?? [],
                'hourly_rate' => $params['hourly_rate'] ?? null,
                'overtime_applicable' => $params['overtime_applicable'] ?? false,
                'assigned_by' => $userId,
                'created_user_id' => $userId,
            ]);

            return $assignment;
        });
    }

    /**
     * Process assignment confirmation with conflict resolution
     */
    public function processAssignmentConfirmation(array $params): array
    {
        return DB::transaction(function () use ($params) {
            $vehicleId = $params['vehicle_id'] ?? null;
            $driverId = $params['driver_id'] ?? null;
            $bookingId = $params['booking_id'];
            $requestedBookingItemId = $params['booking_item_id'] ?? null;
            $assignmentType = $params['assignment_type'] ?? 'primary';
            $overrideReasons = $params['override_reasons'] ?? [];
            $requiresApprovalForAssignment = $assignmentType !== 'primary';
            $assignmentStatus = $requiresApprovalForAssignment ? 'pending_approval' : 'active';

            $booking = Booking::with('bookingItems')->findOrFail($bookingId);
            $bookingItem = null;

            if (!empty($requestedBookingItemId)) {
                $bookingItem = $booking->bookingItems->firstWhere('id', $requestedBookingItemId);

                if (!$bookingItem) {
                    throw new \InvalidArgumentException('Selected booking item does not belong to this booking');
                }
            } else {
                $bookingItem = $booking->bookingItems
                    ->sortBy(function ($item) {
                        return sprintf(
                            '%08d-%s',
                            (int) ($item->trip_number ?? 0),
                            (string) ($item->id ?? '')
                        );
                    })
                    ->first();
            }
            
            $fromDate = Carbon::parse($params['from_date']);
            $toDate = Carbon::parse($params['to_date']);

            $assignments = [];
            $requiresApproval = false;

            // Handle vehicle assignment
            if ($vehicleId) {
                $vehicleAssignmentParams = [
                    'vehicle_id' => $vehicleId,
                    'booking_id' => $bookingId,
                    'customer_name' => $params['customer_name'],
                    'service_type' => $params['service_type'],
                    'assigned_from' => $fromDate,
                    'assigned_to' => $toDate,
                    'assignment_type' => $assignmentType,
                    'status' => $assignmentStatus,
                    'requires_approval' => $requiresApprovalForAssignment,
                    'override_reasons' => $overrideReasons,
                ];

                if (isset($params['overlap_details'])) {
                    $vehicleAssignmentParams['overlap_type'] = $params['overlap_type'] ?? 'full';
                    $vehicleAssignmentParams['overlap_details'] = $params['overlap_details'];
                }

                $vehicleAssignment = $this->createVehicleAssignment($vehicleAssignmentParams);
                $assignments['vehicle'] = $vehicleAssignment;

                if ($requiresApprovalForAssignment) {
                    $requiresApproval = true;
                }
            }

            // Handle driver assignment
            if ($driverId) {
                $driverAssignmentParams = [
                    'driver_id' => $driverId,
                    'booking_id' => $bookingId,
                    'booking_item_id' => $bookingItem?->id,
                    'customer_name' => $params['customer_name'],
                    'service_type' => $params['service_type'],
                    'assigned_from' => $fromDate,
                    'assigned_to' => $toDate,
                    'assignment_type' => $assignmentType,
                    'status' => $assignmentStatus,
                    'requires_approval' => $requiresApprovalForAssignment,
                    'override_reasons' => $overrideReasons,
                ];

                if (isset($params['hourly_rate'])) {
                    $driverAssignmentParams['hourly_rate'] = $params['hourly_rate'];
                }

                $driverAssignment = $this->createDriverAssignment($driverAssignmentParams);
                $assignments['driver'] = $driverAssignment;

                if ($requiresApprovalForAssignment) {
                    $requiresApproval = true;
                }
            }

            // Persist current selection so Assignment Management can restore it after refresh.
            $bookingItemUpdates = [];
            $bookingUpdates = [];

            if ($vehicleId) {
                $bookingItemUpdates['vehicle_id'] = $vehicleId;
                $bookingUpdates['vehicle_id'] = $vehicleId;
            }

            if ($driverId) {
                $bookingItemUpdates['driver_id'] = $driverId;
                $bookingUpdates['driver_id'] = $driverId;
            }

            if ($bookingItem && !empty($bookingItemUpdates)) {
                $bookingItem->update($bookingItemUpdates);
            }

            // Keep legacy booking-level fields in sync for single-item bookings.
            if ((!$bookingItem || $booking->bookingItems->count() <= 1) && !empty($bookingUpdates)) {
                $booking->update($bookingUpdates);
            }

            return [
                'assignments' => $assignments,
                'requires_approval' => $requiresApproval,
                'assignment_type' => $assignmentType,
                'conflicts_resolved' => count($params['conflicts_resolved'] ?? []),
                'message' => $requiresApproval ? 
                    'Assignments created and submitted for approval' : 
                    'Assignments created successfully',
            ];
        });
    }

    /**
     * Handle vehicle selection with assignment logic
     */
    public function handleVehicleSelection(string $vehicleId, Carbon $fromDate, Carbon $toDate, string $serviceType, ?string $excludeBookingId = null): array
    {
        $vehicle = Vehicle::with(['defaultDriver.user', 'assignments.booking.customer', 'assignments.booking.driver.user'])
                          ->findOrFail($vehicleId);

        $conflicts = $vehicle->getAssignmentConflicts($fromDate, $toDate, $excludeBookingId);
        $availabilityStatus = $this->determineVehicleAvailabilityStatus($vehicle, $conflicts, $fromDate, $toDate);
        $currentDriver = $vehicle->getCurrentDriver();
        
        $result = [
            'vehicle_id' => $vehicle->id,
            'availability_status' => $availabilityStatus,
            'has_conflicts' => count($conflicts) > 0,
            'conflicts' => $conflicts,
            'requires_confirmation' => $availabilityStatus !== 'available',
            'allows_concurrent' => $vehicle->allowsConcurrentAssignments(),
            'current_driver' => null,
            'recommended_driver' => null,
            'force_default_driver' => false,
            'assignment_options' => [],
        ];

        // Determine driver selection logic
        if ($currentDriver) {
            $driverAvailability = $this->getEnhancedDriverAvailability(
                $currentDriver->id, 
                $fromDate, 
                $toDate, 
                $excludeBookingId
            );

            $result['current_driver'] = [
                'id' => $currentDriver->id,
                'name' => $currentDriver->user ? $currentDriver->user->first_name . ' ' . $currentDriver->user->last_name : 'Unknown Driver',
                'license_number' => $currentDriver->license_no,
                'is_available' => $driverAvailability['availability_status'] === 'available',
                'availability_status' => $driverAvailability['availability_status'],
                'conflicts' => $driverAvailability['conflicts'],
                'is_default' => $currentDriver->id === $vehicle->default_driver_id,
                'is_force_default' => $vehicle->hasForceDefaultDriver() && $currentDriver->id === $vehicle->default_driver_id,
            ];

            $result['recommended_driver'] = $result['current_driver'];
        } elseif ($vehicle->defaultDriver) {
            $defaultDriverAvailability = $this->getEnhancedDriverAvailability(
                $vehicle->defaultDriver->id, 
                $fromDate, 
                $toDate, 
                $excludeBookingId
            );

            $result['recommended_driver'] = [
                'id' => $vehicle->defaultDriver->id,
                'name' => $vehicle->defaultDriver->user ? $vehicle->defaultDriver->user->first_name . ' ' . $vehicle->defaultDriver->user->last_name : 'Unknown Driver',
                'license_number' => $vehicle->defaultDriver->license_no,
                'is_available' => $defaultDriverAvailability['availability_status'] === 'available',
                'availability_status' => $defaultDriverAvailability['availability_status'],
                'conflicts' => $defaultDriverAvailability['conflicts'],
                'is_default' => true,
                'is_force_default' => $vehicle->hasForceDefaultDriver(),
            ];
        }

        $result['force_default_driver'] = $vehicle->hasForceDefaultDriver();

        // Generate assignment options
        $result['assignment_options'] = $this->generateAssignmentOptions($vehicle, $conflicts, $fromDate, $toDate);

        return $result;
    }

    /**
     * Generate assignment options based on conflicts
     */
    public function generateAssignmentOptions(Vehicle $vehicle, array $conflicts, Carbon $fromDate, Carbon $toDate): array
    {
        $options = [];

        if (empty($conflicts)) {
            $options[] = [
                'type' => 'direct',
                'title' => 'Direct Assignment',
                'description' => 'Vehicle is fully available for the requested period',
                'requires_approval' => false,
                'recommended' => true,
            ];
        } else {
            // Check if all conflicts can be handled with concurrent assignments
            if ($vehicle->allowsConcurrentAssignments()) {
                $options[] = [
                    'type' => 'concurrent',
                    'title' => 'Concurrent Assignment',
                    'description' => 'Share vehicle with existing bookings (requires approval)',
                    'requires_approval' => true,
                    'recommended' => true,
                    'conflict_details' => $conflicts,
                ];
            }

            // Override option (always available)
            $options[] = [
                'type' => 'override',
                'title' => 'Override Assignment',
                'description' => 'Override existing assignments (requires approval)',
                'requires_approval' => true,
                'recommended' => false,
                'conflict_details' => $conflicts,
            ];

            // Alternative vehicles option
            $options[] = [
                'type' => 'alternative',
                'title' => 'Find Alternative Vehicle',
                'description' => 'Search for alternative vehicles in the same group',
                'requires_approval' => false,
                'recommended' => true,
            ];
        }

        return $options;
    }

    /**
     * Determine vehicle availability status considering assignments
     */
    private function determineVehicleAvailabilityStatus(Vehicle $vehicle, array $conflicts, Carbon $fromDate, Carbon $toDate): string
    {
        if (empty($conflicts)) {
            return 'available';
        }

        // Check if all conflicts allow concurrent usage
        if ($vehicle->allowsConcurrentAssignments()) {
            $allConcurrent = collect($conflicts)->every(function($conflict) {
                return $conflict['can_be_concurrent'] ?? false;
            });

            if ($allConcurrent) {
                return 'available_concurrent';
            }
        }

        // Check conflict types
        $hasActiveConflicts = collect($conflicts)->contains('status', 'active');
        $hasPendingConflicts = collect($conflicts)->contains('status', 'pending_approval');

        if ($hasActiveConflicts) {
            return 'assigned';
        }

        if ($hasPendingConflicts) {
            return 'pending_assignment';
        }

        return 'conflicted';
    }

    /**
     * Determine driver availability status considering assignments
     */
    private function determineDriverAvailabilityStatus(Driver $driver, array $conflicts, Carbon $fromDate, Carbon $toDate): string
    {
        if (empty($conflicts)) {
            return 'available';
        }

        $hasActiveConflicts = collect($conflicts)->contains('status', 'active');
        $hasPendingConflicts = collect($conflicts)->contains('status', 'pending_approval');

        if ($hasActiveConflicts) {
            return 'assigned';
        }

        if ($hasPendingConflicts) {
            return 'pending_assignment';
        }

        return 'conflicted';
    }

    /**
     * Get vehicle assignment recommendations
     */
    private function getVehicleAssignmentRecommendations(Vehicle $vehicle, array $conflicts, Carbon $fromDate, Carbon $toDate): array
    {
        $recommendations = [];

        if (empty($conflicts)) {
            return [[
                'type' => 'direct',
                'message' => 'Vehicle is available for direct assignment',
                'priority' => 'high'
            ]];
        }

        if ($vehicle->allowsConcurrentAssignments()) {
            $recommendations[] = [
                'type' => 'concurrent',
                'message' => 'Consider concurrent assignment - vehicle supports multiple bookings',
                'priority' => 'medium'
            ];
        }

        // Check for partial availability windows
        $availableWindows = $this->findAvailableTimeWindows($conflicts, $fromDate, $toDate);
        if (!empty($availableWindows)) {
            $recommendations[] = [
                'type' => 'partial',
                'message' => 'Vehicle has partial availability windows',
                'priority' => 'low',
                'available_windows' => $availableWindows
            ];
        }

        return $recommendations;
    }

    /**
     * Get driver assignment recommendations
     */
    private function getDriverAssignmentRecommendations(Driver $driver, array $conflicts, Carbon $fromDate, Carbon $toDate): array
    {
        $recommendations = [];

        if (empty($conflicts)) {
            return [[
                'type' => 'direct',
                'message' => 'Driver is available for direct assignment',
                'priority' => 'high'
            ]];
        }

        // Drivers typically don't support concurrent assignments
        $recommendations[] = [
            'type' => 'override',
            'message' => 'Driver has conflicts - consider alternative driver or reschedule',
            'priority' => 'low'
        ];

        return $recommendations;
    }

    /**
     * Find available time windows between conflicts
     */
    private function findAvailableTimeWindows(array $conflicts, Carbon $fromDate, Carbon $toDate): array
    {
        if (empty($conflicts)) {
            return [];
        }

        $windows = [];
        $sortedConflicts = collect($conflicts)->sortBy('from_datetime');
        $currentTime = $fromDate->copy();

        foreach ($sortedConflicts as $conflict) {
            $conflictStart = Carbon::parse($conflict['from_datetime']);
            
            if ($currentTime < $conflictStart) {
                $windows[] = [
                    'start' => $currentTime->toISOString(),
                    'end' => $conflictStart->toISOString(),
                    'duration_hours' => $currentTime->diffInHours($conflictStart),
                ];
            }
            
            $currentTime = Carbon::parse($conflict['to_datetime']);
        }

        // Check for window after last conflict
        if ($currentTime < $toDate) {
            $windows[] = [
                'start' => $currentTime->toISOString(),
                'end' => $toDate->toISOString(),
                'duration_hours' => $currentTime->diffInHours($toDate),
            ];
        }

        return $windows;
    }

    /**
     * Approve assignment (used in booking approval workflow)
     */
    public function approveAssignments(string $bookingId, string $approverId): array
    {
        return DB::transaction(function () use ($bookingId, $approverId) {
            $vehicleAssignment = VehicleAssignment::where('booking_id', $bookingId)->first();
            $driverAssignment = DriverAssignment::where('booking_id', $bookingId)->first();

            $results = [];

            if ($vehicleAssignment && $vehicleAssignment->requires_approval) {
                $vehicleAssignment->update([
                    'status' => 'active',
                    'approved_by' => $approverId,
                    'approved_at' => now(),
                ]);
                $results['vehicle_assignment'] = $vehicleAssignment;
            }

            if ($driverAssignment && $driverAssignment->requires_approval) {
                $driverAssignment->update([
                    'status' => 'active',
                    'approved_by' => $approverId,
                    'approved_at' => now(),
                ]);
                $results['driver_assignment'] = $driverAssignment;
            }

            return $results;
        });
    }

    /**
     * Get available alternatives when conflicts exist
     */
    public function getAlternativeAssignments(string $vehicleGroupId, Carbon $fromDate, Carbon $toDate, string $serviceType, ?string $excludeBookingId = null): array
    {
        // Get alternative vehicles in the same group
        $alternativeVehicles = Vehicle::where('vehicle_group_id', $vehicleGroupId)
            ->where('status', 'active')
            ->where('id', '!=', $excludeBookingId ? 
                optional(Booking::find($excludeBookingId))->vehicle_id : 'none')
            ->with(['defaultDriver.user'])
            ->get()
            ->filter(function($vehicle) use ($fromDate, $toDate, $excludeBookingId) {
                return $vehicle->isAvailableForPeriod($fromDate, $toDate, $excludeBookingId) ||
                       $vehicle->allowsConcurrentAssignments();
            })
            ->take(5) // Limit alternatives
            ->map(function($vehicle) use ($fromDate, $toDate, $excludeBookingId) {
                $conflicts = $vehicle->getAssignmentConflicts($fromDate, $toDate, $excludeBookingId);
                return [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_name' => $vehicle->title,
                    'license_plate' => $vehicle->license_plate,
                    'availability_status' => $this->determineVehicleAvailabilityStatus($vehicle, $conflicts, $fromDate, $toDate),
                    'recommended_driver' => $vehicle->defaultDriver ? [
                        'id' => $vehicle->defaultDriver->id,
                        'name' => $vehicle->defaultDriver->user ? 
                            $vehicle->defaultDriver->user->first_name . ' ' . $vehicle->defaultDriver->user->last_name 
                            : 'Unknown Driver',
                        'license_number' => $vehicle->defaultDriver->license_no,
                    ] : null,
                    'allows_concurrent' => $vehicle->allowsConcurrentAssignments(),
                    'force_default_driver' => $vehicle->hasForceDefaultDriver(),
                ];
            })
            ->toArray();

        return $alternativeVehicles;
    }

    /**
     * Handle booking confirmation with assignments
     */
    public function confirmBookingAssignments(string $bookingId): array
    {
        return DB::transaction(function () use ($bookingId) {
            $booking = Booking::findOrFail($bookingId);
            
            $assignments = [];
            $errors = [];

            // Confirm vehicle assignment
            if ($booking->vehicle_id) {
                try {
                    $vehicleAssignment = VehicleAssignment::where('booking_id', $bookingId)->first();
                    if ($vehicleAssignment) {
                        $vehicleAssignment->update([
                            'status' => 'active',
                            'confirmed_by' => Auth::id(),
                            'confirmed_at' => now(),
                            'manually_confirmed' => true,
                        ]);
                        $assignments['vehicle'] = $vehicleAssignment;
                    }
                } catch (\Exception $e) {
                    $errors['vehicle'] = 'Failed to confirm vehicle assignment: ' . $e->getMessage();
                }
            }

            // Confirm driver assignment
            if ($booking->driver_id) {
                try {
                    $driverAssignment = DriverAssignment::where('booking_id', $bookingId)->first();
                    if ($driverAssignment) {
                        $driverAssignment->update([
                            'status' => 'active',
                            'confirmed_by' => Auth::id(),
                            'confirmed_at' => now(),
                            'manually_confirmed' => true,
                        ]);
                        $assignments['driver'] = $driverAssignment;
                    }
                } catch (\Exception $e) {
                    $errors['driver'] = 'Failed to confirm driver assignment: ' . $e->getMessage();
                }
            }

            return [
                'assignments' => $assignments,
                'errors' => $errors,
                'success' => empty($errors),
            ];
        });
    }
}
