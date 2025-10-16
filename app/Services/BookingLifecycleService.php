<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingQC;
use App\Models\Vehicle\Vehicle;
use App\Models\User;
use App\Enums\BookingLifecycleStatus;
use App\Enums\DispatchStatus;
use App\Enums\QCStatus;
use App\Enums\VehicleAvailabilityStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

/**
 * Booking Lifecycle Service
 * 
 * Manages the complete E2E booking lifecycle from inquiry to completion
 * Following the business operation flow chart
 */
class BookingLifecycleService
{
    protected AssignmentService $assignmentService;
    protected BookingFlowService $bookingFlowService;
    protected CurrencyService $currencyService;

    public function __construct(
        AssignmentService $assignmentService,
        BookingFlowService $bookingFlowService,
        CurrencyService $currencyService
    ) {
        $this->assignmentService = $assignmentService;
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
    }

    // ========================
    // INQUIRY STAGE
    // ========================

    /**
     * Create new inquiry
     */
    public function createInquiry(array $data): Booking
    {
        return DB::transaction(function () use ($data) {
            $booking = Booking::create([
                'customer_id' => $data['customer_id'],
                'service_type_id' => $data['service_type_id'],
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'from_time' => $data['from_time'] ?? null,
                'to_time' => $data['to_time'] ?? null,
                'pickup_location' => $data['pickup_location'] ?? null,
                'dropoff_location' => $data['dropoff_location'] ?? null,
                'special_requirements' => $data['special_requirements'] ?? null,
                'status' => 'inquiry',
                'created_from' => $data['created_from'] ?? 'system',
                'created_user_id' => Auth::id(),
            ]);

            $this->logLifecycleTransition($booking, null, BookingLifecycleStatus::INQUIRY, [
                'created_from' => $data['created_from'] ?? 'system'
            ]);

            return $booking;
        });
    }

    /**
     * Qualify inquiry
     */
    public function qualifyInquiry(string $bookingId, array $qualificationData): Booking
    {
        return DB::transaction(function () use ($bookingId, $qualificationData) {
            $booking = Booking::findOrFail($bookingId);
            
            if (!$booking->getLifecycleStatus()->canTransitionTo(BookingLifecycleStatus::INQUIRY_QUALIFIED)) {
                throw new \Exception('Cannot qualify inquiry from current status');
            }

            $booking->update([
                'vehicle_group_id' => $qualificationData['vehicle_group_id'] ?? null,
                'is_self_driven' => $qualificationData['is_self_driven'] ?? false,
                'passenger_count' => $qualificationData['passenger_count'] ?? null,
                'workflow_data' => array_merge($booking->workflow_data ?? [], [
                    'qualification_notes' => $qualificationData['notes'] ?? null,
                    'qualified_by' => Auth::id(),
                    'qualified_at' => now(),
                ]),
            ]);

            $this->logLifecycleTransition($booking, BookingLifecycleStatus::INQUIRY, BookingLifecycleStatus::INQUIRY_QUALIFIED, $qualificationData);

            return $booking;
        });
    }

    // ========================
    // BOOKING STAGE
    // ========================

    /**
     * Convert inquiry to booking
     */
    public function convertToBooking(string $bookingId, array $bookingData): Booking
    {
        return DB::transaction(function () use ($bookingId, $bookingData) {
            $booking = Booking::findOrFail($bookingId);
            
            if (!$booking->getLifecycleStatus()->canTransitionTo(BookingLifecycleStatus::BOOKING_PENDING)) {
                throw new \Exception('Cannot convert to booking from current status');
            }

            // Update booking with full details
            $booking->update([
                'status' => 'pending',
                'vehicle_group_id' => $bookingData['vehicle_group_id'],
                'vehicle_id' => $bookingData['vehicle_id'] ?? null,
                'driver_id' => $bookingData['driver_id'] ?? null,
                'is_self_driven' => $bookingData['is_self_driven'],
                'base_amount' => $bookingData['base_amount'] ?? 0,
                'total_estimated' => $bookingData['total_estimated'] ?? 0,
                'requires_approval' => $bookingData['requires_approval'] ?? false,
                'workflow_data' => array_merge($booking->workflow_data ?? [], [
                    'converted_from_inquiry' => true,
                    'converted_by' => Auth::id(),
                    'converted_at' => now(),
                ]),
            ]);

            $this->logLifecycleTransition($booking, BookingLifecycleStatus::INQUIRY_QUALIFIED, BookingLifecycleStatus::BOOKING_PENDING, $bookingData);

            return $booking;
        });
    }

