<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingQC;
use App\Models\DriverAssignment;
use App\Models\Vehicle\Vehicle;
use App\Models\Website\WebsiteSetting;
use App\Services\Driver\NotificationTriggerService;
use App\Models\User;
use App\Enums\BookingLifecycleStatus;
use App\Enums\DispatchStatus;
use App\Enums\QCStatus;
use App\Enums\VehicleAvailabilityStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
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
    protected NotificationTriggerService $notificationTriggerService;

    public function __construct(
        AssignmentService $assignmentService,
        BookingFlowService $bookingFlowService,
        CurrencyService $currencyService,
        NotificationTriggerService $notificationTriggerService
    ) {
        $this->assignmentService = $assignmentService;
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
        $this->notificationTriggerService = $notificationTriggerService;
    }

    /**
     * Resolve selected booking item context for lifecycle/assignment operations.
     */
    private function resolveLifecycleContext(Booking $booking, ?string $bookingItemId = null): array
    {
        if (!$booking->relationLoaded('bookingItems')) {
            $booking->load([
                'bookingItems.serviceType',
                'bookingItems.vehicle',
                'bookingItems.driver.user',
            ]);
        }

        $bookingItems = $booking->bookingItems
            ->sortBy(function ($item) {
                return sprintf(
                    '%08d-%s',
                    (int) ($item->trip_number ?? 0),
                    (string) ($item->id ?? '')
                );
            })
            ->values();

        $selectedBookingItem = null;
        if (!empty($bookingItemId)) {
            $selectedBookingItem = $bookingItems->firstWhere('id', $bookingItemId);
            if (!$selectedBookingItem) {
                throw new \InvalidArgumentException('Selected booking item does not belong to this booking');
            }
        }

        if (!$selectedBookingItem) {
            $selectedBookingItem = $bookingItems->first();
        }

        $selectedVehicleId = $selectedBookingItem?->vehicle_id ?: $booking->vehicle_id;
        $selectedDriverId = $selectedBookingItem?->driver_id ?: $booking->driver_id;
        $isSelfDriven = (bool) ($selectedBookingItem?->is_self_driven ?? $booking->is_self_driven);

        return [
            'booking_item' => $selectedBookingItem,
            'booking_item_id' => $selectedBookingItem?->id,
            'trip_number' => $selectedBookingItem?->trip_number,
            'vehicle_id' => $selectedVehicleId,
            'driver_id' => $selectedDriverId,
            'is_self_driven' => $isSelfDriven,
            'vehicle' => $selectedBookingItem?->vehicle,
            'driver' => $selectedBookingItem?->driver,
        ];
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
            $booking = Booking::with(['dispatch', 'qc.repairItems', 'bookingItems.vehicle', 'bookingItems.driver.user'])->findOrFail($bookingId);
            $context = $this->resolveLifecycleContext($booking, $dispatchData['booking_item_id'] ?? null);
            $dispatch = $booking->dispatch;
            $vehicleId = $context['vehicle_id'];
            $driverId = $context['driver_id'];
            $isSelfDriven = (bool) $context['is_self_driven'];
            $hasPreviousDispatch = (bool) ($dispatch && (
                $dispatch->dispatched_at !== null ||
                $dispatch->isActive() ||
                $dispatch->isReturned()
            ));
            $isRepeatDispatch = $hasPreviousDispatch;
            $isReopeningCompletedHire = (bool) ($dispatch && !$dispatch->isActive());
            $allowRepeatDispatchForTesting = (bool) ($dispatchData['allow_repeat_dispatch_for_testing'] ?? false);

            if (!$vehicleId) {
                throw new \Exception('Vehicle must be assigned before dispatch');
            }

            if (!$isSelfDriven && !$driverId) {
                throw new \Exception('Driver must be assigned before dispatch');
            }

            if (!$isSelfDriven && $driverId) {
                $this->ensureDriverAssignmentForDispatch($booking, $context);
            }

            if ($isRepeatDispatch && !$allowRepeatDispatchForTesting) {
                throw new \Exception('Repeat dispatch is blocked unless allow_repeat_dispatch_for_testing is true');
            }

            if ($isReopeningCompletedHire) {
                $this->resetBookingAfterCompletedHireForRedispatch($booking);
            }

            if (!$dispatch) {
                $dispatch = $booking->dispatch()->create([
                    'vehicle_id' => $vehicleId,
                    'driver_id' => $driverId,
                    'dispatch_status' => DispatchStatus::NOT_DISPATCHED,
                    'expected_return_at' => $booking->to_date,
                    'is_self_driven' => $isSelfDriven,
                    'dispatch_notes' => $dispatchData['notes'] ?? null,
                    'agreements_signed' => (bool) ($dispatchData['agreements_signed'] ?? false),
                    'created_user_id' => Auth::id(),
                ]);
            } elseif (
                (string) $dispatch->vehicle_id !== (string) $vehicleId
                || (string) ($dispatch->driver_id ?? '') !== (string) ($driverId ?? '')
            ) {
                $dispatch->update([
                    'vehicle_id' => $vehicleId,
                    'driver_id' => $driverId,
                    'is_self_driven' => $isSelfDriven,
                ]);
            }

            // Mark vehicle as dispatched
            $dispatch->markDispatched(Auth::id(), $dispatchData);

            // Update vehicle availability
            $vehicle = Vehicle::findOrFail($vehicleId);
            $vehicle->update(['availability_status' => VehicleAvailabilityStatus::ON_HIRE->value]);

            if (!$isRepeatDispatch) {
                $booking->transitionToStatus(BookingLifecycleStatus::DISPATCH_OUT, Auth::id(), $dispatchData);
                $this->logLifecycleTransition($booking, BookingLifecycleStatus::DISPATCH_READY, BookingLifecycleStatus::DISPATCH_OUT, $dispatchData);
            }

            DB::afterCommit(function () use ($booking, $driverId, $context, $dispatch, $isRepeatDispatch) {
                $this->triggerDriverDispatchNotification(
                    (string) $booking->id,
                    $driverId ? (string) $driverId : null,
                    $context['booking_item_id'] ? (string) $context['booking_item_id'] : null,
                    [
                        'event_type' => 'booking_dispatch',
                        'notification_type' => 'booking_dispatch',
                        'dispatch_action' => $isRepeatDispatch ? 'redispatch' : 'dispatch',
                        'dispatch_id' => (string) $dispatch->id,
                        'dispatch_status' => $dispatch->dispatch_status?->value,
                        'dispatched_at' => $dispatch->dispatched_at?->toIso8601String(),
                        'is_repeat_dispatch' => $isRepeatDispatch,
                        'allow_repeat_dispatch_for_testing' => $isRepeatDispatch,
                    ]
                );
            });

            return $dispatch;
        });
    }

    private function resetBookingAfterCompletedHireForRedispatch(Booking $booking): void
    {
        if ($booking->qc) {
            $booking->qc->repairItems()->delete();
            $booking->qc()->delete();
            $booking->unsetRelation('qc');
        }

        if ((string) $booking->status === 'completed' || $booking->completed_at) {
            $booking->update([
                'status' => 'confirmed',
                'completed_at' => null,
                'updated_user_id' => Auth::id(),
            ]);
        }
    }

    private function triggerDriverDispatchNotification(string $bookingId, ?string $driverId, ?string $bookingItemId = null, array $notificationContext = []): void
    {
        if (!$driverId) {
            return;
        }

        $driverAssignmentsQuery = DriverAssignment::where('booking_id', $bookingId)
            ->where('driver_id', $driverId)
            ->whereIn('status', ['active', 'pending_approval', 'approved', 'confirmed'])
            ->orderByDesc('updated_at');

        $driverAssignments = $driverAssignmentsQuery
            ->when(!empty($bookingItemId), function ($query) use ($bookingItemId) {
                $query->where('booking_item_id', $bookingItemId);
            })
            ->get();

        // Fallback for legacy records where booking_item_id might be null/mismatched.
        if ($driverAssignments->isEmpty() && !empty($bookingItemId)) {
            $driverAssignments = DriverAssignment::where('booking_id', $bookingId)
                ->where('driver_id', $driverId)
                ->whereIn('status', ['active', 'pending_approval', 'approved', 'confirmed'])
                ->orderByDesc('updated_at')
                ->get();
        }

        if ($driverAssignments->isEmpty()) {
            Log::warning('Dispatch notification skipped: no matching driver assignments', [
                'booking_id' => $bookingId,
                'driver_id' => $driverId,
            ]);
            return;
        }

        foreach ($driverAssignments as $assignment) {
            try {
                $this->notificationTriggerService->processAssignmentNotificationAttempt($assignment, 1, $notificationContext);
            } catch (\Throwable $exception) {
                Log::warning('Failed to send dispatch notification to driver app', [
                    'booking_id' => $bookingId,
                    'assignment_id' => $assignment->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function ensureDriverAssignmentForDispatch(Booking $booking, array $context): void
    {
        $driverId = $context['driver_id'] ?? null;
        if (!$driverId) {
            return;
        }

        $bookingItemId = $context['booking_item_id'] ?? null;
        $selectedItem = $context['booking_item'] ?? null;

        $existingQuery = DriverAssignment::where('booking_id', $booking->id)
            ->where('driver_id', $driverId)
            ->whereIn('status', ['active', 'pending_approval', 'approved', 'confirmed']);

        if ($bookingItemId) {
            $existingQuery->where('booking_item_id', $bookingItemId);
        }

        if ($existingQuery->exists()) {
            return;
        }

        $booking->loadMissing([
            'customer.user',
            'serviceType',
            'bookingItems.serviceType',
        ]);

        $customerName = trim((string) (
            ($booking->customer?->user?->first_name ?? '') . ' ' . ($booking->customer?->user?->last_name ?? '')
        ));
        if ($customerName === '') {
            $customerName = (string) ($booking->customer?->name ?? 'Customer');
        }

        $serviceTypeName = (string) (
            $selectedItem?->serviceType?->name
                ?? $booking->serviceType?->name
                ?? 'Booking Service'
        );

        $assignedFrom = $selectedItem?->from_date ?? $booking->from_date ?? now();
        $assignedTo = $selectedItem?->to_date ?? $booking->to_date ?? $assignedFrom;

        $this->assignmentService->createDriverAssignment([
            'driver_id' => $driverId,
            'booking_id' => $booking->id,
            'booking_item_id' => $bookingItemId,
            'customer_name' => $customerName,
            'service_type' => $serviceTypeName,
            'assigned_from' => $assignedFrom,
            'assigned_to' => $assignedTo,
            'assignment_type' => 'primary',
            'status' => 'active',
        ]);
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
            $booking = Booking::with(['dispatch', 'bookingItems'])->findOrFail($bookingId);
            $context = $this->resolveLifecycleContext($booking, $returnData['booking_item_id'] ?? null);
            $dispatch = $booking->dispatch;
            $fromStatus = $booking->getLifecycleStatus();
            $actorUserId = $returnData['returned_by']
                ?? Auth::id()
                ?? $booking->updated_user_id
                ?? $booking->created_user_id
                ?? $dispatch?->dispatched_by;

            if (!$dispatch) {
                throw new \Exception('Dispatch record not found');
            }
            if (!$actorUserId) {
                throw new \Exception('Authenticated user is required to process return');
            }

            // Mark as returned
            $dispatch->markReturned((string) $actorUserId, $returnData);

            // Update vehicle availability (pending QC)
            $vehicleId = $dispatch->vehicle_id ?: $context['vehicle_id'];
            if (!$vehicleId) {
                throw new \Exception('Vehicle not found for return processing');
            }
            $workflowSettings = $this->getLifecycleWorkflowSettings();
            $forceSkipQc = (bool) ($returnData['skip_qc'] ?? false) || (bool) ($returnData['completed_by_driver'] ?? false);
            $isQcEnabled = !$forceSkipQc && (bool) ($workflowSettings['enable_qc_stage'] ?? true);

            if ($isQcEnabled) {
                $vehicle = Vehicle::findOrFail($vehicleId);
                $vehicle->update(['availability_status' => VehicleAvailabilityStatus::UNAVAILABLE_QC->value]);

                $lifecycleStatus = $dispatch->isOverdue()
                    ? BookingLifecycleStatus::RETURN_LATE
                    : BookingLifecycleStatus::RETURN_COMPLETED;

                $booking->transitionToStatus($lifecycleStatus, (string) $actorUserId, $returnData);

                $this->logLifecycleTransition(
                    $booking,
                    $fromStatus,
                    $lifecycleStatus,
                    $returnData
                );
            } else {
                // Skip QC stage for businesses that disable it in website settings.
                $this->makeVehicleAvailable($vehicleId);
                $completionMeta = array_merge(
                    $returnData,
                    [
                        'qc_skipped' => true,
                        'maintenance_stage_enabled' => (bool) ($workflowSettings['enable_maintenance_stage'] ?? true),
                    ]
                );
                $transitioned = $booking->transitionToStatus(
                    BookingLifecycleStatus::COMPLETED,
                    (string) $actorUserId,
                    $completionMeta
                );
                if (!$transitioned) {
                    $existingWorkflowData = is_array($booking->workflow_data) ? $booking->workflow_data : [];
                    $booking->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'updated_user_id' => $actorUserId,
                        'workflow_data' => array_merge($existingWorkflowData, [
                            'qc_skipped' => true,
                            'completed_via' => !empty($returnData['completed_by_driver']) ? 'driver_mobile' : 'return_processing',
                            'completed_at' => now()->toIso8601String(),
                        ]),
                    ]);
                }

                $this->logLifecycleTransition(
                    $booking,
                    $fromStatus,
                    BookingLifecycleStatus::COMPLETED,
                    array_merge($returnData, ['qc_skipped' => true])
                );
            }

            return $dispatch;
        });
    }

    // ========================
    // QC & REPAIR STAGE
    // ========================

    /**
     * Start QC inspection
     */
    public function startQCInspection(string $bookingId, ?string $inspectorId = null, ?string $bookingItemId = null): BookingQC
    {
        return DB::transaction(function () use ($bookingId, $inspectorId, $bookingItemId) {
            $booking = Booking::with(['dispatch', 'bookingItems'])->findOrFail($bookingId);
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $vehicleId = $booking->dispatch?->vehicle_id ?: $context['vehicle_id'];
            if (!$vehicleId) {
                throw new \Exception('Vehicle must be assigned before QC inspection');
            }
            $resolvedInspectorId = $inspectorId ?: Auth::id();
            if (!$resolvedInspectorId) {
                throw new \Exception('Inspector is required to start QC inspection');
            }

            // Create or get QC record
            $qc = $booking->qc ?: $booking->qc()->create([
                'vehicle_id' => $vehicleId,
                'dispatch_id' => $booking->dispatch?->id,
                'qc_status' => QCStatus::PENDING,
            ]);

            $qc->startInspection((string) $resolvedInspectorId);

            $booking->transitionToStatus(BookingLifecycleStatus::QC_IN_PROGRESS, Auth::id());

            $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_PENDING, BookingLifecycleStatus::QC_IN_PROGRESS);

            return $qc;
        });
    }

    /**
     * Complete QC inspection
     */
    public function completeQCInspection(string $bookingId, array $inspectionData, ?string $bookingItemId = null): BookingQC
    {
        return DB::transaction(function () use ($bookingId, $inspectionData, $bookingItemId) {
            $booking = Booking::findOrFail($bookingId);
            $this->resolveLifecycleContext($booking, $bookingItemId);
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
            $vehicleId = $qc->vehicle_id ?: $booking->vehicle_id;
            if (!$qc->needsRepair()) {
                if ($vehicleId) {
                    $this->makeVehicleAvailable($vehicleId);
                }
            } else {
                // Update vehicle to repair status
                if ($vehicleId) {
                    $vehicle = Vehicle::findOrFail($vehicleId);
                    $vehicle->update(['availability_status' => VehicleAvailabilityStatus::UNAVAILABLE_REPAIR->value]);
                }
            }

            return $qc;
        });
    }

    /**
     * Complete repairs and finish QC
     */
    public function completeRepairs(string $bookingId, array $repairData = [], ?string $bookingItemId = null): BookingQC
    {
        return DB::transaction(function () use ($bookingId, $repairData, $bookingItemId) {
            $booking = Booking::findOrFail($bookingId);
            $this->resolveLifecycleContext($booking, $bookingItemId);
            $qc = $booking->qc;

            if (!$qc) {
                throw new \Exception('QC record not found');
            }

            $qc->markCompleted();

            $booking->transitionToStatus(BookingLifecycleStatus::QC_COMPLETED, Auth::id(), $repairData);

            $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_REPAIR_NEEDED, BookingLifecycleStatus::QC_COMPLETED, $repairData);

            // Make vehicle available
            $vehicleId = $qc->vehicle_id ?: $booking->vehicle_id;
            if ($vehicleId) {
                $this->makeVehicleAvailable($vehicleId);
            }

            return $qc;
        });
    }

    // ========================
    // COMPLETION STAGE
    // ========================
    
    /**
     * Complete booking lifecycle
     */
    public function completeBooking(string $bookingId, array $completionData = [], ?string $bookingItemId = null): Booking
    {
        return DB::transaction(function () use ($bookingId, $completionData, $bookingItemId) {
            $booking = Booking::findOrFail($bookingId);
            $this->resolveLifecycleContext($booking, $bookingItemId);

            $booking->transitionToStatus(BookingLifecycleStatus::COMPLETED, Auth::id(), $completionData);

            $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_COMPLETED, BookingLifecycleStatus::COMPLETED, $completionData);

            // Notify corporate employee about booking completion
            if ($booking->is_corporate_booking && $booking->corporate_account_id) {
                \App\Models\AuditLog::create([
                    'user_id'   => auth()->id(),
                    'action'    => 'corporate_booking_completed',
                    'entity'    => 'Booking',
                    'entity_id' => $booking->id,
                    'timestamp' => now(),
                    'details'   => [
                        'corporate_id' => $booking->corporate_account_id,
                        'employee_id'  => $booking->employee_id,
                        'booking_number' => $booking->booking_number,
                        'completed_at' => now()->toISOString(),
                    ],
                ]);
            }

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

    private function getLifecycleWorkflowSettings(): array
    {
        $enableQc = $this->normalizeSettingBoolean(
            WebsiteSetting::getValue('assignment_enable_qc_stage', 'false'),
            false
        );
        $enableMaintenance = $this->normalizeSettingBoolean(
            WebsiteSetting::getValue('assignment_enable_maintenance_stage', 'false'),
            false
        );

        return [
            'enable_qc_stage' => $enableQc,
            'enable_maintenance_stage' => $enableMaintenance,
        ];
    }

    private function normalizeSettingBoolean(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
            return false;
        }

        return $default;
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
    public function getLifecycleSummary(string $bookingId, ?string $bookingItemId = null): array
    {
        $booking = Booking::with([
            'dispatch.vehicle',
            'dispatch.driver.user',
            'dispatch.dispatchedBy',
            'dispatch.returnedBy',
            'qc.inspector',
            'customer',
            'bookingItems.serviceType',
            'bookingItems.vehicle',
            'bookingItems.driver.user',
        ])->findOrFail($bookingId);
        $context = $this->resolveLifecycleContext($booking, $bookingItemId);
        $approvalTriggers = $this->bookingFlowService->getApprovalTriggersForBooking(
            $booking,
            $context['booking_item']
        );
        $approvalReasonLabels = $this->bookingFlowService->formatApprovalTriggerLabels($approvalTriggers);
        $requiresApproval = (bool) (($booking->requires_approval ?? false) || (($booking->status ?? null) === 'pending_approval'));
        if ($requiresApproval && empty($approvalReasonLabels)) {
            $approvalReasonLabels = ['Manager approval required'];
        }

        $currentStatus = $booking->getLifecycleStatus();
        $nextActions = $booking->getNextActions();
        $workflowSettings = $this->getLifecycleWorkflowSettings();

        return [
            'booking' => $booking,
            'selected_booking_item_id' => $context['booking_item_id'],
            'selected_trip_number' => $context['trip_number'],
            'selected_booking_item' => $context['booking_item'],
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
            'workflow_settings' => $workflowSettings,
            'approval_context' => [
                'requires_approval' => $requiresApproval,
                'triggers' => $approvalTriggers,
                'reasons' => $approvalReasonLabels,
                'justification' => $booking->approval_justification,
                'status' => $booking->approval_status,
            ],
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
        try {
            $baseQuery = User::query();

            // Prefer explicit boolean active flag if available.
            if (Schema::hasColumn('users', 'is_active')) {
                $baseQuery->where('is_active', true);
            } elseif (Schema::hasColumn('users', 'status')) {
                $baseQuery->where(function ($statusQuery) {
                    $statusQuery
                        ->whereIn('status', ['active', 'enabled', 'approved'])
                        ->orWhereNull('status');
                });
            }

            $selectColumns = ['id', 'email'];
            if (Schema::hasColumn('users', 'first_name')) {
                $selectColumns[] = 'first_name';
            }
            if (Schema::hasColumn('users', 'last_name')) {
                $selectColumns[] = 'last_name';
            }
            if (Schema::hasColumn('users', 'name')) {
                $selectColumns[] = 'name';
            }

            $orderColumn = Schema::hasColumn('users', 'first_name')
                ? 'first_name'
                : (Schema::hasColumn('users', 'name') ? 'name' : 'email');

            $inspectorsQuery = clone $baseQuery;
            if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
                $inspectorsQuery->whereHas('roles', function ($roleQuery) {
                    $roleQuery->whereIn('name', [
                        'qc_inspector',
                        'admin',
                        'operations_manager',
                    ]);
                });
            }

            $inspectors = $inspectorsQuery
                ->select($selectColumns)
                ->orderBy($orderColumn)
                ->get();

            // Fallback 1: any active user with a role assignment.
            if ($inspectors->isEmpty() && Schema::hasTable('roles') && Schema::hasTable('model_has_roles')) {
                $fallbackRoleQuery = clone $baseQuery;
                $inspectors = $fallbackRoleQuery
                    ->whereHas('roles')
                    ->select($selectColumns)
                    ->orderBy($orderColumn)
                    ->get();
            }

            // Fallback 2: any active user.
            if ($inspectors->isEmpty()) {
                $fallbackAllQuery = clone $baseQuery;
                $inspectors = $fallbackAllQuery
                    ->select($selectColumns)
                    ->orderBy($orderColumn)
                    ->get();
            }

            return $inspectors->map(function (User $user) {
                $name = trim((string) (
                    ($user->first_name ?? '') . ' ' . ($user->last_name ?? '')
                ));

                if ($name === '') {
                    $name = (string) ($user->name ?? $user->email ?? 'Unknown Inspector');
                }

                return [
                    'id' => $user->id,
                    'name' => $name,
                    'email' => $user->email,
                ];
            })->toArray();
        } catch (\Throwable $exception) {
            Log::warning('Unable to load available inspectors', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get ongoing details for a booking
     */
    public function getOngoingDetails(string $bookingId, ?string $bookingItemId = null): array
    {
        $booking = Booking::with([
            'dispatch.vehicle',
            'dispatch.driver.user',
            'customer',
            'bookingItems.vehicle',
            'bookingItems.driver.user',
        ])->findOrFail($bookingId);
        $context = $this->resolveLifecycleContext($booking, $bookingItemId);

        $dispatch = $booking->dispatch;
        if (!$dispatch) {
            throw new \Exception('No dispatch found for this booking');
        }

        $vehicle = $dispatch->vehicle ?: $context['vehicle'];
        $driver = $dispatch->driver ?: $context['driver'];
        $isOverdue = $dispatch->isOverdue();

        return [
            'booking_id' => $booking->id,
            'selected_booking_item_id' => $context['booking_item_id'],
            'selected_trip_number' => $context['trip_number'],
            'customer_name' => $booking->customer->name ?? 'Unknown Customer',
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'name' => $vehicle->title ?? $vehicle->name ?? 'Vehicle',
                'license_plate' => $vehicle->license_plate,
                'make' => $vehicle->make ?? null,
                'model' => $vehicle->model ?? null,
            ] : null,
            'driver' => $driver ? [
                'id' => $driver->id,
                'name' => trim(($driver->user->first_name ?? '') . ' ' . ($driver->user->last_name ?? '')) ?: ($driver->name ?? 'Unknown Driver'),
                'phone' => $driver->user->phone ?? null,
            ] : null,
            'dispatch_details' => [
                'dispatched_at' => $dispatch->dispatched_at,
                'dispatch_notes' => $dispatch->dispatch_notes,
                'fuel_level_out' => $dispatch->fuel_level_out,
                'mileage_out' => $dispatch->mileage_out,
            ],
            'expected_return' => $dispatch->expected_return_at ?? ($context['booking_item']?->to_date ?? $booking->to_date),
            'status' => $isOverdue ? 'overdue' : (($dispatch->dispatch_status?->value) ?? 'dispatched'),
            'duration_hours' => $dispatch->dispatched_at ? now()->diffInHours($dispatch->dispatched_at) : 0,
        ];
    }

    /**
     * Get dispatch details for a booking
     */
    public function getDispatchDetails(string $bookingId, ?string $bookingItemId = null): array
    {
        $booking = Booking::with([
            'dispatch.vehicle',
            'dispatch.driver.user',
            'bookingItems.vehicle',
            'bookingItems.driver.user',
        ])->findOrFail($bookingId);
        $context = $this->resolveLifecycleContext($booking, $bookingItemId);

        $dispatch = $booking->dispatch;
        if (!$dispatch) {
            throw new \Exception('No dispatch found for this booking');
        }

        $vehicle = $dispatch->vehicle ?: $context['vehicle'];
        $driver = $dispatch->driver ?: $context['driver'];

        return [
            'id' => $dispatch->id,
            'booking_id' => $booking->id,
            'selected_booking_item_id' => $context['booking_item_id'],
            'selected_trip_number' => $context['trip_number'],
            'dispatch_status' => $dispatch->dispatch_status?->value,
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'name' => $vehicle->title ?? $vehicle->name ?? 'Vehicle',
                'license_plate' => $vehicle->license_plate,
                'make' => $vehicle->make ?? null,
                'model' => $vehicle->model ?? null,
            ] : null,
            'driver' => $driver ? [
                'id' => $driver->id,
                'name' => trim(($driver->user->first_name ?? '') . ' ' . ($driver->user->last_name ?? '')) ?: ($driver->name ?? 'Unknown Driver'),
                'phone' => $driver->user->phone ?? null,
            ] : null,
            'dispatch_details' => [
                'dispatched_at' => $dispatch->dispatched_at,
                'handover_time' => $dispatch->dispatched_at,
                'handover_location' => null,
                'dispatch_notes' => $dispatch->dispatch_notes,
                'vehicle_condition_notes' => data_get($dispatch->vehicle_condition_out, 'notes'),
                'dispatch_fuel_level' => $dispatch->fuel_level_out,
                'dispatch_mileage' => $dispatch->mileage_out,
            ],
            'return_details' => [
                'returned_at' => $dispatch->actual_return_at,
                'return_condition_notes' => data_get($dispatch->vehicle_condition_in, 'notes'),
                'return_fuel_level' => $dispatch->fuel_level_in,
                'return_mileage' => $dispatch->mileage_in,
                'return_notes' => $dispatch->return_notes,
                'damages' => $dispatch->damages_reported,
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
    public function getQCDetails(string $bookingId, ?string $bookingItemId = null): array
    {
        $booking = Booking::with([
            'qc.repairItems',
            'qc.inspector',
            'bookingItems',
        ])->findOrFail($bookingId);
        $context = $this->resolveLifecycleContext($booking, $bookingItemId);

        $qc = $booking->qc;

        if (!$qc) {
            throw new \Exception('No QC record found for this booking');
        }

        return [
            'id' => $qc->id,
            'booking_id' => $booking->id,
            'selected_booking_item_id' => $context['booking_item_id'],
            'selected_trip_number' => $context['trip_number'],
            'status' => $qc->qc_status?->value,
            'inspector' => $qc->inspector ? [
                'id' => $qc->inspector->id,
                'name' => $qc->inspector->name,
                'email' => $qc->inspector->email,
            ] : null,
            'inspection_details' => [
                'started_at' => $qc->inspection_started_at,
                'completed_at' => $qc->inspection_completed_at,
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
            ->orWhere(function ($query) use ($start, $end) {
                $query->whereNull('completed_date')
                    ->where('performed_date', '<=', $end);
            })
            ->exists();

        // Check booking conflicts through booking_items
        $bookingConflicts = DB::table('booking_items')
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->where('booking_items.vehicle_id', $vehicleId)
            ->where('bookings.lifecycle_status', '!=', BookingLifecycleStatus::COMPLETED)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('booking_items.from_date', [$start, $end])
                    ->orWhereBetween('booking_items.to_date', [$start, $end])
                    ->orWhere(function ($q) use ($start, $end) {
                        $q->where('booking_items.from_date', '<=', $start)
                            ->where('booking_items.to_date', '>=', $end);
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
