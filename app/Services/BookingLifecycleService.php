<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingQC;
use App\Models\DriverAssignment;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\Vehicle;
use App\Services\Driver\NotificationTriggerService;
use App\Services\Pricing\FinalPricingTelemetryResolver;
use App\Models\AuditLog;
use App\Notifications\BookingLifecycleNotification;
use App\Services\InvoiceService;
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
    protected InvoiceService $invoiceService;
    protected AvailabilityEnforcementService $availabilityEnforcement;
    protected LoyaltyService $loyaltyService;
    protected AgentCommissionService $agentCommissionService;
    protected WebsiteSettingsService $websiteSettingsService;
    protected CustomerMobileActivityService $customerMobileActivityService;
    protected FinalPricingTelemetryResolver $finalPricingTelemetryResolver;

    public function __construct(
        AssignmentService $assignmentService,
        BookingFlowService $bookingFlowService,
        CurrencyService $currencyService,
        NotificationTriggerService $notificationTriggerService,
        InvoiceService $invoiceService,
        AvailabilityEnforcementService $availabilityEnforcement,
        LoyaltyService $loyaltyService,
        AgentCommissionService $agentCommissionService,
        WebsiteSettingsService $websiteSettingsService,
        CustomerMobileActivityService $customerMobileActivityService,
        FinalPricingTelemetryResolver $finalPricingTelemetryResolver
    ) {
        $this->assignmentService = $assignmentService;
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
        $this->notificationTriggerService = $notificationTriggerService;
        $this->invoiceService = $invoiceService;
        $this->availabilityEnforcement = $availabilityEnforcement;
        $this->loyaltyService = $loyaltyService;
        $this->agentCommissionService = $agentCommissionService;
        $this->websiteSettingsService = $websiteSettingsService;
        $this->customerMobileActivityService = $customerMobileActivityService;
        $this->finalPricingTelemetryResolver = $finalPricingTelemetryResolver;
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
            $this->assertReturnStageAvailable($booking);

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
            $this->assertReturnStageAvailable($booking);
            $this->assertItemSafeLifecycle($booking, $returnData['booking_item_id'] ?? null);
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

            if (!array_key_exists('late_fee', $returnData)) {
                $returnData['late_fee'] = $dispatch->calculateLateReturnFeeAt(
                    $returnData['actual_return_time'] ?? Carbon::now('UTC')
                );
            }

            // Mark as returned
            $dispatch->markReturned((string) $actorUserId, $returnData);

            $finalPricing = $this->synchronizeFinalPricing(
                $booking,
                $context,
                $dispatch->fresh(),
                $returnData,
                !empty($returnData['completed_by_driver']) ? 'driver_mobile_return' : 'system_return'
            );
            $returnData['final_pricing'] = $finalPricing;

            // Post-trip maintenance trigger check (non-blocking)
            $vehicleId = $dispatch->vehicle_id ?: $context['vehicle_id'];
            if ($vehicleId) {
                $mileageIn = (int) ($returnData['mileage_in'] ?? $dispatch->mileage_in ?? 0);
                if ($mileageIn > 0) {
                    try {
                        $vehicleForMaintenance = Vehicle::find($vehicleId);
                        if ($vehicleForMaintenance) {
                            $triggered = $this->availabilityEnforcement->checkPostTripMaintenanceTriggers(
                                $vehicleForMaintenance,
                                $mileageIn
                            );
                            if (!empty($triggered)) {
                                Log::info('Post-trip maintenance triggered', [
                                    'vehicle_id' => $vehicleId,
                                    'mileage'    => $mileageIn,
                                    'triggered'  => array_column($triggered, 'schedule_id'),
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::error('Post-trip maintenance check failed', [
                            'vehicle_id' => $vehicleId,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Update vehicle availability (pending QC)
            if (!$vehicleId) {
                throw new \Exception('Vehicle not found for return processing');
            }
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
                    $completedAt = Carbon::now('UTC');
                    $booking->update([
                        'status' => 'completed',
                        'completed_at' => $completedAt,
                        'updated_user_id' => $actorUserId,
                        'workflow_data' => array_merge($existingWorkflowData, [
                            'qc_skipped' => true,
                            'completed_via' => !empty($returnData['completed_by_driver']) ? 'driver_mobile' : 'return_processing',
                            'completed_at' => $completedAt->toIso8601String(),
                        ]),
                    ]);
                }

                $this->logLifecycleTransition(
                    $booking,
                    $fromStatus,
                    BookingLifecycleStatus::COMPLETED,
                    array_merge($returnData, ['qc_skipped' => true])
                );

                // Driver-mobile and direct-return completion paths do not pass
                // through completeBooking(), so invoice only after final pricing
                // has been synchronized above.
                try {
                    $this->invoiceService->generateAndSend($booking->fresh([
                        'customer.user',
                        'bookingItems.serviceType',
                        'bookingItems.vehicle.group',
                        'bookingItems.driver.user',
                        'bookingAddons',
                    ]));
                } catch (\Throwable $e) {
                    Log::error('Invoice generation failed after direct return completion', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
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
            $this->assertQcStageAvailable($booking);
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
            $this->assertQcStageAvailable($booking);
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
            $this->assertQcStageAvailable($booking);
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
            $booking = Booking::with(['dispatch', 'bookingItems'])->findOrFail($bookingId);
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $workflowSettings = $this->getLifecycleWorkflowSettings();
            $fromStatus = $booking->getLifecycleStatus();
            $actorUserId = Auth::id()
                ?? $booking->updated_user_id
                ?? $booking->created_user_id
                ?? $booking->dispatch?->dispatched_by;

            if (!$actorUserId) {
                throw new \Exception('Authenticated user is required to complete the booking');
            }

            $canSkipReturn = !($workflowSettings['enable_return_stage'] ?? false)
                && in_array($fromStatus, [
                    BookingLifecycleStatus::ONGOING_ACTIVE,
                    BookingLifecycleStatus::ONGOING_REPLACEMENT_NEEDED,
                    BookingLifecycleStatus::ONGOING_BREAKDOWN,
                ], true);
            $canSkipQc = !($workflowSettings['enable_qc_stage'] ?? false)
                && in_array($fromStatus, [
                    BookingLifecycleStatus::RETURN_COMPLETED,
                    BookingLifecycleStatus::RETURN_LATE,
                ], true);

            if ($canSkipReturn && $booking->dispatch) {
                $booking->dispatch->markReturned((string) $actorUserId, [
                    'actual_return_time' => Carbon::now('UTC'),
                    'notes' => 'Trip completed without return management.',
                    'completed_by_driver' => true,
                ]);
            }

            $completionData['final_pricing'] = $this->synchronizeFinalPricing(
                $booking,
                $context,
                $booking->dispatch?->fresh(),
                $completionData,
                'booking_completion'
            );

            $transitioned = $booking->transitionToStatus(
                BookingLifecycleStatus::COMPLETED,
                (string) $actorUserId,
                $completionData
            );

            if (!$transitioned && ($canSkipReturn || $canSkipQc)) {
                $completedAt = Carbon::now('UTC');
                $booking->update([
                    'status' => 'completed',
                    'completed_at' => $completedAt,
                    'updated_user_id' => $actorUserId,
                    'workflow_data' => array_merge(
                        is_array($booking->workflow_data) ? $booking->workflow_data : [],
                        $completionData,
                        [
                            'return_skipped' => $canSkipReturn,
                            'qc_skipped' => $canSkipQc || $canSkipReturn,
                            'completed_via' => $canSkipReturn ? 'direct_trip_completion' : 'return_processing',
                            'completed_at' => $completedAt->toIso8601String(),
                        ]
                    ),
                ]);

                $vehicleId = $booking->dispatch?->vehicle_id ?: $context['vehicle_id'];
                if ($vehicleId) {
                    $this->makeVehicleAvailable($vehicleId);
                }
            } elseif (!$transitioned) {
                throw new \Exception('Booking cannot be completed from its current lifecycle status');
            }

            $this->logLifecycleTransition(
                $booking,
                $fromStatus,
                BookingLifecycleStatus::COMPLETED,
                array_merge($completionData, [
                    'return_skipped' => $canSkipReturn,
                    'qc_skipped' => $canSkipQc || $canSkipReturn,
                ])
            );

            // Generate and email invoice on completion
            try {
                $this->invoiceService->generateAndSend($booking->fresh([
                    'customer.user',
                    'bookingItems.serviceType',
                    'bookingItems.vehicle.group',
                    'bookingItems.driver.user',
                    'bookingAddons',
                ]));
            } catch (\Throwable $e) {
                // Invoice failure must not roll back the booking completion.
                Log::error('Invoice generation failed on booking completion', [
                    'booking_id' => $bookingId,
                    'error'      => $e->getMessage(),
                ]);
            }

            // Award loyalty points (non-blocking)
            try {
                $this->loyaltyService->awardPointsForBooking($booking->fresh());
            } catch (\Throwable $e) {
                Log::error('Loyalty points award failed on booking completion', [
                    'booking_id' => $bookingId,
                    'error'      => $e->getMessage(),
                ]);
            }

            // Record agent commission (non-blocking)
            try {
                $this->agentCommissionService->recordForBooking($booking->fresh());
            } catch (\Throwable $e) {
                Log::error('Agent commission recording failed on booking completion', [
                    'booking_id' => $bookingId,
                    'error'      => $e->getMessage(),
                ]);
            }

            // Notify corporate employee about booking completion
            if ($booking->is_corporate_booking && $booking->corporate_account_id) {
                $auditTimestamp = Carbon::now('UTC');
                \App\Models\AuditLog::create([
                    'user_id'   => auth()->id(),
                    'action'    => 'corporate_booking_completed',
                    'entity'    => 'Booking',
                    'entity_id' => $booking->id,
                    'timestamp' => $auditTimestamp,
                    'details'   => [
                        'corporate_id'   => $booking->corporate_account_id,
                        'employee_id'    => $booking->employee_id,
                        'booking_number' => $booking->booking_number,
                        'completed_at'   => $auditTimestamp->toIso8601String(),
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
     * Re-run the configured pricing graph with measured operational data before
     * an invoice can be generated. Driver mobile telemetry wins for chauffeur
     * trips; self-drive uses dispatch/return mileage and timestamps; system
     * actions use the supplied return/completion values with persisted fallback.
     */
    private function synchronizeFinalPricing(
        Booking $booking,
        array $context,
        ?BookingDispatch $dispatch,
        array $activityData,
        string $trigger
    ): array {
        /** @var BookingItem|null $bookingItem */
        $bookingItem = $context['booking_item'] ?? null;
        if (!$bookingItem || !$bookingItem->service_type_id || !$bookingItem->vehicle_group_id) {
            return ['status' => 'preserved', 'reason' => 'booking_item_pricing_context_missing', 'trigger' => $trigger];
        }

        // Serialize final pricing with customer-mobile event writes. Both paths
        // lock the same item row, so a telemetry event either becomes part of
        // this calculation or is rejected after completion; it cannot race the
        // invoice snapshot.
        $bookingItem = BookingItem::query()
            ->whereKey($bookingItem->id)
            ->where('booking_id', $booking->id)
            ->lockForUpdate()
            ->firstOrFail();

        $isSelfDriven = (bool) ($context['is_self_driven'] ?? $bookingItem->is_self_driven);
        $assignment = null;
        if (!$isSelfDriven) {
            $assignment = DriverAssignment::query()
                ->where('booking_id', $booking->id)
                ->when($bookingItem->id, fn ($query) => $query->where('booking_item_id', $bookingItem->id))
                ->orderByDesc('trip_completed_at')
                ->orderByDesc('updated_at')
                ->first();
        }

        $hasDriverTelemetry = $assignment && (
            $assignment->trip_started_at
            || $assignment->trip_completed_at
            || $assignment->total_distance_km !== null
            || $assignment->total_waiting_time_seconds > 0
        );

        $driverTelemetry = $hasDriverTelemetry ? [
            '_source' => 'driver_mobile_activity',
            'actual_start_time' => $assignment->trip_started_at ?: $assignment->actual_start,
            'actual_return_time' => $assignment->trip_completed_at ?: $assignment->actual_end,
            'distance_km' => $assignment->total_distance_km !== null
                ? (float) $assignment->total_distance_km
                : null,
            'waiting_minutes' => (int) ceil(((int) $assignment->total_waiting_time_seconds) / 60),
        ] : null;

        // Values submitted through staff lifecycle endpoints are a trusted
        // system source. A caller merely declaring customer_mobile is never
        // trusted: customer telemetry must exist in the ownership-scoped table.
        $declaredActivitySource = $activityData['activity_source'] ?? null;
        $trustedSystemPayload = !in_array($declaredActivitySource, ['customer_mobile', 'driver_mobile'], true);
        $systemTelemetry = $trustedSystemPayload ? [
            '_source' => in_array($declaredActivitySource, ['system_return', 'system_completion'], true)
                ? $declaredActivitySource
                : ($trigger === 'system_return' ? 'system_return' : 'system_completion'),
            'actual_start_time' => $activityData['actual_start_time'] ?? null,
            'actual_return_time' => $activityData['actual_return_time'] ?? null,
            'distance_km' => is_numeric($activityData['actual_distance'] ?? null)
                ? (float) $activityData['actual_distance']
                : (is_numeric($activityData['distance_km'] ?? null) ? (float) $activityData['distance_km'] : null),
            'waiting_minutes' => array_key_exists('waiting_minutes', $activityData)
                ? (int) $activityData['waiting_minutes']
                : null,
        ] : null;

        // A dispatch start alone is not final telemetry. It becomes an
        // authoritative return source once a return time or mileage pair exists.
        $hasDispatchReturnTelemetry = $dispatch && (
            $dispatch->actual_return_at
            || ($dispatch->mileage_in !== null && $dispatch->mileage_out !== null)
        );
        $dispatchTelemetry = $hasDispatchReturnTelemetry ? [
            '_source' => $isSelfDriven ? 'self_drive_return' : 'dispatch_return',
            'actual_start_time' => $dispatch->dispatched_at,
            'actual_return_time' => $dispatch->actual_return_at,
            'distance_km' => $dispatch->mileage_in !== null && $dispatch->mileage_out !== null
                ? max(0, (float) $dispatch->mileage_in - (float) $dispatch->mileage_out)
                : null,
            'waiting_minutes' => null,
        ] : null;

        $customerTelemetry = null;
        if (
            !$this->finalPricingTelemetryResolver->hasTelemetry($driverTelemetry)
            && !$this->finalPricingTelemetryResolver->hasTelemetry($systemTelemetry)
            && !$this->finalPricingTelemetryResolver->hasTelemetry($dispatchTelemetry)
        ) {
            $persistedCustomerTelemetry = $this->customerMobileActivityService->summaryForItem(
                (string) $bookingItem->id
            );
            if (($persistedCustomerTelemetry['complete'] ?? false) === true) {
                $customerTelemetry = [
                    '_source' => 'customer_mobile_activity',
                    'actual_start_time' => $persistedCustomerTelemetry['actual_start_time'] ?? null,
                    'actual_return_time' => $persistedCustomerTelemetry['actual_return_time'] ?? null,
                    'distance_km' => $persistedCustomerTelemetry['distance_km'] ?? null,
                    'waiting_minutes' => $persistedCustomerTelemetry['waiting_minutes'] ?? null,
                ];
            }
        }

        $resolvedTelemetry = $this->finalPricingTelemetryResolver->resolve([
            'driver_mobile_activity' => $driverTelemetry,
            'system_activity' => $systemTelemetry,
            'dispatch_return' => $dispatchTelemetry,
            'customer_mobile_activity' => $customerTelemetry,
        ], [
            '_source' => 'booking_persisted_fallback',
            'actual_start_time' => $booking->trip_started_at,
            'actual_return_time' => $booking->completed_at,
            'distance_km' => $booking->actual_distance !== null ? (float) $booking->actual_distance : null,
            'waiting_minutes' => data_get($booking->duration_metrics, 'waiting_minutes'),
        ]);

        $source = (string) $resolvedTelemetry['source'];
        $sourceCategory = (string) $resolvedTelemetry['source_category'];
        $sourceSelection = $resolvedTelemetry['source_selection'];
        $startedAt = $resolvedTelemetry['actual_start_time'] ?? null;
        $completedAt = $resolvedTelemetry['actual_return_time'] ?? Carbon::now('UTC');

        $startedAt = $startedAt ? Carbon::parse($startedAt) : null;
        $completedAt = $completedAt ? Carbon::parse($completedAt) : Carbon::now('UTC');
        $measuredDurationMinutes = $this->finalPricingTelemetryResolver->elapsedMinutes(
            $startedAt,
            $completedAt
        );
        $durationMinutes = $measuredDurationMinutes
            ?? (int) ($booking->actual_duration
                ?? data_get($booking->duration_metrics, 'actual_minutes')
                ?? $bookingItem->duration_minutes
                ?? (($bookingItem->duration_hours ?? 0) * 60));
        $hasDurationSource = $startedAt !== null
            || $booking->actual_duration !== null
            || data_get($booking->duration_metrics, 'actual_minutes') !== null
            || (int) ($bookingItem->duration_minutes ?? 0) > 0
            || (int) ($bookingItem->duration_hours ?? 0) > 0;

        $distanceKm = is_numeric($resolvedTelemetry['distance_km'] ?? null)
            ? (float) $resolvedTelemetry['distance_km']
            : null;

        $contractualDistance = data_get($booking->pricing_snapshot, 'distance_policy.coordinate_source') === 'corporate_distance_policy'
            || data_get($booking->pricing_snapshot, 'base_pricing.distance_policy.coordinate_source') === 'corporate_distance_policy'
            || data_get($bookingItem->pricing_breakdown, 'distance_policy.coordinate_source') === 'corporate_distance_policy'
            || data_get($bookingItem->pricing_breakdown, 'base_pricing.distance_policy.coordinate_source') === 'corporate_distance_policy';

        if ($contractualDistance) {
            $distanceKm = (float) (
                data_get($bookingItem->metadata, 'distance_details.total_billable_distance')
                ?? data_get($bookingItem->metadata, 'distance_details.total_distance')
                ?? data_get($bookingItem->pricing_breakdown, 'base_pricing.distance_details.total_billable_distance')
                ?? data_get($bookingItem->pricing_breakdown, 'base_pricing.distance_details.total_distance')
                ?? $distanceKm
                ?? 0
            );
        }

        $waitingMinutes = (int) ($resolvedTelemetry['waiting_minutes'] ?? 0);
        $metadata = is_array($bookingItem->metadata) ? $bookingItem->metadata : [];
        $hasIncludedDuration = array_key_exists('included_minutes', $metadata)
            || array_key_exists('included_hours', $metadata)
            || data_get($metadata, 'package_info.default_duration_hours') !== null
            || (int) ($bookingItem->duration_minutes ?? 0) > 0
            || (int) ($bookingItem->duration_hours ?? 0) > 0;
        if (array_key_exists('included_minutes', $metadata)) {
            $includedMinutes = (int) $metadata['included_minutes'];
        } elseif (array_key_exists('included_hours', $metadata)) {
            $includedMinutes = (int) round((float) $metadata['included_hours'] * 60);
        } elseif (data_get($metadata, 'package_info.default_duration_hours') !== null) {
            $includedMinutes = (int) data_get($metadata, 'package_info.default_duration_hours', 0) * 60
                + (int) data_get($metadata, 'package_info.default_duration_minutes', 0);
        } else {
            $includedMinutes = (int) ($bookingItem->duration_minutes
                ?? ((int) ($bookingItem->duration_hours ?? 0) * 60));
        }
        $extraMinutes = max(0, $durationMinutes - $includedMinutes);

        $params = [
            'service_type_id' => $bookingItem->service_type_id,
            'vehicle_group_id' => $bookingItem->vehicle_group_id,
            'vehicle_id' => $bookingItem->vehicle_id ?: $context['vehicle_id'],
            'corporate_account_id' => $booking->corporate_account_id,
            'package_id' => $metadata['service_package_id'] ?? $metadata['package_id'] ?? null,
            'customer_id' => $booking->customer_id,
            'from_date' => $bookingItem->from_date ?? $booking->from_date,
            'to_date' => $bookingItem->to_date ?? $booking->to_date,
            'from_time' => $bookingItem->from_time ?? $booking->from_time,
            'to_time' => $bookingItem->to_time ?? $booking->to_time,
            'duration_hours' => $durationMinutes / 60,
            'duration_minutes' => $durationMinutes,
            'duration_days' => $durationMinutes >= 1440 ? (int) ceil($durationMinutes / 1440) : 0,
            'extra_minutes' => $extraMinutes,
            'extra_hours' => $extraMinutes / 60,
            'overtime_minutes' => $extraMinutes,
            'overtime_hours' => $extraMinutes / 60,
            'waiting_minutes' => $waitingMinutes,
            'waiting_hours' => $waitingMinutes / 60,
            'mode' => 'final_calculation',
        ];
        if ($distanceKm !== null) {
            $params += [
                'journey_distance' => (float) $distanceKm,
                'total_distance' => (float) $distanceKm,
                'actual_distance' => (float) $distanceKm,
            ];
        }

        $activeDefinitions = VehiclePricingCalculationDefinition::query()
            ->where('service_type_id', $bookingItem->service_type_id)
            ->where('status', 'active')
            ->get(['id', 'variables', 'formula']);

        $result = $this->bookingFlowService->calculateDynamicPricing($params);
        $definitionId = data_get($result, 'pricing_scope.calculation_definition_id')
            ?? data_get($result, 'calculation_metadata.definition_used');
        $calculatedBase = (float) ($result['total_amount'] ?? 0);

        $audit = [
            'status' => $definitionId && $calculatedBase >= 0 ? 'calculated' : 'preserved',
            'trigger' => $trigger,
            'source' => $source,
            'source_category' => $sourceCategory,
            'source_selection' => $sourceSelection,
            'calculation_definition_id' => $definitionId,
            'calculated_at' => Carbon::now('UTC')->toIso8601String(),
            'contractual_distance_preserved' => $contractualDistance,
            'inputs' => [
                'duration_minutes' => $durationMinutes,
                'distance_km' => $distanceKm !== null ? round((float) $distanceKm, 2) : null,
                'waiting_minutes' => $waitingMinutes,
                'extra_minutes' => $extraMinutes,
            ],
        ];

        if (!$definitionId) {
            if ($activeDefinitions->isNotEmpty()) {
                throw new \DomainException(
                    'Final pricing could not resolve an active calculation definition. Completion was stopped to prevent an incorrect invoice.'
                );
            }
            $audit['reason'] = 'no_active_calculation_definition';
            $bookingItem->update([
                'metadata' => array_merge($metadata, ['final_pricing_audit' => $audit]),
            ]);
            return $audit;
        }

        $selectedDefinition = $activeDefinitions->firstWhere('id', $definitionId);
        $selectedFormula = (string) ($selectedDefinition?->formula ?? '');
        $formulaUsesAny = static function (array $names) use ($selectedFormula): bool {
            foreach ($names as $name) {
                if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote($name, '/') . '(?![A-Za-z0-9_])/', $selectedFormula)) {
                    return true;
                }
            }
            return false;
        };

        if (!$contractualDistance && $distanceKm === null && $formulaUsesAny([
            'journey_distance', 'total_distance', 'actual_distance', 'distance_km', 'extra_km',
        ])) {
            throw new \DomainException(
                'Final distance is required by the selected pricing definition. Completion was stopped until mileage or measured distance is supplied.'
            );
        }
        if (!$hasDurationSource && $formulaUsesAny(['duration_minutes', 'duration_hours', 'duration_days'])) {
            throw new \DomainException(
                'Final duration is required by the selected pricing definition. Completion was stopped until start and return times are supplied.'
            );
        }
        if (!$hasIncludedDuration && $formulaUsesAny([
            'extra_minutes', 'extra_hours', 'overtime_minutes', 'overtime_hours',
        ])) {
            throw new \DomainException(
                'Included package duration is missing. Completion was stopped to prevent all trip time from being charged as overtime.'
            );
        }

        $manualCharges = $this->sumOperationalCharges($dispatch?->additional_charges ?? []);
        if ($trigger === 'booking_completion') {
            // A staff-approved completion adjustment is additional to charges
            // already captured at return. During processReturn both arrays
            // describe the same charges, so only the persisted dispatch copy is
            // counted there.
            $manualCharges += $this->sumOperationalCharges($activityData['charges'] ?? []);
        } elseif (!$dispatch) {
            $manualCharges += $this->sumOperationalCharges($activityData['charges'] ?? []);
        }
        $manualCharges = round($manualCharges, 2);
        $lateFee = (float) ($dispatch?->late_return_fee ?? $activityData['late_fee'] ?? 0);
        $finalBase = round($calculatedBase + $manualCharges + $lateFee, 2);
        $audit += [
            'calculated_base' => $calculatedBase,
            'manual_charges' => $manualCharges,
            'late_return_fee' => $lateFee,
            'final_base' => $finalBase,
            'calculation_example' => $this->buildFinalCalculationExample($result, $manualCharges, $lateFee, $finalBase),
        ];

        $bookingItem->update([
            'unit_price' => $finalBase,
            'total_price' => $finalBase * max(1, (int) $bookingItem->quantity),
            'pricing_breakdown' => array_merge(
                is_array($bookingItem->pricing_breakdown) ? $bookingItem->pricing_breakdown : [],
                ['final_pricing' => array_merge($result, ['audit' => $audit])]
            ),
            'metadata' => array_merge($metadata, ['final_pricing_audit' => $audit]),
        ]);

        $booking->load('bookingItems');
        $bookingUpdates = [
            'actual_duration' => $durationMinutes,
            'base_amount' => round((float) $booking->bookingItems->sum('total_price'), 2),
            'total_actual' => round($booking->calculateTotal(), 2),
            'duration_metrics' => array_merge(is_array($booking->duration_metrics) ? $booking->duration_metrics : [], [
                'source' => $source,
                'actual_minutes' => $durationMinutes,
                'waiting_minutes' => $waitingMinutes,
                'extra_minutes' => $extraMinutes,
            ]),
            'distance_metrics' => array_merge(is_array($booking->distance_metrics) ? $booking->distance_metrics : [], [
                'source' => $source,
                'actual_km' => $distanceKm !== null ? round((float) $distanceKm, 2) : null,
                'pricing_effect' => $contractualDistance ? 'contractual_distance_preserved' : 'final_recalculation',
            ]),
            'pricing_snapshot' => array_merge(is_array($booking->pricing_snapshot) ? $booking->pricing_snapshot : [], [
                'final_pricing' => $audit,
            ]),
        ];
        if ($distanceKm !== null) {
            $bookingUpdates['actual_distance'] = round((float) $distanceKm, 2);
        }
        $booking->update($bookingUpdates);

        return $audit;
    }

    private function sumOperationalCharges(mixed $charges): float
    {
        if (is_numeric($charges)) {
            return round((float) $charges, 2);
        }
        if (!is_array($charges)) {
            return 0.0;
        }

        return round((float) collect($charges)->sum(function ($charge) {
            return is_numeric($charge)
                ? (float) $charge
                : (float) ($charge['amount'] ?? $charge['total'] ?? $charge['value'] ?? 0);
        }), 2);
    }

    private function assertItemSafeLifecycle(Booking $booking, ?string $bookingItemId): void
    {
        if ($booking->bookingItems->count() <= 1) {
            return;
        }

        throw new \DomainException(
            'Multi-item completion requires item-level dispatch and invoice ownership. Completion was stopped to prevent invoicing unfinished trips.'
        );
    }

    private function buildFinalCalculationExample(array $result, float $manualCharges, float $lateFee, float $finalBase): array
    {
        $lines = collect($result['breakdown'] ?? [])->map(function ($line) {
            return [
                'label' => $line['name'] ?? $line['description'] ?? $line['component'] ?? 'Charge',
                'calculation' => $line['calculation'] ?? null,
                'amount' => (float) ($line['amount'] ?? 0),
            ];
        })->values()->all();

        if ($manualCharges > 0) {
            $lines[] = ['label' => 'Operational charges', 'calculation' => 'Approved return/completion charges', 'amount' => $manualCharges];
        }
        if ($lateFee > 0) {
            $lines[] = ['label' => 'Late return fee', 'calculation' => 'Configured late-return rule', 'amount' => $lateFee];
        }

        return ['lines' => $lines, 'total' => $finalBase];
    }

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
        $enableReturn = $this->normalizeSettingBoolean(
            $this->websiteSettingsService->get('feature_vehicle_return_management_enabled', 'false'),
            false
        );
        $enableQc = $this->normalizeSettingBoolean(
            $this->websiteSettingsService->get('assignment_enable_qc_stage', 'false'),
            false
        ) && $enableReturn;
        $enableMaintenance = $this->normalizeSettingBoolean(
            $this->websiteSettingsService->get('assignment_enable_maintenance_stage', 'false'),
            false
        );

        return [
            'enable_return_stage' => $enableReturn,
            'enable_qc_stage' => $enableQc,
            'enable_maintenance_stage' => $enableMaintenance,
        ];
    }

    private function assertReturnStageAvailable(Booking $booking): void
    {
        $settings = $this->getLifecycleWorkflowSettings();
        if (
            !($settings['enable_return_stage'] ?? false)
            && $booking->getLifecycleStatus()->getStage() !== 'return'
        ) {
            throw new \Exception('Vehicle return management is disabled in booking settings');
        }
    }

    private function assertQcStageAvailable(Booking $booking): void
    {
        $settings = $this->getLifecycleWorkflowSettings();
        if (
            !($settings['enable_qc_stage'] ?? false)
            && $booking->getLifecycleStatus()->getStage() !== 'qc_repair'
        ) {
            throw new \Exception('QC management is disabled in booking settings');
        }
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
     * Check if allocation requires approval before dispatch.
     *
     * Rules (all configurable via website_settings):
     * 1. Global setting "allocation_requires_approval" forces approval on every booking.
     * 2. Corporate bookings require approval if their corporate account mandates it.
     * 3. Bookings with pricing overrides require approval when "allocation_approval_on_override" is enabled.
     * 4. Bookings above a certain value require approval when "allocation_approval_amount_threshold" is set.
     */
    private function checkAllocationRequiresApproval(Booking $booking): bool
    {
        // 1. Global override
        $globalRequired = $this->normalizeSettingBoolean(
            WebsiteSetting::getValue('allocation_requires_approval', 'false'),
            false
        );
        if ($globalRequired) {
            return true;
        }

        // 2. Corporate account policy
        if ($booking->is_corporate_booking && $booking->corporate_account_id) {
            $corporate = $booking->corporateAccount;
            if ($corporate && !empty($corporate->require_booking_approval)) {
                // Coordinator exemption
                if (!empty($corporate->approval_exempt_coordinators)) {
                    $userId = \Illuminate\Support\Facades\Auth::id();
                    $exempted = is_array($corporate->approval_exempt_coordinators)
                        ? in_array($userId, $corporate->approval_exempt_coordinators)
                        : false;
                    if (!$exempted) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }

        // 3. Pricing override triggers approval
        $approvalOnOverride = $this->normalizeSettingBoolean(
            WebsiteSetting::getValue('allocation_approval_on_override', 'false'),
            false
        );
        if ($approvalOnOverride && $booking->has_overrides) {
            return true;
        }

        // 4. Amount threshold
        $amountThreshold = (float) (WebsiteSetting::getValue('allocation_approval_amount_threshold', 0) ?? 0);
        if ($amountThreshold > 0) {
            $bookingAmount = $booking->total_actual ?? $booking->total_estimated ?? 0;
            if ($bookingAmount >= $amountThreshold) {
                return true;
            }
        }

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

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'booking_lifecycle_transitioned',
            'entity' => 'Booking',
            'entity_id' => $booking->id,
            'timestamp' => Carbon::now('UTC'),
            'details' => [
                'booking_number' => $booking->booking_number,
                'booking_item_id' => $data['booking_item_id'] ?? null,
                'from_status' => $fromStatus?->value,
                'to_status' => $toStatus->value,
                'transition_source' => $data['source'] ?? 'booking_lifecycle',
            ],
        ]);

        $this->notifyCustomerOfLifecycleTransition($booking, $toStatus);
    }

    private function notifyCustomerOfLifecycleTransition(
        Booking $booking,
        BookingLifecycleStatus $status
    ): void {
        $notification = match ($status) {
            BookingLifecycleStatus::BOOKING_CONFIRMED => [
                'Booking confirmed',
                'Your booking is confirmed and scheduled.',
                'booking_confirmed',
            ],
            BookingLifecycleStatus::ALLOCATION_ASSIGNED => [
                'Vehicle assigned',
                'A vehicle has been assigned to your booking.',
                'booking_vehicle_assigned',
            ],
            BookingLifecycleStatus::DISPATCH_OUT => [
                'Booking dispatched',
                'Your vehicle or driver has been dispatched.',
                'booking_dispatched',
            ],
            BookingLifecycleStatus::ONGOING_ACTIVE => [
                'Trip started',
                'Your trip is now in progress.',
                'booking_trip_started',
            ],
            BookingLifecycleStatus::RETURN_SCHEDULED => [
                'Vehicle return reminder',
                'The vehicle return stage is ready. Please follow the agreed return arrangements.',
                'booking_return_scheduled',
            ],
            BookingLifecycleStatus::RETURN_COMPLETED,
            BookingLifecycleStatus::RETURN_LATE => [
                'Vehicle returned',
                'The vehicle return has been recorded and final checks are in progress.',
                'booking_returned',
            ],
            BookingLifecycleStatus::COMPLETED => [
                'Booking completed',
                'Your booking has been completed.',
                'booking_completed',
            ],
            default => null,
        };

        if (!$notification) {
            return;
        }

        try {
            $booking->loadMissing('customer.user');
            $recipient = $booking->customer?->user;
            if ($recipient) {
                $recipient->notify(new BookingLifecycleNotification(
                    $booking,
                    $notification[0],
                    $notification[1],
                    $notification[2],
                ));
            }
        } catch (\Throwable $exception) {
            Log::warning('Customer lifecycle notification could not be queued', [
                'booking_id' => $booking->id,
                'lifecycle_status' => $status->value,
                'error' => $exception->getMessage(),
            ]);
        }
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

        $workflowSettings = $this->getLifecycleWorkflowSettings();
        $currentStatus = $booking->getLifecycleStatus();
        $nextActions = $booking->getNextActions();
        $currentStage = $currentStatus->getStage();

        // Do not strand bookings already inside an optional stage when settings change.
        if ($currentStage === 'return') {
            $workflowSettings['enable_return_stage'] = true;
        } elseif ($currentStage === 'qc_repair') {
            $workflowSettings['enable_return_stage'] = true;
            $workflowSettings['enable_qc_stage'] = true;
        }

        if (
            $currentStatus === BookingLifecycleStatus::ONGOING_ACTIVE
            && !($workflowSettings['enable_return_stage'] ?? false)
        ) {
            $nextActions = [[
                'status' => BookingLifecycleStatus::COMPLETED->value,
                'display_name' => BookingLifecycleStatus::COMPLETED->getDisplayName(),
                'action' => 'Complete Trip',
                'color' => BookingLifecycleStatus::COMPLETED->getColor(),
            ]];
        } elseif (
            in_array($currentStatus, [BookingLifecycleStatus::RETURN_COMPLETED, BookingLifecycleStatus::RETURN_LATE], true)
            && !($workflowSettings['enable_qc_stage'] ?? false)
        ) {
            $nextActions = [[
                'status' => BookingLifecycleStatus::COMPLETED->value,
                'display_name' => BookingLifecycleStatus::COMPLETED->getDisplayName(),
                'action' => 'Complete Booking',
                'color' => BookingLifecycleStatus::COMPLETED->getColor(),
            ]];
        }

        $lifecycleContract = $this->getLifecycleContract($booking, $context['booking_item_id']);
        $lifecycleHistory = AuditLog::query()
            ->with('user:id,first_name,last_name')
            ->where('entity', 'Booking')
            ->where('entity_id', $booking->id)
            ->where('action', 'booking_lifecycle_transitioned')
            ->latest('timestamp')
            ->limit(100)
            ->get(['id', 'user_id', 'timestamp', 'details'])
            ->map(static fn (AuditLog $log): array => [
                'id' => $log->id,
                'actor_id' => $log->user_id,
                'actor_name' => $log->user
                    ? trim((string) $log->user->first_name . ' ' . (string) $log->user->last_name)
                    : null,
                'timestamp' => $log->timestamp,
                'booking_item_id' => data_get($log->details, 'booking_item_id'),
                'from_status' => data_get($log->details, 'from_status'),
                'to_status' => data_get($log->details, 'to_status'),
                'source' => data_get($log->details, 'transition_source'),
            ])
            ->values()
            ->all();

        return [
            'booking' => $booking,
            'selected_booking_item_id' => $context['booking_item_id'],
            'selected_trip_number' => $context['trip_number'],
            'selected_booking_item' => $context['booking_item'],
            'lifecycle_contract' => $lifecycleContract,
            'allowed_actions' => $lifecycleContract['allowed_actions'],
            'blocking_reasons' => $lifecycleContract['blocking_reasons'],
            'current_status' => [
                'value' => $currentStatus->value,
                'stage' => $currentStatus->getStage(),
                'display_name' => $currentStatus->getDisplayName(),
                'label' => $currentStatus->getDisplayName(),
                'color' => $currentStatus->getColor(),
                'icon' => $this->getStatusIcon($currentStatus),
                'primaryAction' => $this->getPrimaryActionText($currentStatus, $workflowSettings),
            ],
            'next_actions' => $nextActions,
            'stage_progress' => $this->getStageProgress($booking, $workflowSettings),
            'timeline' => $this->getLifecycleTimeline($booking),
            'lifecycle_history' => $lifecycleHistory,
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
     * Canonical lifecycle contract for API consumers.
     */
    public function getLifecycleContract(Booking $booking, ?string $bookingItemId = null): array
    {
        $booking->loadMissing([
            'dispatch',
            'qc',
            'bookingItems.serviceType',
            'bookingItems.vehicle',
            'bookingItems.driver.user',
        ]);

        $context = $this->resolveLifecycleContext($booking, $bookingItemId);
        $currentStatus = $booking->getLifecycleStatus();
        $workflowSettings = $this->getLifecycleWorkflowSettings();

        if ($currentStatus->getStage() === 'return') {
            $workflowSettings['enable_return_stage'] = true;
        } elseif ($currentStatus->getStage() === 'qc_repair') {
            $workflowSettings['enable_return_stage'] = true;
            $workflowSettings['enable_qc_stage'] = true;
        }

        $driverAssignment = $this->latestDriverAssignment(
            (string) $booking->id,
            $context['driver_id'] ? (string) $context['driver_id'] : null,
            $context['booking_item_id'] ? (string) $context['booking_item_id'] : null
        );

        [$allowedActions, $blockingReasons] = $this->resolveAllowedLifecycleActions(
            $booking,
            $context,
            $currentStatus,
            $workflowSettings
        );

        return [
            'booking_status' => (string) ($booking->status ?? ''),
            'lifecycle_status' => $currentStatus->value,
            'dispatch_status' => $this->enumValue($booking->dispatch?->dispatch_status),
            'qc_status' => $this->enumValue($booking->qc?->qc_status),
            'driver_trip_phase' => $this->enumValue($driverAssignment?->trip_phase),
            'approval_status' => $this->resolveApprovalStatus($booking),
            'payment_collection_status' => $booking->payment_collection_status ?? $booking->payment_status ?? 'pending',
            'allowed_actions' => $allowedActions,
            'blocking_reasons' => $blockingReasons,
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_null($value) ? null : (string) $value;
    }

    private function resolveApprovalStatus(Booking $booking): string
    {
        if (!($booking->requires_approval ?? false) && ($booking->status ?? null) !== 'pending_approval') {
            return 'not_required';
        }

        return $booking->approval_status ?: (($booking->status ?? null) === 'pending_approval' ? 'pending' : 'required');
    }

    private function latestDriverAssignment(string $bookingId, ?string $driverId = null, ?string $bookingItemId = null): ?DriverAssignment
    {
        return DriverAssignment::where('booking_id', $bookingId)
            ->when($driverId, fn ($query) => $query->where('driver_id', $driverId))
            ->when($bookingItemId && Schema::hasColumn('driver_assignments', 'booking_item_id'), fn ($query) => $query->where('booking_item_id', $bookingItemId))
            ->orderByDesc('updated_at')
            ->first();
    }

    private function resolveAllowedLifecycleActions(
        Booking $booking,
        array $context,
        BookingLifecycleStatus $currentStatus,
        array $workflowSettings
    ): array {
        $actions = [];
        $blockingReasons = [];
        $hasVehicle = !empty($context['vehicle_id']);
        $hasDriver = !empty($context['driver_id']);
        $isSelfDriven = (bool) $context['is_self_driven'];

        if (($booking->status ?? null) === 'pending_approval' || $this->resolveApprovalStatus($booking) === 'pending') {
            $actions[] = 'approve_booking';
            $actions[] = 'reject_booking';
            $blockingReasons[] = 'Approval is required before dispatch';
        }

        if (in_array($currentStatus, [
            BookingLifecycleStatus::BOOKING_CONFIRMED,
            BookingLifecycleStatus::ALLOCATION_PENDING,
            BookingLifecycleStatus::ALLOCATION_CONFLICTS,
        ], true)) {
            $actions[] = 'assign_vehicle';
            if (!$isSelfDriven) {
                $actions[] = 'assign_driver';
            }
        }

        if (in_array($currentStatus, [
            BookingLifecycleStatus::ALLOCATION_ASSIGNED,
            BookingLifecycleStatus::ALLOCATION_APPROVED,
            BookingLifecycleStatus::DISPATCH_READY,
        ], true)) {
            if ($hasVehicle && ($isSelfDriven || $hasDriver)) {
                $actions[] = 'dispatch_vehicle';
            } else {
                if (!$hasVehicle) {
                    $blockingReasons[] = 'Vehicle required before dispatch';
                }
                if (!$isSelfDriven && !$hasDriver) {
                    $blockingReasons[] = 'Driver required before dispatch';
                }
            }
        }

        if (in_array($currentStatus, [
            BookingLifecycleStatus::DISPATCH_OUT,
            BookingLifecycleStatus::ONGOING_ACTIVE,
            BookingLifecycleStatus::ONGOING_REPLACEMENT_NEEDED,
            BookingLifecycleStatus::ONGOING_BREAKDOWN,
        ], true)) {
            if ($workflowSettings['enable_return_stage'] ?? false) {
                $actions[] = 'process_return';
            } else {
                $actions[] = 'complete_booking';
            }
        }

        if (in_array($currentStatus, [
            BookingLifecycleStatus::RETURN_COMPLETED,
            BookingLifecycleStatus::RETURN_LATE,
            BookingLifecycleStatus::QC_PENDING,
        ], true)) {
            if ($workflowSettings['enable_qc_stage'] ?? false) {
                $actions[] = 'start_qc_inspection';
            } else {
                $actions[] = 'complete_booking';
            }
        }

        if ($currentStatus === BookingLifecycleStatus::QC_IN_PROGRESS) {
            $actions[] = 'complete_qc_inspection';
        }

        if (in_array($currentStatus, [
            BookingLifecycleStatus::QC_ISSUES_FOUND,
            BookingLifecycleStatus::QC_REPAIR_NEEDED,
        ], true)) {
            $actions[] = 'complete_repairs';
            $blockingReasons[] = 'QC repair must be completed';
        }

        if ($currentStatus === BookingLifecycleStatus::QC_COMPLETED) {
            $actions[] = 'complete_booking';
        }

        if ($currentStatus === BookingLifecycleStatus::COMPLETED) {
            $blockingReasons[] = 'Booking is already completed';
        } elseif ($currentStatus === BookingLifecycleStatus::CANCELLED) {
            $blockingReasons[] = 'Booking is cancelled';
        }

        return [array_values(array_unique($actions)), array_values(array_unique($blockingReasons))];
    }

    /**
     * Get stage progress for UI
     */
    private function getStageProgress(Booking $booking, array $workflowSettings): array
    {
        $currentStatus = $booking->getLifecycleStatus();
        $stages = [
            'inquiry' => ['completed' => false, 'current' => false],
            'booking' => ['completed' => false, 'current' => false],
            'allocation_dispatch' => ['completed' => false, 'current' => false],
            'ongoing' => ['completed' => false, 'current' => false],
        ];
        if ($workflowSettings['enable_return_stage'] ?? false) {
            $stages['return'] = ['completed' => false, 'current' => false];
        }
        if ($workflowSettings['enable_qc_stage'] ?? false) {
            $stages['qc_repair'] = ['completed' => false, 'current' => false];
        }
        $stages['final'] = ['completed' => false, 'current' => false];

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
            'timestamp' => $this->toUtcIsoTimestamp($booking->created_at),
            'user' => $booking->createdBy?->name ?? 'System',
            'status' => 'inquiry',
        ];

        // Booking confirmed
        if ($booking->confirmed_at) {
            $timeline[] = [
                'event' => 'Booking Confirmed',
                'timestamp' => $this->toUtcIsoTimestamp($booking->confirmed_at),
                'user' => $booking->updatedBy?->name ?? 'System',
                'status' => 'confirmed',
            ];
        }

        // Vehicle dispatched
        if ($booking->dispatch?->dispatched_at) {
            $timeline[] = [
                'event' => 'Vehicle Dispatched',
                'timestamp' => $this->toUtcIsoTimestamp($booking->dispatch->dispatched_at),
                'user' => $booking->dispatch->dispatchedBy?->name ?? 'System',
                'status' => 'dispatched',
            ];
        }

        // Vehicle returned
        if ($booking->dispatch?->actual_return_at) {
            $timeline[] = [
                'event' => 'Vehicle Returned',
                'timestamp' => $this->toUtcIsoTimestamp($booking->dispatch->actual_return_at),
                'user' => $booking->dispatch->returnedBy?->name ?? 'System',
                'status' => 'returned',
            ];
        }

        // QC completed
        if ($booking->qc?->inspection_completed_at) {
            $timeline[] = [
                'event' => 'QC Inspection Completed',
                'timestamp' => $this->toUtcIsoTimestamp($booking->qc->inspection_completed_at),
                'user' => $booking->qc->inspector?->name ?? 'System',
                'status' => 'qc_completed',
            ];
        }

        // Booking completed
        if ($booking->completed_at) {
            $timeline[] = [
                'event' => 'Booking Completed',
                'timestamp' => $this->toUtcIsoTimestamp($booking->completed_at),
                'user' => $booking->updatedBy?->name ?? 'System',
                'status' => 'completed',
            ];
        }

        return array_reverse($timeline); // Most recent first
    }

    private function toUtcIsoTimestamp($value): ?string
    {
        if (!$value) {
            return null;
        }

        $timestamp = $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse((string) $value);

        return $timestamp->utc()->toIso8601String();
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
    private function getPrimaryActionText(
        BookingLifecycleStatus $status,
        ?array $workflowSettings = null
    ): string
    {
        $workflowSettings ??= $this->getLifecycleWorkflowSettings();

        if (
            $status === BookingLifecycleStatus::ONGOING_ACTIVE
            && !($workflowSettings['enable_return_stage'] ?? false)
        ) {
            return 'Complete Trip';
        }

        if (
            in_array($status, [BookingLifecycleStatus::RETURN_COMPLETED, BookingLifecycleStatus::RETURN_LATE], true)
            && !($workflowSettings['enable_qc_stage'] ?? false)
        ) {
            return 'Complete Booking';
        }

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
        $maintenanceQuery = DB::table('vehicle_maintenance_records')
            ->where('vehicle_id', $vehicleId)
            ->where('status', 'in_progress')
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('performed_date', [$start, $end])
                    ->orWhere('performed_date', '<=', $end);
            });

        if (Schema::hasColumn('vehicle_maintenance_records', 'completed_date')) {
            $maintenanceQuery->whereNull('completed_date');
        }

        $maintenanceConflicts = $maintenanceQuery->exists();

        // Check booking conflicts through booking_items.
        // Use the bookings.status column (lifecycle_status is a computed property, not a DB column).
        $bookingConflicts = DB::table('booking_items')
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->where('booking_items.vehicle_id', $vehicleId)
            ->whereNotIn('bookings.status', ['completed', 'cancelled', 'inquiry_cancelled'])
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
        $selectColumns = [
            'vmr.id',
            'vmr.vehicle_id',
            'v.name as vehicle_name',
            'v.license_plate',
            'vms.type as maintenance_type',
            'vmr.performed_date as started_at',
            'vmr.notes',
        ];

        if (Schema::hasColumn('vehicle_maintenance_records', 'estimated_completion_date')) {
            $selectColumns[] = 'vmr.estimated_completion_date';
        }

        $blocksQuery = DB::table('vehicle_maintenance_records as vmr')
            ->join('vehicles as v', 'vmr.vehicle_id', '=', 'v.id')
            ->leftJoin('vehicle_maintenance_schedules as vms', 'vmr.schedule_id', '=', 'vms.id')
            ->where('vmr.status', 'in_progress');

        if (Schema::hasColumn('vehicle_maintenance_records', 'completed_date')) {
            $blocksQuery->whereNull('vmr.completed_date');
        }

        $blocks = $blocksQuery
            ->select($selectColumns)
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

            // Ensure booking is completed (getLifecycleStatus() derives status from the status column).
            if ($booking->getLifecycleStatus() !== BookingLifecycleStatus::COMPLETED) {
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