    /**
     * Confirm booking
     */
    public function confirmBooking(string $bookingId, array $confirmationData = []): Booking
    {
        return DB::transaction(function () use ($bookingId, $confirmationData) {
            $booking = Booking::findOrFail($bookingId);
            
            if ($booking->requires_approval && $booking->approval_status !== 'approved') {
                throw new \Exception('Booking requires approval before confirmation');
            }

            $booking->transitionToStatus(BookingLifecycleStatus::BOOKING_CONFIRMED, Auth::id(), $confirmationData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::BOOKING_PENDING, BookingLifecycleStatus::BOOKING_CONFIRMED, $confirmationData);

            return $booking;
        });
    }

    // ========================
    // ALLOCATION & DISPATCH STAGE
    // ========================

    /**
     * Start allocation process
     */
    public function startAllocation(string $bookingId): array
    {
        $booking = Booking::findOrFail($bookingId);
        
        if (!$booking->isInStage('allocation_dispatch')) {
            throw new \Exception('Booking is not ready for allocation');
        }

        // Get available vehicles and drivers
        $availabilityData = [
            'service_type' => $booking->service_type_id,
            'from_date' => $booking->from_date->toDateString(),
            'to_date' => $booking->to_date->toDateString(),
            'from_time' => $booking->from_time,
            'to_time' => $booking->to_time,
            'vehicle_group_id' => $booking->vehicle_group_id,
            'exclude_booking_id' => $booking->id,
        ];

        $vehicleGroups = $this->bookingFlowService->getAvailableVehicleGroups($availabilityData);
        $drivers = $this->bookingFlowService->getAvailableDrivers($availabilityData);

        return [
            'booking' => $booking,
            'vehicle_groups' => $vehicleGroups,
            'drivers' => $drivers,
            'requires_approval' => $this->checkAllocationRequiresApproval($booking),
        ];
    }

    /**
     * Assign vehicle and driver
     */
    public function assignVehicleAndDriver(string $bookingId, array $assignmentData): Booking
    {
        return DB::transaction(function () use ($bookingId, $assignmentData) {
            $booking = Booking::findOrFail($bookingId);
            
            // Assign vehicle and driver
            $booking->update([
                'vehicle_id' => $assignmentData['vehicle_id'],
                'driver_id' => $assignmentData['driver_id'] ?? null,
            ]);

            // Create assignments
            if (isset($assignmentData['vehicle_id'])) {
                $this->assignmentService->createVehicleAssignment([
                    'booking_id' => $booking->id,
                    'vehicle_id' => $assignmentData['vehicle_id'],
                    'assigned_from' => $booking->from_date,
                    'assigned_to' => $booking->to_date,
                    'assignment_type' => $assignmentData['assignment_type'] ?? 'primary',
                    'requires_approval' => $assignmentData['requires_approval'] ?? false,
                ]);
            }

            if (isset($assignmentData['driver_id'])) {
                $this->assignmentService->createDriverAssignment([
                    'booking_id' => $booking->id,
                    'driver_id' => $assignmentData['driver_id'],
                    'assigned_from' => $booking->from_date,
                    'assigned_to' => $booking->to_date,
                    'assignment_type' => $assignmentData['assignment_type'] ?? 'primary',
                ]);
            }

            $booking->transitionToStatus(BookingLifecycleStatus::ALLOCATION_ASSIGNED, Auth::id(), $assignmentData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::ALLOCATION_PENDING, BookingLifecycleStatus::ALLOCATION_ASSIGNED, $assignmentData);

            return $booking;
        });
    }

