<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingDispatch;
use App\Models\Booking\BookingQC;
use App\Models\DriverAssignment;
use App\Models\Driver\DriverSession;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\BookingPriceAdjustmentHistory;
use App\Models\Vehicle\VehiclePricing\PriceAdjustment;
use App\Models\Vehicle\Vehicle;
use App\Models\Finance\FinancialSettlementItem;
use App\Services\Driver\NotificationTriggerService;
use App\Services\Pricing\FinalPricingTelemetryResolver;
use App\Services\Pricing\PricingContextPolicyService;
use App\Models\AuditLog;
use App\Notifications\BookingLifecycleNotification;
use App\Services\InvoiceService;
use App\Models\User;
use App\Enums\BookingLifecycleStatus;
use App\Enums\DispatchStatus;
use App\Enums\TripPhase;
use App\Enums\QCStatus;
use App\Enums\VehicleAvailabilityStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
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
    protected ?BookingOperationsHealthMonitor $healthMonitor;

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
        FinalPricingTelemetryResolver $finalPricingTelemetryResolver,
        ?BookingOperationsHealthMonitor $healthMonitor = null,
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
        $this->healthMonitor = $healthMonitor;
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

    /**
     * Resolve the dispatch owned by the selected booking item. A null-item
     * legacy dispatch remains a supported fallback for a single-item booking,
     * but is never guessed for a multi-item booking.
     */
    private function resolveItemDispatch(Booking $booking, array $context, bool $lock = false): ?BookingDispatch
    {
        $query = BookingDispatch::query()
            ->where('booking_id', $booking->id)
            ->whereNull('deleted_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        $bookingItemId = $context['booking_item_id'] ?? null;
        if ($bookingItemId) {
            $itemDispatch = (clone $query)
                ->where('booking_item_id', $bookingItemId)
                ->first();
            if ($itemDispatch) {
                return $itemDispatch;
            }
        }

        if ($booking->bookingItems->count() <= 1) {
            return (clone $query)->whereNull('booking_item_id')->first();
        }

        if ((clone $query)->whereNull('booking_item_id')->exists()) {
            throw new \DomainException(
                'A legacy booking-level dispatch cannot be assigned automatically to a multi-item booking. Select or create the item dispatch explicitly.'
            );
        }

        return null;
    }

    /**
     * Resolve the QC record owned by the selected item. Legacy null-item QC is
     * accepted only for a single-item booking; an ambiguous multi-item record
     * is never guessed.
     */
    private function resolveItemQc(Booking $booking, array $context, bool $lock = false): ?BookingQC
    {
        $query = BookingQC::query()
            ->where('booking_id', $booking->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $bookingItemId = $context['booking_item_id'] ?? null;
        if ($bookingItemId) {
            $itemQc = (clone $query)
                ->where('booking_item_id', $bookingItemId)
                ->first();
            if ($itemQc) {
                return $itemQc;
            }
        }

        if ($booking->bookingItems->count() <= 1) {
            return (clone $query)->whereNull('booking_item_id')->first();
        }

        if ((clone $query)->whereNull('booking_item_id')->exists()) {
            throw new \DomainException(
                'A legacy booking-level QC record cannot be assigned automatically to a multi-item booking.'
            );
        }

        return null;
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
            $createdFrom = strtolower(trim((string) ($data['created_from'] ?? 'system')));
            $bookingSource = $data['booking_source'] ?? match (true) {
                in_array($createdFrom, ['public', 'website', 'web', 'online', 'customer', 'customer_portal', 'guest'], true) => 'public',
                in_array($createdFrom, ['corporate', 'corporate_portal', 'employee_portal'], true) => 'corporate',
                default => 'internal',
            };

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
                'booking_source' => $bookingSource,
                'created_from' => $createdFrom,
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
                    'assigned_from' => $this->bookingDateTime($booking->from_date, $booking->from_time),
                    'assigned_to' => $this->bookingDateTime($booking->to_date, $booking->to_time),
                    'assignment_type' => $assignmentData['assignment_type'] ?? 'primary',
                    'requires_approval' => $assignmentData['requires_approval'] ?? false,
                ]);
            }

            if (isset($assignmentData['driver_id'])) {
                $this->assignmentService->createDriverAssignment([
                    'booking_id' => $booking->id,
                    'driver_id' => $assignmentData['driver_id'],
                    'assigned_from' => $this->bookingDateTime($booking->from_date, $booking->from_time),
                    'assigned_to' => $this->bookingDateTime($booking->to_date, $booking->to_time),
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
            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['bookingItems.vehicle', 'bookingItems.driver.user'])
                ->findOrFail($bookingId);
            $bookingItemId = $dispatchData['booking_item_id'] ?? null;
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $this->assertFinancialLifecycleReady($booking, 'dispatch');
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);

            if (!$context['vehicle_id']) {
                throw new \Exception('Vehicle must be assigned before dispatch preparation');
            }

            if ($this->resolveItemDispatch($booking, $context, true)) {
                throw new \DomainException('A dispatch record already exists for the selected booking item');
            }

            // Create dispatch record
            $dispatch = $booking->dispatches()->create([
                'booking_item_id' => $context['booking_item_id'],
                'vehicle_id' => $context['vehicle_id'],
                'driver_id' => $context['driver_id'],
                'dispatch_status' => DispatchStatus::READY_FOR_DISPATCH,
                'expected_return_at' => $context['booking_item']?->to_date ?? $booking->to_date,
                'is_self_driven' => $context['is_self_driven'],
                'dispatch_notes' => $dispatchData['notes'] ?? null,
                'created_user_id' => Auth::id(),
            ]);

            if ($booking->bookingItems->count() === 1) {
                $booking->transitionToStatus(BookingLifecycleStatus::DISPATCH_READY, Auth::id(), $dispatchData);
            }

            $this->logLifecycleTransition(
                $booking,
                BookingLifecycleStatus::ALLOCATION_APPROVED,
                BookingLifecycleStatus::DISPATCH_READY,
                array_merge($dispatchData, ['booking_item_id' => $context['booking_item_id']])
            );

            return $dispatch;
        });
    }

    /**
     * Dispatch vehicle
     */
    public function dispatchVehicle(string $bookingId, array $dispatchData): BookingDispatch
    {
        return DB::transaction(function () use ($bookingId, $dispatchData) {
            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['dispatches', 'qc.repairItems', 'bookingItems.vehicle', 'bookingItems.driver.user'])
                ->findOrFail($bookingId);
            $bookingItemId = $dispatchData['booking_item_id'] ?? null;
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $this->assertFinancialLifecycleReady($booking, 'dispatch');
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $dispatch = $this->resolveItemDispatch($booking, $context, true);
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
                $this->resetBookingItemAfterCompletedHireForRedispatch($context['booking_item']);
            }

            if (!$dispatch) {
                $dispatch = $booking->dispatches()->create([
                    'booking_item_id' => $context['booking_item_id'],
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
            $vehicleUpdates = ['availability_status' => VehicleAvailabilityStatus::ON_HIRE->value];
            $dispatchMileage = (int) ($dispatchData['mileage'] ?? 0);
            if ($dispatchMileage > (int) ($vehicle->current_mileage ?? 0)) {
                $vehicleUpdates['current_mileage'] = $dispatchMileage;
            }
            $vehicle->update($vehicleUpdates);

            if (!$isRepeatDispatch) {
                if ($booking->bookingItems->count() === 1) {
                    $booking->transitionToStatus(BookingLifecycleStatus::DISPATCH_OUT, Auth::id(), $dispatchData);
                }
                $this->logLifecycleTransition(
                    $booking,
                    BookingLifecycleStatus::DISPATCH_READY,
                    BookingLifecycleStatus::DISPATCH_OUT,
                    array_merge($dispatchData, ['booking_item_id' => $context['booking_item_id']])
                );
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

    private function resetBookingItemAfterCompletedHireForRedispatch(?BookingItem $bookingItem): void
    {
        if (!$bookingItem) {
            return;
        }

        $bookingItem->update([
            'returned_at' => null,
            'final_priced_at' => null,
            'completed_at' => null,
            'status' => 'confirmed',
            'lifecycle_data' => array_merge(
                is_array($bookingItem->lifecycle_data) ? $bookingItem->lifecycle_data : [],
                ['redispatched_at' => Carbon::now('UTC')->toIso8601String()]
            ),
        ]);
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

        $assignedFrom = $this->bookingDateTime(
            $selectedItem?->from_date ?? $booking->from_date ?? now(),
            $selectedItem?->from_time ?? $booking->from_time
        );
        $assignedTo = $this->bookingDateTime(
            $selectedItem?->to_date ?? $booking->to_date ?? $assignedFrom,
            $selectedItem?->to_time ?? $booking->to_time
        );

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
        $minutesActive = $dispatch?->dispatched_at
            ? (int) $dispatch->dispatched_at->diffInMinutes(Carbon::now('UTC'))
            : 0;

        return [
            'booking' => $booking,
            'dispatch' => $dispatch,
            'is_overdue' => $isOverdue,
            'minutes_active' => $minutesActive,
            'hours_active' => $minutesActive / 60,
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
            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['bookingItems', 'dispatches'])
                ->findOrFail($bookingId);
            $this->assertReturnStageAvailable($booking);
            $bookingItemId = $returnData['booking_item_id'] ?? null;
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $dispatch = $this->resolveItemDispatch($booking, $context, true);

            if ($dispatch) {
                $dispatch->update([
                    'expected_return_at' => $returnData['expected_return_at']
                        ?? $context['booking_item']?->to_date
                        ?? $booking->to_date,
                    'return_notes' => $returnData['notes'] ?? null,
                ]);
            }

            if ($booking->bookingItems->count() === 1) {
                $booking->transitionToStatus(BookingLifecycleStatus::RETURN_SCHEDULED, Auth::id(), $returnData);
            }

            $this->logLifecycleTransition(
                $booking,
                BookingLifecycleStatus::ONGOING_ACTIVE,
                BookingLifecycleStatus::RETURN_SCHEDULED,
                array_merge($returnData, ['booking_item_id' => $context['booking_item_id']])
            );

            return $booking;
        });
    }

    /**
     * Process return
     */
    public function processReturn(string $bookingId, array $returnData): BookingDispatch
    {
        return DB::transaction(function () use ($bookingId, $returnData) {
            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['dispatches', 'bookingItems'])
                ->findOrFail($bookingId);
            $bookingItemId = $returnData['booking_item_id'] ?? null;
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $bookingItem = $context['booking_item']
                ? BookingItem::query()
                    ->whereKey($context['booking_item']->id)
                    ->where('booking_id', $booking->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;
            $context['booking_item'] = $bookingItem;
            $dispatch = $this->resolveItemDispatch($booking, $context, true);
            $isMultiItem = $booking->bookingItems->count() > 1;
            $fromStatus = $booking->getLifecycleStatus();
            $actorUserId = $returnData['returned_by']
                ?? Auth::id()
                ?? $booking->updated_user_id
                ?? $booking->created_user_id
                ?? $dispatch?->dispatched_by;

            if (!$dispatch) {
                throw new \Exception('Dispatch record not found');
            }

            if ($bookingItem?->returned_at && $dispatch->isReturned()) {
                if ($bookingItem->completed_at) {
                    $newlyCompleted = $this->finalizeMultiItemBookingIfReady(
                        $booking,
                        (string) ($actorUserId ?: $dispatch->returned_by ?: $dispatch->dispatched_by),
                        $returnData
                    );
                    if ($newlyCompleted) {
                        $this->runAggregateCompletionEffects($booking->fresh());
                    }
                }

                return $dispatch;
            }

            $this->assertReturnStageAvailable($booking);
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

            if ($bookingItem) {
                $returnedAt = $dispatch->fresh()->actual_return_at ?? Carbon::now('UTC');
                $bookingItem->update([
                    'returned_at' => $returnedAt,
                    'final_priced_at' => Carbon::now('UTC'),
                    'lifecycle_data' => array_merge(
                        is_array($bookingItem->lifecycle_data) ? $bookingItem->lifecycle_data : [],
                        [
                            'stage' => 'returned',
                            'dispatch_id' => (string) $dispatch->id,
                            'returned_at' => $returnedAt->toIso8601String(),
                            'final_pricing_trigger' => !empty($returnData['completed_by_driver'])
                                ? 'driver_mobile_return'
                                : 'system_return',
                        ]
                    ),
                ]);
            }

            // Post-trip maintenance trigger check (non-blocking)
            $vehicleId = $dispatch->vehicle_id ?: $context['vehicle_id'];
            if ($vehicleId) {
                $mileageIn = (int) ($returnData['mileage_in'] ?? $dispatch->mileage_in ?? 0);
                if ($mileageIn > 0) {
                    try {
                        $vehicleForMaintenance = Vehicle::find($vehicleId);
                        if ($vehicleForMaintenance) {
                            if ($mileageIn > (int) ($vehicleForMaintenance->current_mileage ?? 0)) {
                                $vehicleForMaintenance->update(['current_mileage' => $mileageIn]);
                            }
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
            $workflowSettings = $this->getLifecycleWorkflowSettings();
            $forceSkipQc = (bool) ($returnData['skip_qc'] ?? false) || (bool) ($returnData['completed_by_driver'] ?? false);
            $isQcEnabled = !$forceSkipQc && (bool) ($workflowSettings['enable_qc_stage'] ?? true);

            if ($isQcEnabled) {
                $vehicle = Vehicle::findOrFail($vehicleId);
                $vehicle->update(['availability_status' => VehicleAvailabilityStatus::UNAVAILABLE_QC->value]);

                $lifecycleStatus = $dispatch->isOverdue()
                    ? BookingLifecycleStatus::RETURN_LATE
                    : BookingLifecycleStatus::RETURN_COMPLETED;

                if ($isMultiItem) {
                    $this->logItemLifecycleEvent(
                        $booking,
                        $bookingItem,
                        'returned',
                        array_merge($returnData, ['qc_required' => true])
                    );
                } else {
                    $booking->transitionToStatus($lifecycleStatus, (string) $actorUserId, $returnData);

                    $this->logLifecycleTransition(
                        $booking,
                        $fromStatus,
                        $lifecycleStatus,
                        $returnData
                    );
                }
            } else {
                // Skip QC stage for businesses that disable it in website settings.
                $this->assertFinancialLifecycleReady($booking, 'completion');
                $this->makeVehicleAvailable($vehicleId);
                $completionMeta = array_merge(
                    $returnData,
                    [
                        'qc_skipped' => true,
                        'maintenance_stage_enabled' => (bool) ($workflowSettings['enable_maintenance_stage'] ?? true),
                    ]
                );
                if ($isMultiItem) {
                    $this->markBookingItemCompleted($bookingItem, $completionMeta);
                    $this->closeDriverAssignmentsForCompletion(
                        (string) $booking->id,
                        $bookingItem?->id ? (string) $bookingItem->id : null,
                        $bookingItem?->returned_at ?? $dispatch->fresh()->actual_return_at ?? Carbon::now('UTC'),
                        $completionMeta
                    );
                    $this->logItemLifecycleEvent($booking, $bookingItem, 'completed', $completionMeta);
                    $newlyCompleted = $this->finalizeMultiItemBookingIfReady(
                        $booking,
                        (string) $actorUserId,
                        $completionMeta
                    );
                    if ($newlyCompleted) {
                        $this->runAggregateCompletionEffects($booking->fresh());
                    }

                    return $dispatch;
                }

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

                $this->markBookingItemCompleted($bookingItem?->fresh(), $completionMeta);
                $this->closeDriverAssignmentsForCompletion(
                    (string) $booking->id,
                    $bookingItem?->id ? (string) $bookingItem->id : null,
                    $bookingItem?->returned_at ?? $dispatch->fresh()->actual_return_at ?? Carbon::now('UTC'),
                    $completionMeta
                );

                $this->logLifecycleTransition(
                    $booking,
                    $fromStatus,
                    BookingLifecycleStatus::COMPLETED,
                    array_merge($returnData, ['qc_skipped' => true])
                );

                // Driver-mobile and direct-return completion paths do not pass
                // through completeBooking(), so invoice only after final pricing
                // has been synchronized above.
                if ($this->isPricingPendingReview($finalPricing)) {
                    Log::warning('Invoice deferred: final pricing pending manual review', [
                        'booking_id' => $booking->id,
                    ]);
                } else {
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
            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['dispatches', 'qcs', 'bookingItems'])
                ->findOrFail($bookingId);
            $this->assertQcStageAvailable($booking);
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $dispatch = $this->resolveItemDispatch($booking, $context, true);
            $vehicleId = $dispatch?->vehicle_id ?: $context['vehicle_id'];
            if (!$vehicleId) {
                throw new \Exception('Vehicle must be assigned before QC inspection');
            }
            if (
                $booking->bookingItems->count() > 1
                && !$context['booking_item']?->returned_at
                && !$dispatch?->isReturned()
            ) {
                throw new \DomainException('The selected booking item must be returned before QC can start.');
            }
            $resolvedInspectorId = $inspectorId ?: Auth::id();
            if (!$resolvedInspectorId) {
                throw new \Exception('Inspector is required to start QC inspection');
            }

            // Create or get QC record
            $qc = $this->resolveItemQc($booking, $context, true) ?: $booking->qcs()->create([
                'booking_item_id' => $context['booking_item_id'],
                'vehicle_id' => $vehicleId,
                'dispatch_id' => $dispatch?->id,
                'qc_status' => QCStatus::PENDING,
            ]);

            if ($qc->isInProgress()) {
                return $qc;
            }
            if ($qc->inspection_completed_at || $qc->isCompleted() || $qc->needsRepair()) {
                throw new \DomainException('The selected booking item QC has already progressed beyond inspection start.');
            }

            $qc->startInspection((string) $resolvedInspectorId);

            if ($booking->bookingItems->count() > 1) {
                $this->updateItemQcState($context['booking_item'], $qc, 'qc_in_progress');
                $this->logItemLifecycleEvent($booking, $context['booking_item'], 'qc_in_progress');
            } else {
                $booking->transitionToStatus(BookingLifecycleStatus::QC_IN_PROGRESS, Auth::id());
                $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_PENDING, BookingLifecycleStatus::QC_IN_PROGRESS);
            }

            return $qc->fresh();
        });
    }

    /**
     * Complete QC inspection
     */
    public function completeQCInspection(string $bookingId, array $inspectionData, ?string $bookingItemId = null): BookingQC
    {
        return DB::transaction(function () use ($bookingId, $inspectionData, $bookingItemId) {
            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['dispatches', 'qcs', 'bookingItems'])
                ->findOrFail($bookingId);
            $this->assertQcStageAvailable($booking);
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $qc = $this->resolveItemQc($booking, $context, true);

            if (!$qc) {
                throw new \Exception('QC record not found');
            }

            if ($qc->inspection_completed_at) {
                return $qc;
            }
            if (!$qc->isInProgress()) {
                throw new \DomainException('QC inspection must be started before it can be completed.');
            }

            $qc->completeInspection($inspectionData);
            $qc->refresh();

            // Determine next status based on inspection results
            $nextStatus = $qc->needsRepair()
                ? BookingLifecycleStatus::QC_REPAIR_NEEDED
                : BookingLifecycleStatus::QC_COMPLETED;

            if ($booking->bookingItems->count() > 1) {
                $itemStage = $qc->needsRepair() ? 'qc_repair_needed' : 'qc_completed';
                $this->updateItemQcState($context['booking_item'], $qc, $itemStage);
                $this->logItemLifecycleEvent($booking, $context['booking_item'], $itemStage, $inspectionData);
            } else {
                $booking->transitionToStatus($nextStatus, Auth::id(), $inspectionData);
                $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_IN_PROGRESS, $nextStatus, $inspectionData);
            }

            // If no repair needed, make vehicle available
            $vehicleId = $qc->vehicle_id ?: $booking->vehicle_id;
            if (!$qc->needsRepair() && !$qc->requires_maintenance) {
                if ($vehicleId) {
                    $this->makeVehicleAvailable($vehicleId);
                }
            } elseif (!$qc->needsRepair() && $qc->requires_maintenance) {
                if ($vehicleId) {
                    $vehicle = Vehicle::findOrFail($vehicleId);
                    $vehicle->update([
                        'availability_status' => VehicleAvailabilityStatus::UNAVAILABLE_MAINTENANCE->value,
                    ]);
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
            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['dispatches', 'qcs', 'bookingItems'])
                ->findOrFail($bookingId);
            $this->assertQcStageAvailable($booking);
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $qc = $this->resolveItemQc($booking, $context, true);

            if (!$qc) {
                throw new \Exception('QC record not found');
            }

            if ($qc->isCompleted()) {
                return $qc;
            }
            if (!$qc->needsRepair()) {
                throw new \DomainException('The selected booking item does not have a pending QC repair.');
            }

            $qc->markCompleted();
            $qc->refresh();

            if ($booking->bookingItems->count() > 1) {
                $this->updateItemQcState($context['booking_item'], $qc, 'qc_completed');
                $this->logItemLifecycleEvent($booking, $context['booking_item'], 'qc_completed', $repairData);
            } else {
                $booking->transitionToStatus(BookingLifecycleStatus::QC_COMPLETED, Auth::id(), $repairData);
                $this->logLifecycleTransition($booking, BookingLifecycleStatus::QC_REPAIR_NEEDED, BookingLifecycleStatus::QC_COMPLETED, $repairData);
            }

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
     * Reconcile a persisted driver completion through the canonical booking-item
     * completion path. This repairs legacy rows without completing sibling items.
     */
    public function reconcileCompletedDriverAssignment(string $assignmentId): bool
    {
        $assignment = DriverAssignment::query()
            ->with(['booking.bookingItems', 'booking.dispatches'])
            ->findOrFail($assignmentId);

        if ($assignment->trip_phase?->value !== TripPhase::COMPLETED->value
            || !$assignment->trip_completed_at
            || !$assignment->booking) {
            return false;
        }

        $bookingItems = $assignment->booking->bookingItems->values();
        $bookingItem = $this->resolveCompletedAssignmentBookingItem($assignment, $bookingItems);

        if (!$bookingItem) {
            throw new \DomainException(
                'A completed driver assignment could not be mapped safely to a booking item. '
                .json_encode([
                    'booking_id' => (string) $assignment->booking_id,
                    'stored_booking_item_id' => $assignment->booking_item_id,
                    'candidate_booking_item_ids' => $bookingItems->pluck('id')->map(fn ($id) => (string) $id)->all(),
                ])
            );
        }

        if ((string) $assignment->booking_item_id !== (string) $bookingItem->id) {
            $previousBookingItemId = $assignment->booking_item_id;
            $assignment->forceFill(['booking_item_id' => $bookingItem->id])->save();
            Log::warning('Repaired completed driver assignment booking item link', [
                'assignment_id' => (string) $assignment->id,
                'booking_id' => (string) $assignment->booking_id,
                'previous_booking_item_id' => $previousBookingItemId,
                'booking_item_id' => (string) $bookingItem->id,
            ]);
        }

        if ($bookingItem->completed_at && (string) $bookingItem->status === 'completed') {
            return false;
        }

        $this->completeBooking(
            (string) $assignment->booking_id,
            [
                'activity_source' => 'driver_completion_reconciliation',
                'actual_start_time' => $assignment->trip_started_at?->toIso8601String(),
                'actual_return_time' => $assignment->trip_completed_at->toIso8601String(),
                'actual_distance' => $assignment->total_distance_km !== null
                    ? (float) $assignment->total_distance_km
                    : null,
                'waiting_minutes' => (int) ceil(((int) $assignment->total_waiting_time_seconds) / 60),
                'completed_by_driver' => true,
                'suppress_completion_emails' => true,
            ],
            (string) $bookingItem->id
        );

        return true;
    }

    private function resolveCompletedAssignmentBookingItem(DriverAssignment $assignment, Collection $bookingItems): ?BookingItem
    {
        if ($assignment->booking_item_id) {
            $storedItem = $bookingItems->first(
                fn (BookingItem $item): bool => (string) $item->id === (string) $assignment->booking_item_id
            );
            if ($storedItem) {
                return $storedItem;
            }
        }

        if ($bookingItems->count() === 1) {
            return $bookingItems->first();
        }

        $validItemIds = $bookingItems->pluck('id')->map(fn ($id) => (string) $id);
        $dispatchItemIds = $assignment->booking->dispatches
            ->filter(fn (BookingDispatch $dispatch): bool =>
                (string) $dispatch->driver_id === (string) $assignment->driver_id
                && $dispatch->booking_item_id
                && $validItemIds->contains((string) $dispatch->booking_item_id)
            )
            ->pluck('booking_item_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
        if ($dispatchItemIds->count() === 1) {
            return $bookingItems->first(fn (BookingItem $item): bool => (string) $item->id === $dispatchItemIds->first());
        }

        $driverMatches = $bookingItems->filter(
            fn (BookingItem $item): bool => (string) $item->driver_id === (string) $assignment->driver_id
        )->values();
        if ($driverMatches->count() === 1) {
            return $driverMatches->first();
        }

        $timeMatches = $bookingItems->filter(function (BookingItem $item) use ($assignment): bool {
            $fromMatches = $assignment->assigned_from && $item->from_date
                && $assignment->assigned_from->diffInMinutes($item->from_date) <= 5;
            $toMatches = $assignment->assigned_to && $item->to_date
                && $assignment->assigned_to->diffInMinutes($item->to_date) <= 5;

            return $fromMatches && $toMatches;
        })->values();
        if ($timeMatches->count() === 1) {
            return $timeMatches->first();
        }

        $remainingItems = $bookingItems->filter(
            fn (BookingItem $item): bool => !$item->completed_at
                && !in_array((string) $item->status, ['cancelled', 'rejected'], true)
        )->values();

        return $remainingItems->count() === 1 ? $remainingItems->first() : null;
    }
    
    /**
     * Complete booking lifecycle
     */
    public function completeBooking(string $bookingId, array $completionData = [], ?string $bookingItemId = null): Booking
    {
        return DB::transaction(function () use ($bookingId, $completionData, $bookingItemId) {
            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['dispatches', 'dispatch', 'bookingItems'])
                ->findOrFail($bookingId);
            $this->assertItemSafeLifecycle($booking, $bookingItemId);
            $this->assertFinancialLifecycleReady($booking, 'completion');
            $context = $this->resolveLifecycleContext($booking, $bookingItemId);
            $bookingItem = $context['booking_item']
                ? BookingItem::query()
                    ->whereKey($context['booking_item']->id)
                    ->where('booking_id', $booking->id)
                    ->lockForUpdate()
                    ->firstOrFail()
                : null;
            $context['booking_item'] = $bookingItem;
            $dispatch = $this->resolveItemDispatch($booking, $context, true);
            $workflowSettings = $this->getLifecycleWorkflowSettings();
            $fromStatus = $booking->getLifecycleStatus();
            $actorUserId = Auth::id()
                ?? $booking->updated_user_id
                ?? $booking->created_user_id
                ?? $dispatch?->dispatched_by;

            if (!$actorUserId) {
                throw new \Exception('Authenticated user is required to complete the booking');
            }

            if ($booking->bookingItems->count() > 1) {
                return $this->completeMultiItemBookingItem(
                    $booking,
                    $context,
                    $dispatch,
                    $completionData,
                    $workflowSettings,
                    (string) $actorUserId
                );
            }

            $driverDirectCompletion = (bool) ($completionData['completed_by_driver'] ?? false);
            $isActiveLifecycleStatus = in_array($fromStatus, [
                    BookingLifecycleStatus::DISPATCH_OUT,
                    BookingLifecycleStatus::ONGOING_ACTIVE,
                    BookingLifecycleStatus::ONGOING_REPLACEMENT_NEEDED,
                    BookingLifecycleStatus::ONGOING_BREAKDOWN,
                ], true);
            $hasActiveResolvedDispatch = $dispatch && in_array($dispatch->dispatch_status, [
                DispatchStatus::DISPATCHED,
                DispatchStatus::IN_PROGRESS,
                DispatchStatus::RETURNED,
            ], true);
            $hasCompletedDriverAssignment = $driverDirectCompletion
                && DriverAssignment::query()
                    ->where('booking_id', $booking->id)
                    ->when(
                        $bookingItem?->id && Schema::hasColumn('driver_assignments', 'booking_item_id'),
                        fn ($query) => $query->where('booking_item_id', $bookingItem->id)
                    )
                    ->where('trip_phase', TripPhase::COMPLETED->value)
                    ->whereNotNull('trip_completed_at')
                    ->exists();
            $canSkipReturn = ($driverDirectCompletion || !($workflowSettings['enable_return_stage'] ?? false))
                && ($isActiveLifecycleStatus || $hasActiveResolvedDispatch || $hasCompletedDriverAssignment);
            $canSkipQc = ($driverDirectCompletion || !($workflowSettings['enable_qc_stage'] ?? false))
                && in_array($fromStatus, [
                    BookingLifecycleStatus::RETURN_COMPLETED,
                    BookingLifecycleStatus::RETURN_LATE,
                ], true);

            if ($canSkipReturn && $dispatch) {
                $dispatch->markReturned((string) $actorUserId, [
                    'actual_return_time' => Carbon::now('UTC'),
                    'notes' => 'Trip completed without return management.',
                    'completed_by_driver' => true,
                ]);
            }

            $completionData['final_pricing'] = $this->synchronizeFinalPricing(
                $booking,
                $context,
                $dispatch?->fresh(),
                $completionData,
                'booking_completion'
            );

            if ($bookingItem) {
                $itemTimestamp = Carbon::now('UTC');
                $bookingItem->update([
                    'returned_at' => $bookingItem->returned_at
                        ?? $dispatch?->fresh()->actual_return_at
                        ?? ($canSkipReturn ? $itemTimestamp : null),
                    'final_priced_at' => $itemTimestamp,
                    'lifecycle_data' => array_merge(
                        is_array($bookingItem->lifecycle_data) ? $bookingItem->lifecycle_data : [],
                        [
                            'dispatch_id' => $dispatch?->id,
                            'final_pricing_trigger' => 'booking_completion',
                        ]
                    ),
                ]);
            }

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

                $vehicleId = $dispatch?->vehicle_id ?: $context['vehicle_id'];
                if ($vehicleId) {
                    $this->makeVehicleAvailable($vehicleId);
                }
            } elseif (!$transitioned) {
                throw new \Exception('Booking cannot be completed from its current lifecycle status');
            }

            $this->markBookingItemCompleted($bookingItem?->fresh(), $completionData);
            $this->closeDriverAssignmentsForCompletion(
                $bookingId,
                $bookingItem?->id,
                Carbon::now('UTC'),
                $completionData
            );

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
            if ((bool) ($completionData['suppress_completion_emails'] ?? false)) {
                Log::info('Completion invoice email suppressed', [
                    'booking_id' => $bookingId,
                    'source' => $completionData['activity_source'] ?? 'booking_completion',
                ]);
            } elseif ($this->isPricingPendingReview($completionData['final_pricing'] ?? null)) {
                Log::warning('Invoice deferred: final pricing pending manual review', [
                    'booking_id' => $bookingId,
                ]);
            } else {
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

    /**
     * Administratively end an active hire. Canonical completion is attempted
     * first. If operational telemetry or workflow prerequisites are missing,
     * close the hire without synthesizing pricing and flag it for manual review.
     */
    public function forceCompleteBooking(
        string $bookingId,
        array $completionData = [],
        ?string $bookingItemId = null
    ): Booking {
        $bookingForStatus = Booking::query()
            ->with(['bookingItems', 'dispatch', 'dispatches', 'qc', 'qcs'])
            ->findOrFail($bookingId);
        $contextForStatus = $this->resolveLifecycleContext($bookingForStatus, $bookingItemId);
        $currentStatus = $this->resolveSelectedItemLifecycleStatus(
            $bookingForStatus,
            $contextForStatus['booking_item'],
            $this->resolveItemDispatch($bookingForStatus, $contextForStatus),
            $this->resolveItemQc($bookingForStatus, $contextForStatus)
        );
        if ($currentStatus === BookingLifecycleStatus::COMPLETED) {
            $completedAt = isset($completionData['actual_return_time'])
                ? Carbon::parse($completionData['actual_return_time'])->utc()
                : ($bookingForStatus->completed_at ?? Carbon::now('UTC'));
            // A completed booking is terminal for every assignment. Do not let
            // an older or duplicate assignment/session keep the driver app in
            // an in-progress trip after an administrative force-end retry.
            $this->closeDriverAssignmentsForCompletion(
                $bookingId,
                (string) $bookingForStatus->status === 'completed'
                    ? null
                    : $contextForStatus['booking_item_id'],
                $completedAt,
                $completionData
            );

            return $bookingForStatus->fresh(['bookingItems', 'dispatches']);
        }
        if (!in_array($currentStatus, [
            BookingLifecycleStatus::DISPATCH_OUT,
            BookingLifecycleStatus::ONGOING_ACTIVE,
            BookingLifecycleStatus::ONGOING_REPLACEMENT_NEEDED,
            BookingLifecycleStatus::ONGOING_BREAKDOWN,
            BookingLifecycleStatus::RETURN_SCHEDULED,
            BookingLifecycleStatus::RETURN_OVERDUE,
            BookingLifecycleStatus::RETURN_COMPLETED,
            BookingLifecycleStatus::RETURN_LATE,
            BookingLifecycleStatus::QC_PENDING,
            BookingLifecycleStatus::QC_IN_PROGRESS,
            BookingLifecycleStatus::QC_ISSUES_FOUND,
            BookingLifecycleStatus::QC_REPAIR_NEEDED,
            BookingLifecycleStatus::QC_COMPLETED,
        ], true)) {
            throw new \DomainException('A hire can only be force ended after it has been dispatched.');
        }

        $completionData = array_filter($completionData, static fn ($value) => $value !== null && $value !== '');
        $completionData['force_completion'] = true;
        $completionData['force_completed_by'] = Auth::id();
        $completionData['force_completed_at'] = Carbon::now('UTC')->toIso8601String();

        try {
            $booking = $this->completeBooking($bookingId, $completionData, $bookingItemId);
            $completedAt = isset($completionData['actual_return_time'])
                ? Carbon::parse($completionData['actual_return_time'])->utc()
                : Carbon::now('UTC');
            $this->closeDriverAssignmentsForCompletion(
                $bookingId,
                $bookingItemId,
                $completedAt,
                $completionData
            );

            return $booking->fresh(['bookingItems', 'dispatches']);
        } catch (\Throwable $exception) {
            // Administrative force completion may recover missing operational
            // telemetry, but it must not bypass explicit financial safeguards.
            if ($this->resolveFinancialLifecycleBlockingReasons($bookingForStatus) !== []) {
                throw $exception;
            }
            $isExpectedLifecycleBlock = $exception instanceof \DomainException
                || $exception instanceof \InvalidArgumentException
                || $exception->getMessage() === 'Booking cannot be completed from its current lifecycle status';
            if (!$isExpectedLifecycleBlock) {
                throw $exception;
            }

            return DB::transaction(function () use (
                $bookingId,
                $bookingItemId,
                $completionData,
                $exception
            ) {
                $actorUserId = Auth::id();
                if (!$actorUserId) {
                    throw new \DomainException('Authenticated staff user is required to force end a hire.');
                }

                $booking = Booking::query()
                    ->lockForUpdate()
                    ->with(['bookingItems', 'dispatches'])
                    ->findOrFail($bookingId);
                $context = $this->resolveLifecycleContext($booking, $bookingItemId);
                /** @var BookingItem|null $bookingItem */
                $bookingItem = $context['booking_item'];
                if (!$bookingItem) {
                    throw new \DomainException('The hire has no booking item to complete.');
                }

                $completedAt = isset($completionData['actual_return_time'])
                    ? Carbon::parse($completionData['actual_return_time'])->utc()
                    : Carbon::now('UTC');
                $manualData = array_intersect_key($completionData, array_flip([
                    'actual_start_time', 'actual_return_time', 'actual_distance',
                    'distance_km', 'waiting_minutes', 'notes',
                ]));
                $forceAudit = [
                    'status' => 'manual_review_required',
                    'reason' => 'administrative_force_completion',
                    'canonical_completion_error' => $exception->getMessage(),
                    'manual_data' => $manualData,
                    'actor_user_id' => (string) $actorUserId,
                    'completed_at' => $completedAt->toIso8601String(),
                ];

                $bookingItem->update([
                    'status' => 'completed',
                    'returned_at' => $bookingItem->returned_at ?? $completedAt,
                    'completed_at' => $completedAt,
                    'final_priced_at' => null,
                    'lifecycle_data' => array_merge(
                        is_array($bookingItem->lifecycle_data) ? $bookingItem->lifecycle_data : [],
                        ['force_completion' => $forceAudit]
                    ),
                ]);

                $dispatch = $this->resolveItemDispatch($booking, $context, false);
                if ($dispatch && !$dispatch->isReturned()) {
                    $dispatch->update([
                        'dispatch_status' => DispatchStatus::RETURNED,
                        'actual_return_at' => $completedAt,
                        'returned_by' => $actorUserId,
                        'return_notes' => $completionData['notes'] ?? 'Administratively force ended.',
                    ]);
                }

                $assignments = DriverAssignment::query()
                    ->where('booking_id', $booking->id)
                    ->when($bookingItemId, fn ($query) => $query->where('booking_item_id', $bookingItemId))
                    ->where(function ($query) {
                        $query->whereNull('trip_phase')
                            ->orWhere('trip_phase', '!=', TripPhase::COMPLETED->value);
                    })
                    ->get();
                $assignmentIds = $assignments->pluck('id');
                foreach ($assignments as $assignment) {
                    $updates = [
                        'status' => 'completed',
                        'trip_phase' => TripPhase::COMPLETED,
                        'trip_completed_at' => $completedAt,
                        'actual_end' => $completedAt,
                    ];
                    $manualDistance = $completionData['actual_distance'] ?? $completionData['distance_km'] ?? null;
                    if (is_numeric($manualDistance)) {
                        $updates['total_distance_km'] = round((float) $manualDistance, 2);
                    }
                    $assignment->update($updates);
                }
                if ($assignmentIds->isNotEmpty()) {
                    DriverSession::query()
                        ->whereIn('assignment_id', $assignmentIds)
                        ->update(['assignment_id' => null]);
                }

                $vehicleId = $dispatch?->vehicle_id ?: $context['vehicle_id'];
                if ($vehicleId) {
                    $this->makeVehicleAvailable((string) $vehicleId);
                }

                $allItemsCompleted = $booking->bookingItems()->whereNull('completed_at')->doesntExist();
                if ($allItemsCompleted) {
                    $booking->update([
                        'status' => 'completed',
                        'completed_at' => $completedAt,
                        'updated_user_id' => $actorUserId,
                        'workflow_data' => array_merge(
                            is_array($booking->workflow_data) ? $booking->workflow_data : [],
                            ['force_completion' => $forceAudit]
                        ),
                    ]);
                }

                AuditLog::create([
                    'user_id' => $actorUserId,
                    'action' => 'booking_force_completed',
                    'entity' => 'Booking',
                    'entity_id' => $booking->id,
                    'timestamp' => Carbon::now('UTC'),
                    'details' => array_merge($forceAudit, ['booking_item_id' => $bookingItem->id]),
                ]);

                Log::warning('Booking hire administratively force completed without canonical final pricing', [
                    'booking_id' => $booking->id,
                    'booking_item_id' => $bookingItem->id,
                    'actor_user_id' => $actorUserId,
                    'canonical_error' => $exception->getMessage(),
                ]);

                return $booking->fresh(['bookingItems', 'dispatches']);
            });
        }
    }

    private function closeDriverAssignmentsForCompletion(
        string $bookingId,
        ?string $bookingItemId,
        Carbon $completedAt,
        array $completionData
    ): void {
        DB::transaction(function () use ($bookingId, $bookingItemId, $completedAt, $completionData) {
            $assignments = DriverAssignment::query()
                ->where('booking_id', $bookingId)
                ->when($bookingItemId, fn ($query) => $query->where('booking_item_id', $bookingItemId))
                ->where(function ($query) {
                    $query->whereNull('trip_phase')
                        ->orWhere('trip_phase', '!=', TripPhase::COMPLETED->value);
                })
                ->lockForUpdate()
                ->get();
            $assignmentIds = $assignments->pluck('id');
            $manualDistance = $completionData['actual_distance'] ?? $completionData['distance_km'] ?? null;

            foreach ($assignments as $assignment) {
                $updates = [
                    'status' => 'completed',
                    'trip_phase' => TripPhase::COMPLETED,
                    'trip_completed_at' => $completedAt,
                    'actual_end' => $completedAt,
                ];
                if (is_numeric($manualDistance)) {
                    $updates['total_distance_km'] = round((float) $manualDistance, 2);
                }
                $assignment->update($updates);
            }

            if ($assignmentIds->isNotEmpty()) {
                DriverSession::query()
                    ->whereIn('assignment_id', $assignmentIds)
                    ->update(['assignment_id' => null]);
            }
        });
    }

    private function completeMultiItemBookingItem(
        Booking $booking,
        array $context,
        ?BookingDispatch $dispatch,
        array $completionData,
        array $workflowSettings,
        string $actorUserId
    ): Booking {
        /** @var BookingItem|null $bookingItem */
        $bookingItem = $context['booking_item'] ?? null;
        if (!$bookingItem) {
            throw new \DomainException('A booking item is required for multi-item completion');
        }

        if ($bookingItem->completed_at) {
            $newlyCompleted = $this->finalizeMultiItemBookingIfReady(
                $booking,
                $actorUserId,
                $completionData
            );
            if ($newlyCompleted) {
                $this->runAggregateCompletionEffects(
                    $booking->fresh(),
                    (bool) ($completionData['suppress_completion_emails'] ?? false)
                );
            }

            return $booking->fresh(['bookingItems', 'dispatches']);
        }

        $driverDirectCompletion = (bool) ($completionData['completed_by_driver'] ?? false);
        $returnStageEnabled = (bool) ($workflowSettings['enable_return_stage'] ?? false)
            && !$driverDirectCompletion;
        $hasReturned = (bool) ($bookingItem->returned_at || $dispatch?->isReturned());

        if ($returnStageEnabled && !$hasReturned) {
            throw new \DomainException(
                'The selected booking item must be returned before it can be completed.'
            );
        }

        if ((bool) ($workflowSettings['enable_qc_stage'] ?? false) && !$driverDirectCompletion) {
            $qc = $this->resolveItemQc($booking, $context, true);
            if (!$qc || !$qc->isCompleted()) {
                throw new \DomainException(
                    'The selected booking item must pass QC and finish any required repairs before completion.'
                );
            }
        }

        if (!$returnStageEnabled && $dispatch && !$dispatch->isReturned()) {
            $dispatch->markReturned($actorUserId, [
                'actual_return_time' => $completionData['actual_return_time'] ?? Carbon::now('UTC'),
                'notes' => 'Item completed without return management.',
                'completed_by_driver' => (bool) ($completionData['completed_by_driver'] ?? false),
            ]);
            $hasReturned = true;
        }

        $finalPricing = $this->synchronizeFinalPricing(
            $booking,
            $context,
            $dispatch?->fresh(),
            $completionData,
            'booking_completion'
        );

        $completedAt = Carbon::now('UTC');
        $returnTimestamp = $bookingItem->returned_at
            ?? $dispatch?->fresh()->actual_return_at
            ?? (!$returnStageEnabled ? $completedAt : null);
        $bookingItem->update([
            'returned_at' => $returnTimestamp,
            'final_priced_at' => $completedAt,
            'lifecycle_data' => array_merge(
                is_array($bookingItem->lifecycle_data) ? $bookingItem->lifecycle_data : [],
                [
                    'dispatch_id' => $dispatch?->id,
                    'return_skipped' => !$returnStageEnabled && !$hasReturned,
                    'qc_skipped' => !(bool) ($workflowSettings['enable_qc_stage'] ?? false),
                    'final_pricing_trigger' => 'booking_completion',
                    'final_pricing' => $finalPricing,
                ]
            ),
        ]);
        $this->markBookingItemCompleted($bookingItem->fresh(), array_merge($completionData, [
            'booking_item_id' => $bookingItem->id,
            'dispatch_id' => $dispatch?->id,
        ]));
        $this->closeDriverAssignmentsForCompletion(
            (string) $booking->id,
            (string) $bookingItem->id,
            $completedAt,
            $completionData
        );
        $bookingItem->refresh();

        $vehicleId = $dispatch?->vehicle_id ?: $context['vehicle_id'];
        if ($vehicleId) {
            $this->makeVehicleAvailable((string) $vehicleId);
        }

        $this->logItemLifecycleEvent($booking, $bookingItem, 'completed', $completionData);
        $newlyCompleted = $this->finalizeMultiItemBookingIfReady(
            $booking,
            $actorUserId,
            $completionData
        );
        if ($newlyCompleted) {
            $this->runAggregateCompletionEffects(
                $booking->fresh(),
                (bool) ($completionData['suppress_completion_emails'] ?? false)
            );
        }

        return $booking->fresh(['bookingItems', 'dispatches']);
    }

    /**
     * Re-attempt final pricing for a booking item whose prior completion left
     * it flagged pending_manual_pricing (see synchronizeFinalPricing). Intended
     * for the bookings:retry-final-pricing scheduled command: once the missing
     * calculation definition/rate/condition is corrected, this resolves the
     * price and fires the invoice that was withheld at completion time.
     *
     * @return array{retried: bool, resolved: bool, audit: array<string, mixed>}
     */
    public function retryPendingFinalPricing(string $bookingItemId): array
    {
        return DB::transaction(function () use ($bookingItemId) {
            $bookingItem = BookingItem::query()
                ->whereKey($bookingItemId)
                ->lockForUpdate()
                ->first();
            if (!$bookingItem) {
                return ['retried' => false, 'resolved' => false, 'audit' => []];
            }

            $currentAudit = data_get($bookingItem->metadata, 'final_pricing_audit', []);
            if (!$this->isPricingPendingReview($currentAudit)) {
                return ['retried' => false, 'resolved' => true, 'audit' => $currentAudit];
            }

            $booking = Booking::query()
                ->lockForUpdate()
                ->with(['dispatches', 'bookingItems'])
                ->findOrFail($bookingItem->booking_id);
            $context = $this->resolveLifecycleContext($booking, $bookingItem->id);
            $dispatch = $this->resolveItemDispatch($booking, $context, true);

            $audit = $this->synchronizeFinalPricing(
                $booking,
                $context,
                $dispatch,
                [],
                'pricing_retry'
            );

            $resolved = !$this->isPricingPendingReview($audit);
            if ($resolved && (string) $booking->status === 'completed') {
                if ($booking->bookingItems->count() > 1) {
                    if (!$this->bookingHasPendingPricingReview($booking->fresh(['bookingItems']))) {
                        $this->runAggregateCompletionEffects($booking->fresh());
                    }
                } else {
                    try {
                        $this->invoiceService->generateAndSend($booking->fresh([
                            'customer.user',
                            'bookingItems.serviceType',
                            'bookingItems.vehicle.group',
                            'bookingItems.driver.user',
                            'bookingAddons',
                        ]));
                    } catch (\Throwable $e) {
                        Log::error('Invoice generation failed after final-pricing retry resolved the price', [
                            'booking_id' => (string) $booking->id,
                            'booking_item_id' => $bookingItemId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return ['retried' => true, 'resolved' => $resolved, 'audit' => $audit];
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
        $pickupWaitingSeconds = $assignment ? max(0, (int) ($assignment->pickup_waiting_time_seconds ?? 0)) : 0;
        $hireWaitingSeconds = $assignment ? max(0, (int) ($assignment->hire_waiting_time_seconds ?? 0)) : 0;
        if ($assignment && $pickupWaitingSeconds === 0 && $hireWaitingSeconds === 0
            && (int) $assignment->total_waiting_time_seconds > 0) {
            $hireWaitingSeconds = (int) $assignment->total_waiting_time_seconds;
        }

        $driverTelemetry = $hasDriverTelemetry ? [
            '_source' => 'driver_mobile_activity',
            'actual_start_time' => $assignment->trip_started_at ?: $assignment->actual_start,
            'actual_return_time' => $assignment->trip_completed_at ?: $assignment->actual_end,
            'distance_km' => $assignment->total_distance_km !== null
                ? (float) $assignment->total_distance_km
                : null,
            'waiting_minutes' => (int) ceil(((int) $assignment->total_waiting_time_seconds) / 60),
            'pickup_waiting_minutes' => (int) ceil($pickupWaitingSeconds / 60),
            'hire_waiting_minutes' => (int) ceil($hireWaitingSeconds / 60),
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
            'actual_start_time' => $activityData['actual_start_time'] ?? $dispatch?->dispatched_at,
            'actual_return_time' => $activityData['actual_return_time'] ?? $dispatch?->actual_return_at,
            'distance_km' => is_numeric($activityData['actual_distance'] ?? null)
                ? (float) $activityData['actual_distance']
                : (is_numeric($activityData['distance_km'] ?? null)
                    ? (float) $activityData['distance_km']
                    : $this->measuredMileageDistance($dispatch)),
            'waiting_minutes' => array_key_exists('waiting_minutes', $activityData)
                ? (int) $activityData['waiting_minutes']
                : null,
            'pickup_waiting_minutes' => array_key_exists('pickup_waiting_minutes', $activityData)
                ? (int) $activityData['pickup_waiting_minutes']
                : null,
            'hire_waiting_minutes' => array_key_exists('hire_waiting_minutes', $activityData)
                ? (int) $activityData['hire_waiting_minutes']
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
            'distance_km' => $this->measuredMileageDistance($dispatch),
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

        $isMultiItem = $booking->relationLoaded('bookingItems')
            ? $booking->bookingItems->count() > 1
            : BookingItem::query()->where('booking_id', $booking->id)->count() > 1;
        $itemDurationMetrics = data_get(
            $booking->duration_metrics,
            'items.' . (string) $bookingItem->id,
            []
        );
        $itemDistanceMetrics = data_get(
            $booking->distance_metrics,
            'items.' . (string) $bookingItem->id,
            []
        );
        $persistedFallback = $isMultiItem ? [
            '_source' => 'booking_item_persisted_fallback',
            'actual_start_time' => data_get($bookingItem->lifecycle_data, 'actual_start_time'),
            'actual_return_time' => $bookingItem->returned_at
                ?? data_get($bookingItem->lifecycle_data, 'actual_return_time'),
            'distance_km' => is_numeric($itemDistanceMetrics['actual_km'] ?? null)
                ? (float) $itemDistanceMetrics['actual_km']
                : null,
            'waiting_minutes' => is_numeric($itemDurationMetrics['waiting_minutes'] ?? null)
                ? (int) $itemDurationMetrics['waiting_minutes']
                : null,
            'pickup_waiting_minutes' => is_numeric($itemDurationMetrics['pickup_waiting_minutes'] ?? null)
                ? (int) $itemDurationMetrics['pickup_waiting_minutes']
                : null,
            'hire_waiting_minutes' => is_numeric($itemDurationMetrics['hire_waiting_minutes'] ?? null)
                ? (int) $itemDurationMetrics['hire_waiting_minutes']
                : null,
        ] : [
            '_source' => 'booking_persisted_fallback',
            'actual_start_time' => $booking->trip_started_at,
            'actual_return_time' => $booking->completed_at,
            'distance_km' => $booking->actual_distance !== null ? (float) $booking->actual_distance : null,
            'waiting_minutes' => data_get($booking->duration_metrics, 'waiting_minutes'),
            'pickup_waiting_minutes' => data_get($booking->duration_metrics, 'pickup_waiting_minutes'),
            'hire_waiting_minutes' => data_get($booking->duration_metrics, 'hire_waiting_minutes'),
        ];

        $resolvedTelemetry = $this->finalPricingTelemetryResolver->resolve([
            'driver_mobile_activity' => $driverTelemetry,
            'system_activity' => $systemTelemetry,
            'dispatch_return' => $dispatchTelemetry,
            'customer_mobile_activity' => $customerTelemetry,
        ], $persistedFallback);

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
        $persistedDurationMinutes = $isMultiItem
            ? ($itemDurationMetrics['actual_minutes'] ?? null)
            : ($booking->actual_duration ?? data_get($booking->duration_metrics, 'actual_minutes'));
        $durationMinutes = $measuredDurationMinutes
            ?? (int) ($persistedDurationMinutes
                ?? $bookingItem->duration_minutes
                ?? (($bookingItem->duration_hours ?? 0) * 60));
        $hasDurationSource = $measuredDurationMinutes !== null
            || $persistedDurationMinutes !== null;

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

        $hasWaitingSource = (array_key_exists('waiting_minutes', $resolvedTelemetry)
                && is_numeric($resolvedTelemetry['waiting_minutes']))
            || (array_key_exists('pickup_waiting_minutes', $resolvedTelemetry)
                && is_numeric($resolvedTelemetry['pickup_waiting_minutes']))
            || (array_key_exists('hire_waiting_minutes', $resolvedTelemetry)
                && is_numeric($resolvedTelemetry['hire_waiting_minutes']));
        $waitingMinutes = $hasWaitingSource
            ? max(0, (int) $resolvedTelemetry['waiting_minutes'])
            : null;
        $pickupWaitingMinutes = max(0, (int) ($resolvedTelemetry['pickup_waiting_minutes'] ?? 0));
        $hireWaitingMinutes = max(0, (int) ($resolvedTelemetry['hire_waiting_minutes']
            ?? ($waitingMinutes !== null ? max(0, $waitingMinutes - $pickupWaitingMinutes) : 0)));
        $totalWaitingMinutes = $pickupWaitingMinutes + $hireWaitingMinutes;
        $waitingMinutes = $hasWaitingSource ? $totalWaitingMinutes : null;
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

        $packageId = $metadata['service_package_id'] ?? $metadata['package_id'] ?? null;
        $additionalStops = $this->resolveBookedAdditionalStops($metadata);
        $packageIncludedKm = $this->resolvePackageIncludedKilometres(
            $metadata,
            is_array($bookingItem->pricing_breakdown) ? $bookingItem->pricing_breakdown : [],
            $durationMinutes
        );

        // Operational charges must be available to the calculation graph so a
        // definition can explicitly include them. If it does not, they are
        // appended once after the configured formula has been evaluated.
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
        $lateFee = round((float) ($dispatch?->late_return_fee ?? $activityData['late_fee'] ?? 0), 2);
        $recordedManualCharges = $manualCharges;
        $recordedLateFee = $lateFee;
        $currencyContext = $this->resolveFinalPricingCurrencyContext($bookingItem, $booking);
        if ($currencyContext['exchange_rate'] !== 1.0) {
            $manualCharges = round($recordedManualCharges / $currencyContext['exchange_rate'], 6);
            $lateFee = round($recordedLateFee / $currencyContext['exchange_rate'], 6);
        }
        $approvedCustomizations = is_array($bookingItem->customizations)
            ? $bookingItem->customizations
            : [];

        $bookingPricingContext = $this->resolvePersistedBookingPricingContext($booking, $bookingItem);
        $params = [
            'service_type_id' => $bookingItem->service_type_id,
            'vehicle_group_id' => $bookingItem->vehicle_group_id,
            'vehicle_id' => $bookingItem->vehicle_id ?: $context['vehicle_id'],
            'corporate_account_id' => $booking->corporate_account_id,
            'is_corporate_booking' => (bool) ($booking->is_corporate_booking && $booking->corporate_account_id),
            'pricing_context' => $bookingPricingContext,
            'service_type_context' => $bookingPricingContext,
            'package_id' => $packageId,
            'customer_id' => $booking->customer_id,
            'from_date' => $bookingItem->from_date ?? $booking->from_date,
            'to_date' => $bookingItem->to_date ?? $booking->to_date,
            'from_time' => $bookingItem->from_time ?? $booking->from_time,
            'to_time' => $bookingItem->to_time ?? $booking->to_time,
            'duration_hours' => $durationMinutes / 60,
            'duration_minutes' => $durationMinutes,
            // A known positive final duration always occupies at least one
            // billable calendar day. Missing duration is handled separately.
            'duration_days' => $durationMinutes > 0
                ? max(1, (int) ceil($durationMinutes / 1440))
                : 0,
            'extra_minutes' => $extraMinutes,
            'extra_hours' => $extraMinutes / 60,
            'overtime_minutes' => $extraMinutes,
            'overtime_hours' => $extraMinutes / 60,
            'is_self_driven' => $isSelfDriven,
            'booking_type' => $isSelfDriven ? 'self_drive' : 'with_driver',
            'additional_stops' => $additionalStops,
            'stops' => $additionalStops,
            'manual_additional_charge' => $manualCharges,
            'late_return_fee' => $lateFee,
            'applied_customizations' => $approvedCustomizations,
            'mode' => 'final_calculation',
        ];
        if ($hasWaitingSource) {
            $params['pickup_waiting_minutes'] = $pickupWaitingMinutes;
            $params['hire_waiting_minutes'] = $hireWaitingMinutes;
            $params['total_waiting_minutes'] = $totalWaitingMinutes;
            // Legacy definitions continue to receive the total until they are
            // migrated to the explicit pickup/hire variables.
            $params['waiting_minutes'] = $totalWaitingMinutes;
            $params['waiting_hours'] = $totalWaitingMinutes / 60;
        }
        if ($packageIncludedKm !== null) {
            $params['package_included_km'] = $packageIncludedKm;
        }
        if ($distanceKm !== null) {
            $params += [
                'journey_distance' => (float) $distanceKm,
                'total_distance' => (float) $distanceKm,
                'actual_distance' => (float) $distanceKm,
            ];
        }

        $params = app(PricingContextPolicyService::class)->normalizeCalculationParams($params);

        $activeDefinitions = VehiclePricingCalculationDefinition::query()
            ->where('service_type_id', $params['service_type_id'])
            ->where('status', 'active')
            ->get(['id', 'name', 'variables', 'formula', 'conditions']);

        $result = $this->bookingFlowService->calculateDynamicPricing($params);
        $definitionId = data_get($result, 'pricing_scope.calculation_definition_id')
            ?? data_get($result, 'calculation_metadata.definition_used');
        $rawCalculatedBase = $result['total_amount'] ?? null;
        if (
            $definitionId
            && (!is_numeric($rawCalculatedBase)
                || !is_finite((float) $rawCalculatedBase)
                || (float) $rawCalculatedBase < 0)
        ) {
            throw new \DomainException(
                'Final pricing returned an invalid total. Completion was stopped before invoice generation.'
            );
        }
        $calculatedBase = is_numeric($rawCalculatedBase) ? (float) $rawCalculatedBase : 0.0;
        $resolvedVariables = data_get($result, 'calculation_metadata.resolved_variables', []);
        $resolvedVariables = is_array($resolvedVariables) ? $resolvedVariables : [];

        $audit = [
            'status' => $definitionId && $calculatedBase >= 0 ? 'calculated' : 'preserved',
            'trigger' => $trigger,
            'booking_item_id' => (string) $bookingItem->id,
            'source' => $source,
            'source_category' => $sourceCategory,
            'source_selection' => $sourceSelection,
            'calculation_definition_id' => $definitionId,
            'calculation_definition_name' => data_get($result, 'pricing_scope.calculation_definition_name'),
            'calculated_at' => Carbon::now('UTC')->toIso8601String(),
            'contractual_distance_preserved' => $contractualDistance,
            'currency' => $currencyContext,
            'context' => [
                'service_type_id' => (string) $bookingItem->service_type_id,
                'vehicle_group_id' => (string) $bookingItem->vehicle_group_id,
                'vehicle_id' => $params['vehicle_id'] ? (string) $params['vehicle_id'] : null,
                'customer_id' => $booking->customer_id ? (string) $booking->customer_id : null,
                'corporate_account_id' => $booking->corporate_account_id
                    ? (string) $booking->corporate_account_id
                    : null,
                'package_id' => $packageId ? (string) $packageId : null,
                'is_self_driven' => $isSelfDriven,
                'booking_type' => $isSelfDriven ? 'self_drive' : 'with_driver',
                'from_date' => $params['from_date'],
                'to_date' => $params['to_date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
                'is_weekend' => data_get($result, 'calculation_metadata.runtime_context.is_weekend'),
                'is_holiday' => data_get($result, 'calculation_metadata.runtime_context.is_holiday'),
                'holiday_context' => data_get($result, 'calculation_metadata.runtime_context.holiday_context'),
                'additional_stops' => $additionalStops,
                'package_included_km' => $packageIncludedKm,
                'approved_customizations' => $approvedCustomizations,
            ],
            'inputs' => [
                'duration_minutes' => $durationMinutes,
                'distance_km' => $distanceKm !== null ? round((float) $distanceKm, 2) : null,
                'waiting_minutes' => $waitingMinutes,
                'pickup_waiting_minutes' => $pickupWaitingMinutes,
                'hire_waiting_minutes' => $hireWaitingMinutes,
                'total_waiting_minutes' => $totalWaitingMinutes,
                'extra_minutes' => $extraMinutes,
                'manual_additional_charge' => $manualCharges,
                'late_return_fee' => $lateFee,
                'recorded_manual_additional_charge' => $recordedManualCharges,
                'recorded_late_return_fee' => $recordedLateFee,
            ],
            'rates_and_variables' => $resolvedVariables,
            'rate_sources' => data_get($result, 'calculation_metadata.rate_sources', []),
            'candidate_failures' => data_get($result, 'calculation_metadata.candidate_failures', []),
            'conditions_evaluated' => data_get($result, 'calculation_metadata.conditions_evaluated', []),
            'matched_slab' => $result['slab_information']
                ?? data_get($result, 'calculation_metadata.matched_slab'),
            'adjustments' => $result['adjustment_details'] ?? [],
            'formula_evaluation' => $result['formula_evaluation'] ?? null,
            'breakdown' => $result['breakdown'] ?? [],
            'calculation_example' => $this->buildFinalCalculationExample(
                $result,
                0.0,
                0.0,
                round($calculatedBase * $currencyContext['exchange_rate'], 2),
                $currencyContext
            ),
        ];

        if (!$definitionId) {
            if ($activeDefinitions->isNotEmpty()) {
                // Active definitions exist for this service type, but none of them
                // could actually price this booking (contradictory conditions, a
                // missing rate row for this vehicle group, or an invalid formula
                // result). Do not invoice off an unresolved price, but also do not
                // block the caller (trip/booking completion) on it: flag it for
                // manual review and let a scheduled job retry once the underlying
                // configuration is fixed. See PricingDefinitionOrchestrator for the
                // candidate_failures detail captured below.
                $candidateFailures = data_get($result, 'calculation_metadata.candidate_failures', []);
                $audit['status'] = 'pending_manual_pricing';
                $audit['reason'] = 'no_matching_calculation_definition';
                $audit['candidate_failures'] = $candidateFailures;
                $audit['calculation_example']['status'] = 'not_calculated';
                $audit['calculation_example']['note'] = 'Active calculation definitions exist but none matched this booking; pricing is pending manual review.';
                $bookingItem->update([
                    'metadata' => array_merge($metadata, ['final_pricing_audit' => $audit]),
                ]);

                Log::error('Final pricing pending manual review: no active calculation definition matched this booking', [
                    'booking_id' => (string) $booking->id,
                    'booking_item_id' => (string) $bookingItem->id,
                    'service_type_id' => (string) $bookingItem->service_type_id,
                    'vehicle_group_id' => (string) $bookingItem->vehicle_group_id,
                    'assignment_id' => $assignment?->id,
                    'candidate_ids' => $activeDefinitions->pluck('id')->all(),
                    'candidate_failures' => $candidateFailures,
                ]);

                $this->alertOpsPricingResolutionFailed($booking, $bookingItem, $audit);

                return $audit;
            }
            $audit['reason'] = 'no_active_calculation_definition';
            $audit['calculation_example']['status'] = 'not_calculated';
            $audit['calculation_example']['note'] = 'No active calculation definition is configured; the existing item price was preserved.';
            $bookingItem->update([
                'metadata' => array_merge($metadata, ['final_pricing_audit' => $audit]),
            ]);
            return $audit;
        }

        $selectedDefinition = $activeDefinitions->firstWhere('id', $definitionId);
        $selectedFormula = (string) ($selectedDefinition?->formula ?? '');

        $referencesMeasuredDistance = $this->formulaReferencesAny($selectedFormula, [
            'journey_distance', 'total_distance', 'actual_distance', 'distance_km',
        ]);
        $referencesDistanceOverage = $this->formulaReferencesAny($selectedFormula, ['extra_km']);
        $distanceOverageNeedsTelemetry = $referencesDistanceOverage
            && data_get($result, 'km_calculations.calculation_type') !== 'unlimited';
        $minimumDistanceResolved = data_get($result, 'distance_details.minimum_km_applied') === true
            && is_numeric(data_get($result, 'distance_details.journey_distance'));

        if (
            !$contractualDistance
            && $distanceKm === null
            && !$minimumDistanceResolved
            && ($referencesMeasuredDistance || $distanceOverageNeedsTelemetry)
        ) {
            throw new \DomainException(
                'Final distance is required by the selected pricing definition. Completion was stopped until mileage or measured distance is supplied.'
            );
        }
        if (!$hasDurationSource && $this->formulaReferencesAny($selectedFormula, [
            'duration_minutes', 'duration_hours', 'duration_days',
            'extra_minutes', 'extra_hours', 'overtime_minutes', 'overtime_hours',
        ])) {
            throw new \DomainException(
                'Final duration is required by the selected pricing definition. Completion was stopped until start and return times are supplied.'
            );
        }
        if (!$hasWaitingSource && $this->formulaReferencesAny($selectedFormula, [
            'waiting_minutes', 'waiting_hours',
        ])) {
            throw new \DomainException(
                'Final waiting time is required by the selected pricing definition. Completion was stopped until a measured value, including explicit zero, is supplied.'
            );
        }
        if (!$hasIncludedDuration && $this->formulaReferencesAny($selectedFormula, [
            'extra_minutes', 'extra_hours', 'overtime_minutes', 'overtime_hours',
        ])) {
            throw new \DomainException(
                'Included package duration is missing. Completion was stopped to prevent all trip time from being charged as overtime.'
            );
        }

        $manualChargeInFormula = $this->formulaReferencesAny(
            $selectedFormula,
            ['manual_additional_charge']
        );
        $lateFeeInFormula = $this->formulaReferencesAny($selectedFormula, ['late_return_fee']);
        $appendedManualCharges = $manualChargeInFormula ? 0.0 : $manualCharges;
        $appendedLateFee = $lateFeeInFormula ? 0.0 : $lateFee;
        $finalCalculationCurrencyAmount = round(
            $calculatedBase + $appendedManualCharges + $appendedLateFee,
            2
        );
        $finalBase = round(
            $finalCalculationCurrencyAmount * $currencyContext['exchange_rate'],
            2
        );
        $audit += [
            'calculated_base' => $calculatedBase,
            'calculated_base_converted' => round(
                $calculatedBase * $currencyContext['exchange_rate'],
                2
            ),
            'manual_charges' => $recordedManualCharges,
            'late_return_fee' => $recordedLateFee,
            'operational_charge_handling' => [
                'manual_charge_in_formula' => $manualChargeInFormula,
                'late_fee_in_formula' => $lateFeeInFormula,
                'manual_charge_calculation_currency' => $manualCharges,
                'late_fee_calculation_currency' => $lateFee,
                'manual_charge_appended_calculation_currency' => $appendedManualCharges,
                'late_fee_appended_calculation_currency' => $appendedLateFee,
            ],
            'final_calculation_currency_amount' => $finalCalculationCurrencyAmount,
            'final_base' => $finalBase,
            'calculation_example' => $this->buildFinalCalculationExample(
                $result,
                $appendedManualCharges,
                $appendedLateFee,
                $finalBase,
                $currencyContext
            ),
        ];

        $this->commitPriceAdjustmentUsage(
            $booking,
            $bookingItem,
            data_get($result, 'adjustment_details.adjustments', [])
        );

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
        $billableBookingItems = $booking->bookingItems->reject(
            fn (BookingItem $item) => in_array((string) $item->status, ['cancelled', 'rejected'], true)
        );
        $durationMetrics = is_array($booking->duration_metrics) ? $booking->duration_metrics : [];
        $durationItems = is_array($durationMetrics['items'] ?? null) ? $durationMetrics['items'] : [];
        $durationItems[(string) $bookingItem->id] = [
            'source' => $source,
            'actual_minutes' => $durationMinutes,
            'waiting_minutes' => $waitingMinutes,
            'extra_minutes' => $extraMinutes,
        ];
        $aggregateDurationMinutes = (int) collect($durationItems)->sum(
            fn ($metrics) => (int) ($metrics['actual_minutes'] ?? 0)
        );
        $aggregateWaitingMinutes = (int) collect($durationItems)->sum(
            fn ($metrics) => (int) ($metrics['waiting_minutes'] ?? 0)
        );
        $aggregateExtraMinutes = (int) collect($durationItems)->sum(
            fn ($metrics) => (int) ($metrics['extra_minutes'] ?? 0)
        );

        $distanceMetrics = is_array($booking->distance_metrics) ? $booking->distance_metrics : [];
        $distanceItems = is_array($distanceMetrics['items'] ?? null) ? $distanceMetrics['items'] : [];
        $distanceItems[(string) $bookingItem->id] = [
            'source' => $source,
            'actual_km' => $distanceKm !== null ? round((float) $distanceKm, 2) : null,
            'pricing_effect' => $contractualDistance
                ? 'contractual_distance_preserved'
                : 'final_recalculation',
        ];
        $measuredItemDistances = collect($distanceItems)
            ->pluck('actual_km')
            ->filter(fn ($distance) => is_numeric($distance));

        $pricingSnapshot = is_array($booking->pricing_snapshot) ? $booking->pricing_snapshot : [];
        $finalPricingItems = is_array($pricingSnapshot['final_pricing_items'] ?? null)
            ? $pricingSnapshot['final_pricing_items']
            : [];
        $finalPricingItems[(string) $bookingItem->id] = $audit;

        $bookingUpdates = [
            'actual_duration' => $aggregateDurationMinutes,
            'base_amount' => round((float) $billableBookingItems->sum('total_price'), 2),
            'total_actual' => round($booking->calculateTotal(), 2),
            'duration_metrics' => array_merge($durationMetrics, [
                'source' => count($durationItems) > 1 ? 'multiple_item_sources' : $source,
                'actual_minutes' => $aggregateDurationMinutes,
                'waiting_minutes' => $aggregateWaitingMinutes,
                'extra_minutes' => $aggregateExtraMinutes,
                'items' => $durationItems,
            ]),
            'distance_metrics' => array_merge($distanceMetrics, [
                'source' => count($distanceItems) > 1 ? 'multiple_item_sources' : $source,
                'actual_km' => $measuredItemDistances->isNotEmpty()
                    ? round((float) $measuredItemDistances->sum(), 2)
                    : null,
                'pricing_effect' => collect($distanceItems)->contains(
                    fn ($metrics) => ($metrics['pricing_effect'] ?? null) === 'contractual_distance_preserved'
                ) ? 'contains_contractual_distance' : 'final_recalculation',
                'items' => $distanceItems,
            ]),
            'pricing_snapshot' => array_merge($pricingSnapshot, [
                'final_pricing' => $audit,
                'final_pricing_items' => $finalPricingItems,
                'final_pricing_summary' => [
                    'priced_item_count' => count($finalPricingItems),
                    'booking_item_count' => $billableBookingItems->count(),
                    'excluded_terminal_item_count' => $booking->bookingItems->count() - $billableBookingItems->count(),
                    'calculated_total' => round((float) $billableBookingItems->sum('total_price'), 2),
                    'last_calculated_at' => $audit['calculated_at'],
                ],
            ]),
        ];
        if ($measuredItemDistances->isNotEmpty()) {
            $bookingUpdates['actual_distance'] = round((float) $measuredItemDistances->sum(), 2);
        }
        $booking->update($bookingUpdates);

        return $audit;
    }

    /**
     * True when a final-pricing audit (as returned by synchronizeFinalPricing)
     * could not resolve a price and is awaiting manual/config correction.
     * Callers must not invoice off this booking item until it clears.
     */
    private function isPricingPendingReview(?array $finalPricingAudit): bool
    {
        return ($finalPricingAudit['status'] ?? null) === 'pending_manual_pricing';
    }

    /**
     * True when any billable item on the booking still has pricing pending
     * review. Used to gate aggregate (whole-booking) invoice generation.
     */
    private function bookingHasPendingPricingReview(Booking $booking): bool
    {
        return $booking->bookingItems
            ->reject(fn (BookingItem $item) => in_array((string) $item->status, ['cancelled', 'rejected'], true))
            ->contains(fn (BookingItem $item) => $this->isPricingPendingReview(
                data_get($item->metadata, 'final_pricing_audit')
            ));
    }

    /**
     * Best-effort ops notification the moment pricing resolution fails.
     * Failure to send must never affect trip/booking completion.
     */
    private function alertOpsPricingResolutionFailed(Booking $booking, BookingItem $bookingItem, array $audit): void
    {
        $opsEmail = $this->websiteSettingsService->get(
            'pricing_alert_email',
            $this->websiteSettingsService->get('company_email')
        );
        if (empty($opsEmail)) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Mail::to($opsEmail)->queue(new \App\Mail\PricingResolutionFailedMail([
                'booking_id' => (string) $booking->id,
                'booking_number' => $booking->booking_number,
                'booking_item_id' => (string) $bookingItem->id,
                'service_type_id' => (string) $bookingItem->service_type_id,
                'vehicle_group_id' => (string) $bookingItem->vehicle_group_id,
                'reason' => $audit['reason'] ?? 'no_matching_calculation_definition',
                'candidate_failures' => $audit['candidate_failures'] ?? [],
                'detected_at' => Carbon::now('UTC')->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::error('Failed to send ops alert for pending final pricing', [
                'booking_id' => (string) $booking->id,
                'booking_item_id' => (string) $bookingItem->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Commit limited price-adjustment usage only during canonical final pricing.
     * Preview/test calculations remain read-only, while repeated completion of
     * the same item updates its audit row without consuming another use.
     *
     * @param array<int, array<string, mixed>> $adjustments
     */
    private function commitPriceAdjustmentUsage(
        Booking $booking,
        BookingItem $bookingItem,
        array $adjustments
    ): void {
        $adjustmentsById = collect($adjustments)
            ->filter(fn ($adjustment) => is_array($adjustment)
                && ($adjustment['type'] ?? null) === 'price_adjustment'
                && !empty($adjustment['price_adjustment_id']))
            ->keyBy('price_adjustment_id');

        foreach ($adjustmentsById as $adjustmentId => $result) {
            /** @var PriceAdjustment|null $adjustment */
            $adjustment = PriceAdjustment::query()
                ->whereKey($adjustmentId)
                ->lockForUpdate()
                ->first();

            if (!$adjustment || !$adjustment->is_active) {
                throw new \DomainException(
                    'A price adjustment changed while final pricing was being committed. Please retry completion.'
                );
            }

            $history = BookingPriceAdjustmentHistory::query()
                ->where('booking_item_id', $bookingItem->id)
                ->where('price_adjustment_id', $adjustmentId)
                ->first();

            $historyValues = [
                'booking_id' => $booking->id,
                'booking_item_id' => $bookingItem->id,
                'price_adjustment_id' => $adjustmentId,
                'adjustment_amount' => round((float) ($result['amount'] ?? 0), 2),
                'original_amount' => round((float) ($result['original_amount'] ?? 0), 2),
                'final_amount' => round((float) ($result['final_amount'] ?? 0), 2),
                'adjustment_breakdown' => $result,
                'adjustment_reason' => $result['description'] ?? $result['name'] ?? 'Final pricing adjustment',
                'applied_by_user_id' => Auth::id(),
                'applied_at' => Carbon::now('UTC'),
            ];

            if ($history) {
                $history->update($historyValues);
                continue;
            }

            $recordedUsage = BookingPriceAdjustmentHistory::query()
                ->where('price_adjustment_id', $adjustmentId)
                ->count();
            $effectiveUsage = max((int) $adjustment->usage_count, $recordedUsage);
            if ($adjustment->usage_limit !== null && $effectiveUsage >= (int) $adjustment->usage_limit) {
                throw new \DomainException(
                    "Price adjustment {$adjustment->name} has reached its usage limit. Final pricing was stopped."
                );
            }

            BookingPriceAdjustmentHistory::query()->create($historyValues);
            $adjustment->forceFill(['usage_count' => $effectiveUsage + 1])->save();
        }
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

    private function measuredMileageDistance(?BookingDispatch $dispatch): ?float
    {
        if (!$dispatch || $dispatch->mileage_in === null || $dispatch->mileage_out === null) {
            return null;
        }

        $mileageIn = (float) $dispatch->mileage_in;
        $mileageOut = (float) $dispatch->mileage_out;
        if ($mileageIn < $mileageOut) {
            throw new \DomainException(
                'Return mileage cannot be lower than the dispatch mileage. Final pricing was stopped.'
            );
        }

        return round($mileageIn - $mileageOut, 2);
    }

    private function resolvePersistedBookingPricingContext(
        Booking $booking,
        ?BookingItem $bookingItem = null
    ): string {
        if ($booking->corporate_account_id || (bool) $booking->is_corporate_booking) {
            return 'corporate';
        }

        $snapshotCandidates = [
            data_get($bookingItem?->pricing_breakdown, 'pricing_scope.pricing_context'),
            data_get($bookingItem?->pricing_breakdown, 'base_pricing.pricing_scope.pricing_context'),
            data_get($bookingItem?->pricing_breakdown, 'calculation_metadata.runtime_context.pricing_context'),
            data_get($booking->pricing_snapshot, 'pricing_scope.pricing_context'),
            data_get($booking->pricing_snapshot, 'base_pricing.pricing_scope.pricing_context'),
            data_get($booking->pricing_snapshot, 'calculation_metadata.runtime_context.pricing_context'),
            data_get($booking->pricing_snapshot, 'base_pricing.calculation_metadata.runtime_context.pricing_context'),
        ];
        foreach ($snapshotCandidates as $candidate) {
            $context = is_string($candidate) ? strtolower(trim($candidate)) : null;
            if (in_array($context, ['public', 'portal'], true)) {
                return $context;
            }
        }

        $source = strtolower(trim((string) ($booking->booking_source ?: $booking->created_from ?: '')));
        if (in_array($source, [
            'public', 'website', 'web', 'online', 'customer',
            'customer_portal', 'guest', 'mobile', 'customer_mobile',
        ], true)) {
            return 'public';
        }

        return 'portal';
    }

    private function formulaReferencesAny(string $formula, array $variableNames): bool
    {
        foreach ($variableNames as $variableName) {
            if (preg_match(
                '/(?<![A-Za-z0-9_])' . preg_quote((string) $variableName, '/') . '(?![A-Za-z0-9_])/',
                $formula
            )) {
                return true;
            }
        }

        return false;
    }

    private function resolveBookedAdditionalStops(array $metadata): int
    {
        foreach (['additional_stops', 'stops', 'additional_stops_count'] as $key) {
            if (is_numeric($metadata[$key] ?? null)) {
                return max(0, (int) $metadata[$key]);
            }
        }

        $normalizeList = static function (mixed $value): array {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
            }

            return is_array($value) ? array_values($value) : [];
        };

        $orderedStops = $normalizeList(
            $metadata['multi_route_stop_order']
                ?? $metadata['ordered_additional_stops']
                ?? $metadata['additional_route_stops']
                ?? []
        );
        if ($orderedStops !== []) {
            return count($orderedStops);
        }

        return count($normalizeList(
            $metadata['multi_pickup_locations'] ?? $metadata['additional_pickup_locations'] ?? []
        )) + count($normalizeList(
            $metadata['multi_dropoff_locations'] ?? $metadata['additional_dropoff_locations'] ?? []
        ));
    }

    private function resolvePackageIncludedKilometres(
        array $metadata,
        array $pricingBreakdown,
        int $durationMinutes
    ): ?float {
        $packageCandidates = [
            data_get($metadata, 'package_included_km'),
            data_get($metadata, 'included_km'),
            data_get($metadata, 'package_info.max_km_per_package'),
            data_get($metadata, 'package_info.included_km'),
            data_get($pricingBreakdown, 'distance_details.allowed_total_km'),
            data_get($pricingBreakdown, 'base_pricing.distance_details.allowed_total_km'),
            data_get($pricingBreakdown, 'distance_details.free_km_per_package'),
            data_get($pricingBreakdown, 'base_pricing.distance_details.free_km_per_package'),
        ];

        foreach ($packageCandidates as $candidate) {
            if (is_numeric($candidate) && (float) $candidate >= 0) {
                return round((float) $candidate, 2);
            }
        }

        $dailyCandidates = [
            data_get($metadata, 'package_info.max_km_per_day'),
            data_get($metadata, 'max_km_per_day'),
            data_get($pricingBreakdown, 'distance_details.free_km_per_day'),
            data_get($pricingBreakdown, 'base_pricing.distance_details.free_km_per_day'),
        ];
        foreach ($dailyCandidates as $candidate) {
            if (is_numeric($candidate) && (float) $candidate >= 0) {
                return round((float) $candidate * max(1, (int) ceil($durationMinutes / 1440)), 2);
            }
        }

        return null;
    }

    /**
     * Final pricing runs in the original calculation currency, then reuses the
     * exchange rate locked into the booked pricing snapshot. Current market
     * rates must never rewrite a confirmed booking at return time.
     *
     * @return array{calculation_currency:string,booking_currency:string,exchange_rate:float,rate_source:string}
     */
    private function resolveFinalPricingCurrencyContext(BookingItem $bookingItem, Booking $booking): array
    {
        $pricingBreakdown = is_array($bookingItem->pricing_breakdown)
            ? $bookingItem->pricing_breakdown
            : [];
        $calculationCurrency = strtoupper(trim((string) (
            data_get($pricingBreakdown, 'base_pricing.original_currency')
            ?? data_get($pricingBreakdown, 'original_currency')
            ?? config('booking.base_currency', 'LKR')
        )));
        $bookingCurrency = strtoupper(trim((string) (
            $bookingItem->currency
            ?? $booking->currency
            ?? data_get($pricingBreakdown, 'summary.currency')
            ?? $calculationCurrency
        )));
        $calculationCurrency = $calculationCurrency !== '' ? $calculationCurrency : 'LKR';
        $bookingCurrency = $bookingCurrency !== '' ? $bookingCurrency : $calculationCurrency;

        if ($bookingCurrency === $calculationCurrency) {
            return [
                'calculation_currency' => $calculationCurrency,
                'booking_currency' => $bookingCurrency,
                'exchange_rate' => 1.0,
                'rate_source' => 'same_currency',
            ];
        }

        $rateCandidates = [
            'base_pricing_snapshot' => data_get($pricingBreakdown, 'base_pricing.exchange_rate'),
            'item_summary_snapshot' => data_get($pricingBreakdown, 'summary.exchange_rate'),
            'item_snapshot' => data_get($pricingBreakdown, 'exchange_rate'),
        ];
        foreach ($rateCandidates as $source => $candidate) {
            if (is_numeric($candidate) && is_finite((float) $candidate) && (float) $candidate > 0) {
                return [
                    'calculation_currency' => $calculationCurrency,
                    'booking_currency' => $bookingCurrency,
                    'exchange_rate' => (float) $candidate,
                    'rate_source' => $source,
                ];
            }
        }

        throw new \DomainException(
            "The locked {$calculationCurrency} to {$bookingCurrency} exchange rate is missing. Final pricing was stopped."
        );
    }

    private function assertItemSafeLifecycle(Booking $booking, ?string $bookingItemId): void
    {
        if ($booking->bookingItems->count() <= 1) {
            return;
        }

        if (!$bookingItemId) {
            throw new \DomainException(
                'booking_item_id is required for every multi-item dispatch, return, and completion action.'
            );
        }

        if (!$booking->bookingItems->contains(
            fn (BookingItem $item): bool => (string) ($item->getAttributes()['id'] ?? $item->getKey()) === (string) $bookingItemId
        )) {
            throw new \InvalidArgumentException('Selected booking item does not belong to this booking');
        }
    }

    private function markBookingItemCompleted(?BookingItem $bookingItem, array $completionData): void
    {
        if (!$bookingItem || $bookingItem->completed_at) {
            return;
        }

        $completedAt = Carbon::now('UTC');
        $bookingItem->update([
            'status' => 'completed',
            'final_priced_at' => $bookingItem->final_priced_at ?: $completedAt,
            'completed_at' => $completedAt,
            'lifecycle_data' => array_merge(
                is_array($bookingItem->lifecycle_data) ? $bookingItem->lifecycle_data : [],
                $completionData,
                [
                    'stage' => 'completed',
                    'completed_at' => $completedAt->toIso8601String(),
                ]
            ),
        ]);
    }

    private function updateItemQcState(?BookingItem $bookingItem, BookingQC $qc, string $stage): void
    {
        if (!$bookingItem) {
            return;
        }

        $bookingItem->update([
            'lifecycle_data' => array_merge(
                is_array($bookingItem->lifecycle_data) ? $bookingItem->lifecycle_data : [],
                [
                    'stage' => $stage,
                    'qc_id' => (string) $qc->id,
                    'qc_status' => $qc->qc_status?->value,
                    'qc_inspection_started_at' => $qc->inspection_started_at?->toIso8601String(),
                    'qc_inspection_completed_at' => $qc->inspection_completed_at?->toIso8601String(),
                    'qc_updated_at' => Carbon::now('UTC')->toIso8601String(),
                ]
            ),
        ]);
    }

    /**
     * Promote the booking to its aggregate terminal state only when every
     * billable item is complete. Cancelled/rejected items are already terminal
     * and are not required to carry a completion timestamp.
     */
    private function finalizeMultiItemBookingIfReady(
        Booking $booking,
        string $actorUserId,
        array $completionData
    ): bool {
        $items = BookingItem::query()
            ->where('booking_id', $booking->id)
            ->lockForUpdate()
            ->get();
        $requiredItems = $items->reject(
            fn (BookingItem $item): bool => in_array((string) $item->status, ['cancelled', 'rejected'], true)
        );

        if ($requiredItems->isEmpty() || $requiredItems->contains(fn (BookingItem $item): bool => !$item->completed_at)) {
            return false;
        }

        if ((string) $booking->status === 'completed' && $booking->completed_at) {
            return false;
        }

        $fromStatus = $booking->getLifecycleStatus();
        $completedAt = Carbon::now('UTC');
        $booking->update([
            'status' => 'completed',
            'completed_at' => $completedAt,
            'updated_user_id' => $actorUserId,
            'workflow_data' => array_merge(
                is_array($booking->workflow_data) ? $booking->workflow_data : [],
                [
                    'completed_via' => 'all_booking_items_terminal',
                    'completed_at' => $completedAt->toIso8601String(),
                    'completed_booking_item_ids' => $requiredItems->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                ]
            ),
        ]);

        $this->logLifecycleTransition(
            $booking,
            $fromStatus,
            BookingLifecycleStatus::COMPLETED,
            array_merge($completionData, [
                'source' => 'all_booking_items_terminal',
                'booking_item_id' => null,
            ])
        );

        return true;
    }

    private function logItemLifecycleEvent(
        Booking $booking,
        ?BookingItem $bookingItem,
        string $stage,
        array $data = []
    ): void {
        if (!$bookingItem) {
            return;
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'booking_item_lifecycle_transitioned',
            'entity' => 'BookingItem',
            'entity_id' => $bookingItem->id,
            'timestamp' => Carbon::now('UTC'),
            'details' => [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'booking_item_id' => $bookingItem->id,
                'stage' => $stage,
                'source' => $data['source'] ?? $data['activity_source'] ?? 'booking_lifecycle',
            ],
        ]);
    }

    private function runAggregateCompletionEffects(Booking $booking, bool $suppressCompletionEmails = false): void
    {
        if ($suppressCompletionEmails) {
            Log::info('Aggregate completion invoice email suppressed', [
                'booking_id' => $booking->id,
            ]);
        } elseif ($this->bookingHasPendingPricingReview($booking)) {
            Log::warning('Aggregate invoice deferred: one or more items have final pricing pending manual review', [
                'booking_id' => $booking->id,
            ]);
        } else {
            try {
                $this->invoiceService->generateAndSend($booking->fresh([
                    'customer.user',
                    'bookingItems.serviceType',
                    'bookingItems.vehicle.group',
                    'bookingItems.driver.user',
                    'bookingAddons',
                ]));
            } catch (\Throwable $e) {
                Log::error('Aggregate invoice generation failed after all booking items completed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->loyaltyService->awardPointsForBooking($booking->fresh());
        } catch (\Throwable $e) {
            Log::error('Loyalty points award failed on aggregate booking completion', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->agentCommissionService->recordForBooking($booking->fresh());
        } catch (\Throwable $e) {
            Log::error('Agent commission recording failed on aggregate booking completion', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($booking->is_corporate_booking && $booking->corporate_account_id) {
            $auditTimestamp = Carbon::now('UTC');
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'corporate_booking_completed',
                'entity' => 'Booking',
                'entity_id' => $booking->id,
                'timestamp' => $auditTimestamp,
                'details' => [
                    'corporate_id' => $booking->corporate_account_id,
                    'employee_id' => $booking->employee_id,
                    'booking_number' => $booking->booking_number,
                    'completed_at' => $auditTimestamp->toIso8601String(),
                ],
            ]);
        }
    }

    private function buildFinalCalculationExample(
        array $result,
        float $manualCharges,
        float $lateFee,
        float $finalBase,
        array $currencyContext
    ): array {
        $exchangeRate = (float) ($currencyContext['exchange_rate'] ?? 1);
        $lines = collect($result['breakdown'] ?? [])->map(function ($line) use ($exchangeRate) {
            $calculationAmount = (float) ($line['amount'] ?? 0);
            return [
                'label' => $line['name'] ?? $line['description'] ?? $line['component'] ?? 'Charge',
                'calculation' => $line['calculation'] ?? null,
                'calculation_currency_amount' => $calculationAmount,
                'amount' => round($calculationAmount * $exchangeRate, 2),
            ];
        })->values()->all();

        if ($manualCharges > 0) {
            $lines[] = [
                'label' => 'Operational charges',
                'calculation' => 'Approved return/completion charges',
                'calculation_currency_amount' => $manualCharges,
                'amount' => round($manualCharges * $exchangeRate, 2),
            ];
        }
        if ($lateFee > 0) {
            $lines[] = [
                'label' => 'Late return fee',
                'calculation' => 'Configured late-return rule',
                'calculation_currency_amount' => $lateFee,
                'amount' => round($lateFee * $exchangeRate, 2),
            ];
        }

        return [
            'formula_evaluation' => $result['formula_evaluation'] ?? null,
            'adjustments' => $result['adjustment_details']['adjustments'] ?? [],
            'calculation_currency' => $currencyContext['calculation_currency'] ?? null,
            'booking_currency' => $currencyContext['booking_currency'] ?? null,
            'exchange_rate' => $exchangeRate,
            'lines' => $lines,
            'total' => $finalBase,
        ];
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
            'dispatches.vehicle',
            'dispatches.driver.user',
            'dispatch.dispatchedBy',
            'dispatch.returnedBy',
            'dispatches',
            'qc.inspector',
            'qcs.inspector',
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
        $itemQc = $this->resolveItemQc($booking, $context);
        $itemDispatch = $this->resolveItemDispatch($booking, $context);
        $currentStatus = $this->resolveSelectedItemLifecycleStatus(
            $booking,
            $context['booking_item'],
            $itemDispatch,
            $itemQc
        );
        $nextActions = array_map(static fn (BookingLifecycleStatus $status): array => [
            'status' => $status->value,
            'display_name' => $status->getDisplayName(),
            'action' => $status->getPrimaryAction(),
            'color' => $status->getColor(),
        ], $currentStatus->getNextStatuses());
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
        if (($lifecycleContract['lifecycle_status'] ?? null) !== $currentStatus->value) {
            $this->healthMonitor?->recordLifecycleMismatch([
                'booking_id' => (string) $booking->id,
                'booking_item_id' => $context['booking_item_id'],
                'summary_status' => $currentStatus->value,
                'contract_status' => $lifecycleContract['lifecycle_status'] ?? null,
            ]);
        }
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
            'stage_progress' => $this->getStageProgress($booking, $workflowSettings, $currentStatus),
            'timeline' => $this->getLifecycleTimeline($booking, $itemDispatch, $itemQc),
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
            'dispatches',
            'qc',
            'qcs',
            'bookingItems.serviceType',
            'bookingItems.vehicle',
            'bookingItems.driver.user',
        ]);

        $context = $this->resolveLifecycleContext($booking, $bookingItemId);
        $itemDispatch = $this->resolveItemDispatch($booking, $context);
        $itemQc = $this->resolveItemQc($booking, $context);
        $currentStatus = $this->resolveSelectedItemLifecycleStatus(
            $booking,
            $context['booking_item'],
            $itemDispatch,
            $itemQc
        );
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
            'dispatch_status' => $this->enumValue($itemDispatch?->dispatch_status),
            'qc_status' => $this->enumValue($itemQc?->qc_status),
            'driver_trip_phase' => $this->enumValue($driverAssignment?->trip_phase),
            'approval_status' => $this->resolveApprovalStatus($booking),
            'payment_collection_status' => $booking->payment_collection_status ?? $booking->payment_status ?? 'pending',
            'allowed_actions' => $allowedActions,
            'blocking_reasons' => $blockingReasons,
        ];
    }

    private function resolveSelectedItemLifecycleStatus(
        Booking $booking,
        ?BookingItem $bookingItem,
        ?BookingDispatch $dispatch,
        ?BookingQC $qc = null
    ): BookingLifecycleStatus {
        if ((string) $booking->status === 'completed') {
            return BookingLifecycleStatus::COMPLETED;
        }

        if ($bookingItem?->completed_at) {
            return BookingLifecycleStatus::COMPLETED;
        }

        if ($qc) {
            return match ($qc->qc_status) {
                QCStatus::PENDING => BookingLifecycleStatus::QC_PENDING,
                QCStatus::IN_PROGRESS => BookingLifecycleStatus::QC_IN_PROGRESS,
                QCStatus::ISSUES_FOUND => BookingLifecycleStatus::QC_ISSUES_FOUND,
                QCStatus::REPAIR_REQUIRED => BookingLifecycleStatus::QC_REPAIR_NEEDED,
                QCStatus::COMPLETED => BookingLifecycleStatus::QC_COMPLETED,
            };
        }

        if ($bookingItem?->returned_at || $dispatch?->isReturned()) {
            return BookingLifecycleStatus::RETURN_COMPLETED;
        }

        if ($dispatch) {
            return match ($dispatch->dispatch_status) {
                DispatchStatus::READY_FOR_DISPATCH => BookingLifecycleStatus::DISPATCH_READY,
                DispatchStatus::DISPATCHED, DispatchStatus::IN_PROGRESS => BookingLifecycleStatus::ONGOING_ACTIVE,
                default => $booking->getLifecycleStatus(),
            };
        }

        return $booking->getLifecycleStatus();
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
            BookingLifecycleStatus::RETURN_SCHEDULED,
            BookingLifecycleStatus::RETURN_OVERDUE,
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
            $blockingReasons[] = (string) $booking->status === 'completed'
                ? 'Booking is already completed'
                : 'Selected booking item is complete; the booking is waiting for its remaining items';
        } elseif ($currentStatus === BookingLifecycleStatus::CANCELLED) {
            $blockingReasons[] = 'Booking is cancelled';
        }

        $financialBlockingReasons = $this->resolveFinancialLifecycleBlockingReasons($booking);
        if ($financialBlockingReasons !== []) {
            $actions = array_values(array_diff($actions, ['dispatch_vehicle', 'complete_booking']));
            $blockingReasons = array_merge($blockingReasons, $financialBlockingReasons);
        }

        return [array_values(array_unique($actions)), array_values(array_unique($blockingReasons))];
    }

    /**
     * Only explicit prepaid failures and disputed account settlements block the
     * operational lifecycle. Driver collection, pay-at-end, customer credit,
     * and monthly invoicing remain valid post-trip settlement arrangements.
     *
     * @return string[]
     */
    private function resolveFinancialLifecycleBlockingReasons(Booking $booking): array
    {
        $reasons = [];
        $collectionStatus = strtolower((string) ($booking->payment_collection_status ?? ''));
        $paymentStatus = strtolower((string) ($booking->payment_status ?? ''));
        $method = strtolower((string) ($booking->payment_collection_method ?? ''));

        if (in_array($collectionStatus, ['failed', 'declined'], true)
            || in_array($paymentStatus, ['failed', 'declined'], true)) {
            $reasons[] = 'Payment collection failed and must be resolved before dispatch or completion';
        }

        if ($method === 'online') {
            $outstanding = is_numeric($booking->amount_to_pay)
                ? (float) $booking->amount_to_pay
                : max(0, (float) ($booking->total_actual ?? $booking->total_estimated ?? 0)
                    - (float) ($booking->payment_collected_amount ?? 0));
            if ($outstanding > 0 && !in_array($collectionStatus, ['online_paid', 'paid'], true)) {
                $reasons[] = 'Online payment remains due before dispatch or completion';
            }
        }

        if (Schema::hasTable('financial_settlement_items') && Schema::hasTable('financial_account_settlements')) {
            $hasDisputedSettlement = FinancialSettlementItem::query()
                ->where('booking_id', $booking->id)
                ->whereHas('settlement', fn ($query) => $query->where('status', 'disputed'))
                ->exists();
            if ($hasDisputedSettlement) {
                $reasons[] = 'The account settlement dispute must be resolved before booking completion';
            }
        }

        return array_values(array_unique($reasons));
    }

    private function assertFinancialLifecycleReady(Booking $booking, string $operation): void
    {
        $reasons = $this->resolveFinancialLifecycleBlockingReasons($booking);
        if ($reasons !== []) {
            throw new \DomainException(ucfirst($operation) . ' is blocked: ' . implode('; ', $reasons));
        }
    }

    /**
     * Get stage progress for UI
     */
    private function getStageProgress(
        Booking $booking,
        array $workflowSettings,
        ?BookingLifecycleStatus $selectedStatus = null
    ): array
    {
        $currentStatus = $selectedStatus ?? $booking->getLifecycleStatus();
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
    private function getLifecycleTimeline(
        Booking $booking,
        ?BookingDispatch $selectedDispatch = null,
        ?BookingQC $selectedQc = null
    ): array
    {
        $timeline = [];
        $dispatch = $selectedDispatch;
        $qc = $selectedQc;

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
        if ($dispatch?->dispatched_at) {
            $timeline[] = [
                'event' => 'Vehicle Dispatched',
                'timestamp' => $this->toUtcIsoTimestamp($dispatch->dispatched_at),
                'user' => $dispatch->dispatchedBy?->name ?? 'System',
                'status' => 'dispatched',
            ];
        }

        // Vehicle returned
        if ($dispatch?->actual_return_at) {
            $timeline[] = [
                'event' => 'Vehicle Returned',
                'timestamp' => $this->toUtcIsoTimestamp($dispatch->actual_return_at),
                'user' => $dispatch->returnedBy?->name ?? 'System',
                'status' => 'returned',
            ];
        }

        // QC completed
        if ($qc?->inspection_completed_at) {
            $timeline[] = [
                'event' => 'QC Inspection Completed',
                'timestamp' => $this->toUtcIsoTimestamp($qc->inspection_completed_at),
                'user' => $qc->inspector?->name ?? 'System',
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
    public function getOngoingDetails(string $bookingId, ?string $bookingItemId = null): ?array
    {
        $booking = Booking::with([
            'dispatch.vehicle',
            'dispatch.driver.user',
            'dispatches.vehicle',
            'dispatches.driver.user',
            'customer',
            'bookingItems.vehicle',
            'bookingItems.driver.user',
        ])->findOrFail($bookingId);
        $context = $this->resolveLifecycleContext($booking, $bookingItemId);

        $dispatch = $this->resolveItemDispatch($booking, $context);
        if (!$dispatch) {
            return null;
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
    public function getDispatchDetails(string $bookingId, ?string $bookingItemId = null): ?array
    {
        $booking = Booking::with([
            'dispatch.vehicle',
            'dispatch.driver.user',
            'dispatches.vehicle',
            'dispatches.driver.user',
            'bookingItems.vehicle',
            'bookingItems.driver.user',
        ])->findOrFail($bookingId);
        $context = $this->resolveLifecycleContext($booking, $bookingItemId);

        $dispatch = $this->resolveItemDispatch($booking, $context);
        if (!$dispatch) {
            return null;
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
            'qcs.repairItems',
            'qcs.inspector',
            'bookingItems',
        ])->findOrFail($bookingId);
        $context = $this->resolveLifecycleContext($booking, $bookingItemId);

        $qc = $this->resolveItemQc($booking, $context);

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
                'damages_found' => $qc->damages_found,
                'issues_reported' => $qc->issues_reported,
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

    private function bookingDateTime(mixed $date, mixed $time = null): Carbon
    {
        $timestamp = $date instanceof Carbon
            ? $date->copy()
            : Carbon::parse((string) $date);

        if ($time) {
            $timestamp->setTimeFromTimeString((string) $time);
        }

        return $timestamp;
    }
}