    /**
     * Prepare for dispatch
     */
    public function prepareForDispatch(string $bookingId, array $dispatchData = []): BookingDispatch
    {
        return DB::transaction(function () use ($bookingId, $dispatchData) {
            $booking = Booking::findOrFail($bookingId);
            
            if (!$booking->vehicle_id) {
                throw new \Exception('Vehicle must be assigned before dispatch preparation');
            }

            // Create dispatch record
            $dispatch = $booking->dispatch()->create([
                'vehicle_id' => $booking->vehicle_id,
                'driver_id' => $booking->driver_id,
                'dispatch_status' => DispatchStatus::READY_FOR_DISPATCH,
                'expected_return_at' => $booking->to_date,
                'is_self_driven' => $booking->is_self_driven,
                'dispatch_notes' => $dispatchData['notes'] ?? null,
                'created_user_id' => Auth::id(),
            ]);

            $booking->transitionToStatus(BookingLifecycleStatus::DISPATCH_READY, Auth::id(), $dispatchData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::ALLOCATION_APPROVED, BookingLifecycleStatus::DISPATCH_READY, $dispatchData);

            return $dispatch;
        });
    }

    /**
     * Dispatch vehicle
     */
    public function dispatchVehicle(string $bookingId, array $dispatchData): BookingDispatch
    {
        return DB::transaction(function () use ($bookingId, $dispatchData) {
            $booking = Booking::findOrFail($bookingId);
            $dispatch = $booking->dispatch;
            
            if (!$dispatch) {
                throw new \Exception('Dispatch record not found');
            }

            // Mark vehicle as dispatched
            $dispatch->markDispatched(Auth::id(), $dispatchData);

            // Update vehicle availability
            $vehicle = Vehicle::findOrFail($booking->vehicle_id);
            $vehicle->update(['availability_status' => VehicleAvailabilityStatus::ON_HIRE->value]);

            $booking->transitionToStatus(BookingLifecycleStatus::DISPATCH_OUT, Auth::id(), $dispatchData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::DISPATCH_READY, BookingLifecycleStatus::DISPATCH_OUT, $dispatchData);

            return $dispatch;
        });
    }

    // ========================
    // ONGOING STAGE
    // ========================

    /**
     * Get ongoing booking status
     */
    public function getOngoingStatus(string $bookingId): array
    {
        $booking = Booking::with(['dispatch', 'vehicle', 'driver'])->findOrFail($bookingId);
        
        if (!$booking->isInStage('ongoing')) {
            throw new \Exception('Booking is not in ongoing stage');
        }

        $dispatch = $booking->dispatch;
        $isOverdue = $dispatch && $dispatch->isOverdue();

        return [
            'booking' => $booking,
            'dispatch' => $dispatch,
            'is_overdue' => $isOverdue,
            'hours_active' => $dispatch ? $dispatch->dispatched_at?->diffInHours(now()) : 0,
            'expected_return' => $dispatch?->expected_return_at,
            'late_fee' => $isOverdue ? $dispatch->calculateLateReturnFee() : 0,
            'can_schedule_return' => true,
            'can_request_replacement' => true,
        ];
    }

    /**
     * Process replacement request
     */
    public function processReplacement(string $bookingId, array $replacementData): array
    {
        return DB::transaction(function () use ($bookingId, $replacementData) {
            $booking = Booking::findOrFail($bookingId);
            
            // This would integrate with the existing swap functionality
            // from AssignmentController
            
            $booking->transitionToStatus(BookingLifecycleStatus::ONGOING_REPLACEMENT_NEEDED, Auth::id(), $replacementData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::ONGOING_ACTIVE, BookingLifecycleStatus::ONGOING_REPLACEMENT_NEEDED, $replacementData);

            return [
                'booking' => $booking,
                'replacement_requested' => true,
                'next_actions' => $booking->getNextActions(),
            ];
        });
    }

    // ========================
    // RETURN STAGE
    // ========================

    /**
     * Schedule return
     */
    public function scheduleReturn(string $bookingId, array $returnData): Booking
    {
        return DB::transaction(function () use ($bookingId, $returnData) {
            $booking = Booking::findOrFail($bookingId);
            
            if ($booking->dispatch) {
                $booking->dispatch->update([
                    'expected_return_at' => $returnData['expected_return_at'] ?? $booking->to_date,
                    'return_notes' => $returnData['notes'] ?? null,
                ]);
            }

            $booking->transitionToStatus(BookingLifecycleStatus::RETURN_SCHEDULED, Auth::id(), $returnData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::ONGOING_ACTIVE, BookingLifecycleStatus::RETURN_SCHEDULED, $returnData);

            return $booking;
        });
    }

    /**
     * Process return
     */
    public function processReturn(string $bookingId, array $returnData): BookingDispatch
    {
        return DB::transaction(function () use ($bookingId, $returnData) {
            $booking = Booking::findOrFail($bookingId);
            $dispatch = $booking->dispatch;
            
            if (!$dispatch) {
                throw new \Exception('Dispatch record not found');
            }

            // Mark as returned
            $dispatch->markReturned(Auth::id(), $returnData);

            // Update vehicle availability (pending QC)
            $vehicle = Vehicle::findOrFail($booking->vehicle_id);
            $vehicle->update(['availability_status' => VehicleAvailabilityStatus::UNAVAILABLE_QC->value]);

            $lifecycleStatus = $dispatch->isOverdue() 
                ? BookingLifecycleStatus::RETURN_LATE 
                : BookingLifecycleStatus::RETURN_COMPLETED;

            $booking->transitionToStatus($lifecycleStatus, Auth::id(), $returnData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::RETURN_SCHEDULED, $lifecycleStatus, $returnData);

            return $dispatch;
        });
    }

    // ========================
    // QC & REPAIR STAGE
    // ========================

    /**
     * Start QC inspection
     */
    public function startQCInspection(string $bookingId, string $inspectorId): BookingQC
    {
        return DB::transaction(function () use ($bookingId, $inspectorId) {
            $booking = Booking::findOrFail($bookingId);
            
            // Create or get QC record
            $qc = $booking->qc ?: $booking->qc()->create([
                'vehicle_id' => $booking->vehicle_id,
                'dispatch_id' => $booking->dispatch?->id,
                'qc_status' => QCStatus::PENDING,
            ]);

            $qc->startInspection($inspectorId);

            $booking->transitionToStatus(BookingLifecycleStatus::QC_IN_PROGRESS, Auth::id());
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_PENDING, BookingLifecycleStatus::QC_IN_PROGRESS);

            return $qc;
        });
    }

    /**
     * Complete QC inspection
     */
    public function completeQCInspection(string $bookingId, array $inspectionData): BookingQC
    {
        return DB::transaction(function () use ($bookingId, $inspectionData) {
            $booking = Booking::findOrFail($bookingId);
            $qc = $booking->qc;
            
            if (!$qc) {
                throw new \Exception('QC record not found');
            }

            $qc->completeInspection($inspectionData);

            // Determine next status based on inspection results
            $nextStatus = $qc->needsRepair() 
                ? BookingLifecycleStatus::QC_REPAIR_NEEDED 
                : BookingLifecycleStatus::QC_COMPLETED;

            $booking->transitionToStatus($nextStatus, Auth::id(), $inspectionData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_IN_PROGRESS, $nextStatus, $inspectionData);

            // If no repair needed, make vehicle available
            if (!$qc->needsRepair()) {
                $this->makeVehicleAvailable($booking->vehicle_id);
            } else {
                // Update vehicle to repair status
                $vehicle = Vehicle::findOrFail($booking->vehicle_id);
                $vehicle->update(['availability_status' => VehicleAvailabilityStatus::UNAVAILABLE_REPAIR->value]);
            }

            return $qc;
        });
    }

    /**
     * Complete repairs and finish QC
     */
    public function completeRepairs(string $bookingId, array $repairData = []): BookingQC
    {
        return DB::transaction(function () use ($bookingId, $repairData) {
            $booking = Booking::findOrFail($bookingId);
            $qc = $booking->qc;
            
            if (!$qc) {
                throw new \Exception('QC record not found');
            }

            $qc->markCompleted();

            $booking->transitionToStatus(BookingLifecycleStatus::QC_COMPLETED, Auth::id(), $repairData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_REPAIR_NEEDED, BookingLifecycleStatus::QC_COMPLETED, $repairData);

            // Make vehicle available
            $this->makeVehicleAvailable($booking->vehicle_id);

            return $qc;
        });
    }

    // ========================
    // COMPLETION STAGE
    // ========================

    /**
     * Complete booking lifecycle
     */
    public function completeBooking(string $bookingId, array $completionData = []): Booking
    {
        return DB::transaction(function () use ($bookingId, $completionData) {
            $booking = Booking::findOrFail($bookingId);
            
            $booking->transitionToStatus(BookingLifecycleStatus::COMPLETED, Auth::id(), $completionData);
            
            $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_COMPLETED, BookingLifecycleStatus::COMPLETED, $completionData);

            return $booking;
        });
    }

    // ========================
    // HELPER METHODS
    // ========================

    /**
     * Make vehicle available after QC completion
     */
    private function makeVehicleAvailable(string $vehicleId): void
    {
        $vehicle = Vehicle::findOrFail($vehicleId);
        $vehicle->update(['availability_status' => VehicleAvailabilityStatus::AVAILABLE->value]);
    }

    /**
     * Check if allocation requires approval
     */
    private function checkAllocationRequiresApproval(Booking $booking): bool
    {
        // Add logic to determine if allocation requires approval
        // Based on conflicts, overrides, etc.
        return false;
    }

    /**
     * Log lifecycle transition
     */
    private function logLifecycleTransition(
        Booking $booking, 
        ?BookingLifecycleStatus $fromStatus, 
        BookingLifecycleStatus $toStatus, 
        array $data = []
    ): void {
        Log::info('Booking lifecycle transition', [
            'booking_id' => $booking->id,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'user_id' => Auth::id(),
            'data' => $data,
        ]);

        // Could also create a dedicated lifecycle log table
    }

    /**
     * Get booking lifecycle summary
     */
    public function getLifecycleSummary(string $bookingId): array
    {
        $booking = Booking::with(['dispatch', 'qc', 'customer', 'vehicle', 'driver'])->findOrFail($bookingId);
        
        $currentStatus = $booking->getLifecycleStatus();
        $nextActions = $booking->getNextActions();

        return [
            'booking' => $booking,
            'current_status' => [
                'value' => $currentStatus->value,
                'stage' => $currentStatus->getStage(),
                'display_name' => $currentStatus->getDisplayName(),
                'label' => $currentStatus->getDisplayName(),
                'color' => $currentStatus->getColor(),
                'icon' => $this->getStatusIcon($currentStatus),
                'primaryAction' => $this->getPrimaryActionText($currentStatus),
            ],
            'next_actions' => $nextActions,
            'stage_progress' => $this->getStageProgress($booking),
            'timeline' => $this->getLifecycleTimeline($booking),
        ];
    }

    /**
     * Get stage progress for UI
     */
    private function getStageProgress(Booking $booking): array
    {
        $currentStatus = $booking->getLifecycleStatus();
        $stages = [
            'inquiry' => ['completed' => false, 'current' => false],
            'booking' => ['completed' => false, 'current' => false],
            'allocation_dispatch' => ['completed' => false, 'current' => false],
            'ongoing' => ['completed' => false, 'current' => false],
            'return' => ['completed' => false, 'current' => false],
            'qc_repair' => ['completed' => false, 'current' => false],
            'final' => ['completed' => false, 'current' => false],
        ];

        $currentStage = $currentStatus->getStage();
        $stageOrder = array_keys($stages);
        $currentIndex = array_search($currentStage, $stageOrder);

        // Mark completed stages
        for ($i = 0; $i < $currentIndex; $i++) {
            $stages[$stageOrder[$i]]['completed'] = true;
        }

        // Mark current stage
        if ($currentIndex !== false) {
            $stages[$currentStage]['current'] = true;
        }

        return $stages;
    }

    /**
     * Get lifecycle timeline events
     */
    private function getLifecycleTimeline(Booking $booking): array
    {
        $timeline = [];

        // Booking created
        $timeline[] = [
            'event' => 'Booking Created',
            'timestamp' => $booking->created_at,
            'user' => $booking->createdBy?->name ?? 'System',
            'status' => 'inquiry',
        ];

        // Booking confirmed
        if ($booking->confirmed_at) {
            $timeline[] = [
                'event' => 'Booking Confirmed',
                'timestamp' => $booking->confirmed_at,
                'user' => $booking->updatedBy?->name ?? 'System',
                'status' => 'confirmed',
            ];
        }

        // Vehicle dispatched
        if ($booking->dispatch?->dispatched_at) {
            $timeline[] = [
                'event' => 'Vehicle Dispatched',
                'timestamp' => $booking->dispatch->dispatched_at,
                'user' => $booking->dispatch->dispatchedBy?->name ?? 'System',
                'status' => 'dispatched',
            ];
        }

        // Vehicle returned
        if ($booking->dispatch?->actual_return_at) {
            $timeline[] = [
                'event' => 'Vehicle Returned',
                'timestamp' => $booking->dispatch->actual_return_at,
                'user' => $booking->dispatch->returnedBy?->name ?? 'System',
                'status' => 'returned',
            ];
        }

        // QC completed
        if ($booking->qc?->inspection_completed_at) {
            $timeline[] = [
                'event' => 'QC Inspection Completed',
                'timestamp' => $booking->qc->inspection_completed_at,
                'user' => $booking->qc->inspector?->name ?? 'System',
                'status' => 'qc_completed',
            ];
        }

        // Booking completed
        if ($booking->completed_at) {
            $timeline[] = [
                'event' => 'Booking Completed',
                'timestamp' => $booking->completed_at,
                'user' => $booking->updatedBy?->name ?? 'System',
                'status' => 'completed',
            ];
        }

        return array_reverse($timeline); // Most recent first
    }

    /**
     * Get available inspectors for QC
     */
    public function getAvailableInspectors(): array
    {
        // Get users with QC inspector role or permission
        $inspectors = User::query()
            ->where('status', 'active')
            ->whereHas('roles', function ($query) {
                $query->where('name', 'qc_inspector')
                    ->orWhere('name', 'admin')
                    ->orWhere('name', 'operations_manager');
            })
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->get();

        return $inspectors->toArray();
    }

    /**
     * Get ongoing details for a booking
     */
    public function getOngoingDetails(string $bookingId): array
    {
        $booking = Booking::with([
            'dispatch',
            'assignments.vehicle',
            'assignments.driver',
            'customer'
        ])->findOrFail($bookingId);

        $dispatch = $booking->dispatch;
        
        if (!$dispatch) {
            throw new \Exception('No dispatch found for this booking');
        }

        return [
            'booking_id' => $booking->id,
            'customer_name' => $booking->customer->full_name,
            'vehicle' => $booking->assignments->first()?->vehicle,
            'driver' => $booking->assignments->first()?->driver,
            'dispatch_details' => [
                'dispatched_at' => $dispatch->dispatched_at,
                'handover_time' => $dispatch->handover_time,
                'handover_location' => $dispatch->handover_location,
                'dispatch_notes' => $dispatch->dispatched_notes,
            ],
            'expected_return' => $booking->to_date . ' ' . $booking->to_time,
            'contact_info' => [
                'customer_phone' => $booking->customer->phone,
                'driver_phone' => $booking->assignments->first()?->driver->phone,
            ],
            'status' => $booking->lifecycle_status->value,
            'duration_hours' => now()->diffInHours($dispatch->dispatched_at),
        ];
    }

    /**
     * Get dispatch details for a booking
     */
    public function getDispatchDetails(string $bookingId): array
    {
        $booking = Booking::with([
            'dispatch',
            'assignments.vehicle',
            'assignments.driver'
        ])->findOrFail($bookingId);

        $dispatch = $booking->dispatch;
        
        if (!$dispatch) {
            throw new \Exception('No dispatch found for this booking');
        }

        return [
            'id' => $dispatch->id,
            'booking_id' => $booking->id,
            'status' => $dispatch->status->value,
            'vehicle' => $booking->assignments->first()?->vehicle,
            'driver' => $booking->assignments->first()?->driver,
            'dispatch_details' => [
                'dispatched_at' => $dispatch->dispatched_at,
                'handover_time' => $dispatch->handover_time,
                'handover_location' => $dispatch->handover_location,
                'dispatch_notes' => $dispatch->dispatched_notes,
                'vehicle_condition_notes' => $dispatch->vehicle_condition_notes,
                'dispatch_fuel_level' => $dispatch->dispatch_fuel_level,
                'dispatch_mileage' => $dispatch->dispatch_mileage,
            ],
            'return_details' => [
                'returned_at' => $dispatch->returned_at,
                'return_condition_notes' => $dispatch->return_condition_notes,
                'return_fuel_level' => $dispatch->return_fuel_level,
                'return_mileage' => $dispatch->return_mileage,
                'return_notes' => $dispatch->return_notes,
                'damages' => $dispatch->damages,
                'additional_charges' => $dispatch->additional_charges,
                'late_return_fee' => $dispatch->late_return_fee,
            ],
            'created_at' => $dispatch->created_at,
            'updated_at' => $dispatch->updated_at,
        ];
    }

    /**
     * Get QC details for a booking
     */
    public function getQCDetails(string $bookingId): array
    {
        $booking = Booking::with(['qc.repairItems'])->findOrFail($bookingId);

        $qc = $booking->qc;
        
        if (!$qc) {
            throw new \Exception('No QC record found for this booking');
        }

        return [
            'id' => $qc->id,
            'booking_id' => $booking->id,
            'status' => $qc->status->value,
            'inspector' => $qc->inspector ? [
                'id' => $qc->inspector->id,
                'name' => $qc->inspector->name,
                'email' => $qc->inspector->email,
            ] : null,
            'inspection_details' => [
                'started_at' => $qc->started_at,
                'completed_at' => $qc->completed_at,
                'cleanliness_rating' => $qc->cleanliness_rating,
                'fuel_level' => $qc->fuel_level,
                'mileage' => $qc->mileage,
                'interior_condition' => $qc->interior_condition,
                'exterior_condition' => $qc->exterior_condition,
                'mechanical_condition' => $qc->mechanical_condition,
                'qc_notes' => $qc->qc_notes,
            ],
            'repair_info' => [
                'repair_required' => $qc->repair_required,
                'estimated_repair_cost' => $qc->estimated_repair_cost,
                'repair_notes' => $qc->repair_notes,
                'repair_completed_at' => $qc->repair_completed_at,
                'repair_items' => $qc->repairItems->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'item_type' => $item->item_type,
                        'description' => $item->description,
                        'cost' => $item->cost,
                        'status' => $item->status->value,
                        'completed_at' => $item->completed_at,
                    ];
                })->toArray(),
            ],
            'maintenance_info' => [
                'requires_maintenance' => $qc->requires_maintenance,
                'next_maintenance_due' => $qc->next_maintenance_due,
            ],
            'created_at' => $qc->created_at,
            'updated_at' => $qc->updated_at,
        ];
    }

    /**
     * Get icon for booking status
     */
    private function getStatusIcon(BookingLifecycleStatus $status): string
    {
        return match ($status->getStage()) {
            'inquiry' => 'help_outline',
            'booking' => 'event_note',
            'allocation_dispatch' => 'assignment',
            'ongoing' => 'drive_eta',
            'return' => 'assignment_return',
            'qc_repair' => 'build',
            'final' => 'check_circle',
            default => 'radio_button_unchecked',
        };
    }

    /**
     * Get primary action text for current status
     */
    private function getPrimaryActionText(BookingLifecycleStatus $status): string
    {
        return match ($status) {
            BookingLifecycleStatus::INQUIRY => 'Qualify Inquiry',
            BookingLifecycleStatus::INQUIRY_QUALIFIED => 'Convert to Booking',
            BookingLifecycleStatus::BOOKING_PENDING => 'Confirm Booking',
            BookingLifecycleStatus::BOOKING_CONFIRMED => 'Start Allocation',
            BookingLifecycleStatus::ALLOCATION_PENDING => 'Assign Vehicle & Driver',
            BookingLifecycleStatus::ALLOCATION_ASSIGNED => 'Approve Assignment',
            BookingLifecycleStatus::ALLOCATION_APPROVED => 'Prepare for Dispatch',
            BookingLifecycleStatus::DISPATCH_READY => 'Dispatch Vehicle',
            BookingLifecycleStatus::DISPATCH_OUT => 'Monitor Rental',
            BookingLifecycleStatus::ONGOING_ACTIVE => 'Process Return',
            BookingLifecycleStatus::RETURN_SCHEDULED => 'Process Return',
            BookingLifecycleStatus::RETURN_COMPLETED => 'Start QC Inspection',
            BookingLifecycleStatus::RETURN_LATE => 'Start QC Inspection',
            BookingLifecycleStatus::QC_PENDING => 'Start QC Inspection',
            BookingLifecycleStatus::QC_IN_PROGRESS => 'Complete QC Inspection',
            BookingLifecycleStatus::QC_ISSUES_FOUND => 'Address Issues',
            BookingLifecycleStatus::QC_REPAIR_NEEDED => 'Complete Repairs',
            BookingLifecycleStatus::QC_COMPLETED => 'Complete Booking',
            BookingLifecycleStatus::COMPLETED => 'View Details',
            default => 'Continue',
        };
    }

    // ========================
    // AVAILABILITY & MAINTENANCE INTEGRATION
    // ========================

    /**
     * Check vehicle availability with maintenance blocking
     */
    public function checkVehicleAvailability(string $vehicleId, string $startDate, string $endDate): array
    {
        $vehicle = Vehicle::findOrFail($vehicleId);
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Check maintenance schedule conflicts
        $maintenanceConflicts = DB::table('vehicle_maintenance_records')
            ->where('vehicle_id', $vehicleId)
            ->where('status', 'in_progress')
            ->whereBetween('performed_date', [$start, $end])
            ->orWhere(function($query) use ($start, $end) {
                $query->whereNull('completed_date')
                      ->where('performed_date', '<=', $end);
            })
            ->exists();

        // Check booking conflicts
        $bookingConflicts = DB::table('bookings')
            ->where('current_vehicle_id', $vehicleId)
            ->where('lifecycle_status', '!=', BookingLifecycleStatus::COMPLETED)
            ->where(function($query) use ($start, $end) {
                $query->whereBetween('from_date', [$start, $end])
                      ->orWhereBetween('to_date', [$start, $end])
                      ->orWhere(function($q) use ($start, $end) {
                          $q->where('from_date', '<=', $start)
                            ->where('to_date', '>=', $end);
                      });
            })
            ->exists();

        return [
            'vehicle_id' => $vehicleId,
            'available' => !$maintenanceConflicts && !$bookingConflicts,
            'conflicts' => [
                'maintenance' => $maintenanceConflicts,
                'bookings' => $bookingConflicts,
            ],
            'checked_period' => [
                'start' => $start->toISOString(),
                'end' => $end->toISOString(),
            ]
        ];
    }

    /**
     * Get vehicles currently blocked by maintenance
     */
    public function getMaintenanceBlocks(): array
    {
        $blocks = DB::table('vehicle_maintenance_records as vmr')
            ->join('vehicles as v', 'vmr.vehicle_id', '=', 'v.id')
            ->leftJoin('vehicle_maintenance_schedules as vms', 'vmr.schedule_id', '=', 'vms.id')
            ->where('vmr.status', 'in_progress')
            ->whereNull('vmr.completed_date')
            ->select([
                'vmr.id',
                'vmr.vehicle_id',
                'v.name as vehicle_name',
                'v.license_plate',
                'vms.type as maintenance_type',
                'vmr.performed_date as started_at',
                'vmr.notes',
                'vmr.estimated_completion_date'
            ])
            ->get()
            ->toArray();

        return $blocks;
    }

    /**
     * Update availability pool after QC completion
     */
    public function updateAvailabilityPool(string $bookingId): array
    {
        return DB::transaction(function () use ($bookingId) {
            $booking = Booking::findOrFail($bookingId);
            
            // Ensure booking is completed
            if ($booking->lifecycle_status !== BookingLifecycleStatus::COMPLETED) {
                throw new \InvalidArgumentException('Booking must be completed to update availability pool');
            }

            // Get QC results
            $qc = BookingQC::where('booking_id', $bookingId)->latest()->first();
            
            if (!$qc) {
                throw new \InvalidArgumentException('QC record not found');
            }

            // Update vehicle availability based on QC results
            $vehicle = Vehicle::findOrFail($booking->current_vehicle_id);
            
            if ($qc->repair_required) {
                // Vehicle needs repair - mark as unavailable
                $vehicle->update([
                    'availability_status' => VehicleAvailabilityStatus::UNAVAILABLE_REPAIR,
                    'unavailable_reason' => 'Post-rental repair required',
                    'estimated_available_date' => $qc->estimated_repair_completion,
                ]);
            } else {
                // Vehicle passed QC - mark as available
                $vehicle->update([
                    'availability_status' => VehicleAvailabilityStatus::AVAILABLE,
                    'unavailable_reason' => null,
                    'estimated_available_date' => null,
                    'last_qc_date' => $qc->completed_at,
                ]);
            }

            // Log availability update
            Log::info('Availability pool updated', [
                'booking_id' => $bookingId,
                'vehicle_id' => $vehicle->id,
                'new_status' => $vehicle->availability_status,
                'qc_passed' => !$qc->repair_required,
            ]);

            return [
                'vehicle_id' => $vehicle->id,
                'previous_status' => $vehicle->getOriginal('availability_status'),
                'new_status' => $vehicle->availability_status,
                'qc_passed' => !$qc->repair_required,
                'updated_at' => now()->toISOString(),
            ];
        });
    }
}
