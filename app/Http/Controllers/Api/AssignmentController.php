<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AssignmentService;
use App\Services\BookingFlowService;
use App\Services\ContractualDistanceSnapshotProjector;
use App\Services\BookingPaymentLedgerService;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingPaymentReceipt;
use App\Models\Driver\RoutePoint;
use App\Models\Vehicle\VehicleAddon;
use App\Models\Booking\BookingAddon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AssignmentController extends Controller
{
    protected AssignmentService $assignmentService;
    protected BookingFlowService $bookingFlowService;

    public function __construct(
        AssignmentService $assignmentService,
        BookingFlowService $bookingFlowService,
        private readonly ContractualDistanceSnapshotProjector $distanceSnapshotProjector,
        private readonly BookingPaymentLedgerService $paymentLedger,
    ) {
        $this->assignmentService = $assignmentService;
        $this->bookingFlowService = $bookingFlowService;
    }

    /**
     * Get assignment details for booking (for Assignment Management screen)
     */
    public function getAssignmentDetails(Request $request, string $bookingId): JsonResponse
    {
        try {
            $includeTracking = filter_var(
                $request->query('include_tracking', true),
                FILTER_VALIDATE_BOOLEAN
            );
            $trackingLimit = (int) $request->query('tracking_limit', 300);
            $trackingLimit = max(20, min($trackingLimit, 1000));

            $booking = Booking::with([
                'customer.user',
                'vehicle.vehicleGroup',
                'driver.user',
                'vehicleAssignments.vehicle',
                'driverAssignments.driver.user',
                'bookingItems.serviceType',
                'bookingItems.vehicleGroup',
                'bookingItems.vehicle.vehicleGroup',
                'bookingItems.driver.user',
                'dispatch',
            ])->findOrFail($bookingId);

            $requestedBookingItemId = $request->query('booking_item_id');
            $selectedBookingItem = null;
            $selectionWarning = null;

            if (!empty($requestedBookingItemId)) {
                $selectedBookingItem = $booking->bookingItems->firstWhere('id', $requestedBookingItemId);

                if (!$selectedBookingItem) {
                    $staleBookingItem = \App\Models\Booking\BookingItem::withTrashed()
                        ->whereKey($requestedBookingItemId)
                        ->where('booking_id', $booking->id)
                        ->first();

                    if (!$staleBookingItem || !$staleBookingItem->trashed()) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Selected booking item does not belong to this booking',
                        ], 422);
                    }

                    $selectedBookingItem = $booking->bookingItems
                        ->sortBy(fn ($item) => sprintf('%08d-%s', (int) ($item->trip_number ?? 0), (string) $item->id))
                        ->first();
                    $selectionWarning = $selectedBookingItem
                        ? 'The selected trip was replaced by a booking update. The current trip has been opened.'
                        : null;

                    if (!$selectedBookingItem) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'This booking has no active booking items',
                        ], 422);
                    }
                }
            } else {
                $selectedBookingItem = $booking->bookingItems
                    ->sortBy(function ($item) {
                        return sprintf(
                            '%08d-%s',
                            (int) ($item->trip_number ?? 0),
                            (string) ($item->id ?? '')
                        );
                    })
                    ->first();
            }

            // A booking item is the assignment owner for a trip. A null value on
            // that item means "assign later" and must not inherit another trip's
            // legacy booking-level vehicle or driver.
            $selectedVehicle = $selectedBookingItem
                ? $selectedBookingItem->vehicle
                : $booking->vehicle;
            $selectedDriver = $selectedBookingItem
                ? $selectedBookingItem->driver
                : $booking->driver;
            $selectedVehicleGroup = $selectedBookingItem?->vehicleGroup
                ?? $selectedVehicle?->vehicleGroup;
            $selectedServiceType = $selectedBookingItem?->serviceType
                ?? $booking->bookingItems
                    ->filter(fn ($item) => !empty($item->service_type_id))
                    ->sortBy(function ($item) {
                        return sprintf(
                            '%08d-%s',
                            (int) ($item->trip_number ?? 0),
                            (string) ($item->id ?? '')
                        );
                    })
                    ->first()?->serviceType;
            $customerName = trim((string) (
                $booking->customer?->full_name
                ?? $booking->customer?->name
                ?? (
                    ($booking->customer?->user?->first_name ?? '')
                    . ' '
                    . ($booking->customer?->user?->last_name ?? '')
                )
            ));
            if ($customerName === '') {
                $customerName = 'Unknown Customer';
            }

            $pickupLocation = $selectedBookingItem?->pickup_location ?? $booking->pickup_location;
            $dropoffLocation = $selectedBookingItem?->dropoff_location ?? $booking->dropoff_location;

            if (is_string($pickupLocation)) {
                $decodedPickup = json_decode($pickupLocation, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedPickup)) {
                    $pickupLocation = $decodedPickup;
                }
            }

            if (is_string($dropoffLocation)) {
                $decodedDropoff = json_decode($dropoffLocation, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedDropoff)) {
                    $dropoffLocation = $decodedDropoff;
                }
            }

            $pickupLatitude = $selectedBookingItem?->pickup_latitude
                ?? (is_array($pickupLocation) ? ($pickupLocation['latitude'] ?? $pickupLocation['lat'] ?? null) : null);
            $pickupLongitude = $selectedBookingItem?->pickup_longitude
                ?? (is_array($pickupLocation) ? ($pickupLocation['longitude'] ?? $pickupLocation['lng'] ?? null) : null);
            $dropoffLatitude = $selectedBookingItem?->dropoff_latitude
                ?? (is_array($dropoffLocation) ? ($dropoffLocation['latitude'] ?? $dropoffLocation['lat'] ?? null) : null);
            $dropoffLongitude = $selectedBookingItem?->dropoff_longitude
                ?? (is_array($dropoffLocation) ? ($dropoffLocation['longitude'] ?? $dropoffLocation['lng'] ?? null) : null);

            $driverAssignments = $booking->driverAssignments;
            if ($selectedBookingItem?->id) {
                $driverAssignments = $driverAssignments->filter(function ($assignment) use ($selectedBookingItem) {
                    return (string) $assignment->booking_item_id === (string) $selectedBookingItem->id;
                });
            }

            $approvalTriggers = $this->bookingFlowService->getApprovalTriggersForBooking($booking, $selectedBookingItem);
            $approvalReasons = $this->bookingFlowService->formatApprovalTriggerLabels($approvalTriggers);
            if ((bool) (($booking->requires_approval ?? false) || (($booking->status ?? null) === 'pending_approval')) && empty($approvalReasons)) {
                $approvalReasons = ['Manager approval required'];
            }

            $tripAssignment = $this->resolveTrackingAssignment(
                $driverAssignments,
                $selectedBookingItem?->id
            );
            $trackingPayload = $this->buildTrackingPayload(
                $tripAssignment,
                $selectedDriver,
                $includeTracking,
                $trackingLimit,
                $selectedBookingItem,
                $pickupLocation,
                $dropoffLocation,
                $pickupLatitude,
                $pickupLongitude,
                $dropoffLatitude,
                $dropoffLongitude,
                $booking->dispatch
            );
            $pricingMetrics = $this->buildPricingMetrics($selectedBookingItem, $tripAssignment);
            $contractualDistanceSnapshot = $this->distanceSnapshotProjector->projectForInternal(
                is_array($selectedBookingItem?->pricing_breakdown)
                    ? $selectedBookingItem->pricing_breakdown
                    : (is_array($booking->pricing_snapshot) ? $booking->pricing_snapshot : [])
            );
            $customerUser = $booking->customer?->user;

            $result = [
                'booking' => [
                    'id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'reference_number' => $booking->confirmation_number ?? $booking->invoice_number ?? $booking->booking_number,
                    'booking_source' => $booking->booking_source ?? $booking->created_from,
                    'customer_name' => $customerName,
                    'customer_email' => $booking->customer?->email ?? $customerUser?->email,
                    'customer_phone' => $booking->customer?->phone ?? $booking->customer?->mobile ?? $customerUser?->phone,
                    'service_type_id' => $selectedBookingItem?->service_type_id ?? $selectedServiceType?->id,
                    'service_type' => $selectedServiceType ? [
                        'id' => $selectedServiceType->id,
                        'name' => $selectedServiceType->name,
                        'code' => $selectedServiceType->code,
                        'type' => $selectedServiceType->type ?? null,
                    ] : null,
                    'from_date' => $selectedBookingItem?->from_date ?? $booking->from_date,
                    'to_date' => $selectedBookingItem?->to_date ?? $booking->to_date,
                    'from_time' => $selectedBookingItem?->from_time ?? $booking->from_time,
                    'to_time' => $selectedBookingItem?->to_time ?? $booking->to_time,
                    'pickup_location' => $pickupLocation,
                    'dropoff_location' => $dropoffLocation,
                    'pickup_latitude' => $pickupLatitude,
                    'pickup_longitude' => $pickupLongitude,
                    'dropoff_latitude' => $dropoffLatitude,
                    'dropoff_longitude' => $dropoffLongitude,
                    'status' => $booking->status,
                    'approval_status' => $booking->approval_status,
                    'payment_status' => $booking->payment_status,
                    'payment_method' => $booking->payment_method,
                    'payment_collection_method' => $booking->payment_collection_method,
                    'booking_item_id' => $selectedBookingItem?->id,
                    'trip_number' => $selectedBookingItem?->trip_number,
                    'trip_count' => $booking->bookingItems->count(),
                    'duration_days' => $pricingMetrics['duration_days'],
                    'duration_hours' => $pricingMetrics['duration_hours'],
                    'duration_minutes' => $pricingMetrics['duration_minutes'],
                    'hire_km' => $pricingMetrics['hire_km'],
                    'waiting_hours' => $pricingMetrics['waiting_hours'],
                    'waiting_minutes' => $pricingMetrics['waiting_minutes'],
                    'waiting_charge' => $pricingMetrics['waiting_charge'],
                    'total_price' => $pricingMetrics['total_amount'],
                    'booking_total_amount' => (float) ($booking->total_actual ?? $booking->total_estimated ?? 0),
                    'currency' => $pricingMetrics['currency'],
                    'created_at' => $booking->created_at?->toIso8601String(),
                    'updated_at' => $booking->updated_at?->toIso8601String(),
                ],
                'selected_booking_item_id' => $selectedBookingItem?->id,
                'selection_warning' => $selectionWarning,
                'selected_trip_number' => $selectedBookingItem?->trip_number,
                'booking_items' => $booking->bookingItems
                    ->sortBy(fn ($item) => sprintf('%08d-%s', (int) ($item->trip_number ?? 0), (string) $item->id))
                    ->values()
                    ->map(fn ($item) => [
                        'id' => $item->id,
                        'trip_number' => $item->trip_number,
                        'item_code' => $item->item_code ?? null,
                        'service_name' => $item->serviceType?->name,
                        'from_date' => $item->from_date,
                        'from_time' => $item->from_time,
                        'to_date' => $item->to_date,
                        'to_time' => $item->to_time,
                        'status' => $item->completed_at ? 'completed' : ($item->status ?? 'pending'),
                        'vehicle_name' => $item->vehicle?->title,
                        'driver_name' => $item->driver?->user
                            ? trim((string) $item->driver->user->first_name . ' ' . (string) $item->driver->user->last_name)
                            : null,
                    ])->all(),
                'pricing_metrics' => $pricingMetrics,
                'contractual_distance_snapshot' => $contractualDistanceSnapshot,
                'approval_context' => [
                    'requires_approval' => (bool) (($booking->requires_approval ?? false) || (($booking->status ?? null) === 'pending_approval')),
                    'triggers' => $approvalTriggers,
                    'reasons' => $approvalReasons,
                    'status' => $booking->approval_status,
                    'justification' => $booking->approval_justification,
                ],
                'payment_summary' => $this->paymentLedger->summary($booking),
                'payment_account_summary' => $this->paymentLedger->accountSummary($booking),
                'current_vehicle' => $selectedVehicle ? [
                    'id' => $selectedVehicle->id,
                    'name' => $selectedVehicle->title,
                    'license_plate' => $selectedVehicle->license_plate,
                    'availability_status' => $selectedVehicle->availability_status,
                    'vehicle_group' => $selectedVehicle->vehicleGroup ? [
                        'id' => $selectedVehicle->vehicleGroup->id,
                        'name' => $selectedVehicle->vehicleGroup->name,
                    ] : null,
                ] : null,
                'current_vehicle_group' => $selectedVehicleGroup ? [
                    'id' => $selectedVehicleGroup->id,
                    'name' => $selectedVehicleGroup->name,
                ] : null,
                'current_driver' => $selectedDriver ? [
                    'id' => $selectedDriver->id,
                    'name' => $selectedDriver->user ? 
                        $selectedDriver->user->first_name . ' ' . $selectedDriver->user->last_name 
                        : 'Unknown Driver',
                    'license_number' => $selectedDriver->license_no,
                    'is_online' => (bool) ($selectedDriver->is_online ?? false),
                    'last_active_at' => $selectedDriver->last_active_at?->toIso8601String(),
                    'current_latitude' => $selectedDriver->current_latitude !== null
                        ? (float) $selectedDriver->current_latitude
                        : null,
                    'current_longitude' => $selectedDriver->current_longitude !== null
                        ? (float) $selectedDriver->current_longitude
                        : null,
                ] : null,
                'assignments' => [
                    'vehicle' => $booking->vehicleAssignments
                        ->filter(function ($assignment) use ($selectedBookingItem, $selectedVehicle) {
                            if ($selectedBookingItem?->id) {
                                return (string) $assignment->booking_item_id === (string) $selectedBookingItem->id;
                            }

                            return !$selectedVehicle
                                || (string) $assignment->vehicle_id === (string) $selectedVehicle->id;
                        })
                        ->values()
                        ->map(function($assignment) {
                        return [
                            'id' => $assignment->id,
                            'vehicle_id' => $assignment->vehicle_id,
                            'status' => $assignment->status,
                            'assignment_type' => $assignment->assignment_type,
                            'requires_approval' => $assignment->requires_approval,
                            'manually_confirmed' => $assignment->manually_confirmed,
                            'assigned_from' => $assignment->assigned_from,
                            'assigned_to' => $assignment->assigned_to,
                            'booking_item_id' => $assignment->booking_item_id,
                            'vehicle' => $assignment->vehicle ? [
                                'id' => $assignment->vehicle->id,
                                'name' => $assignment->vehicle->title,
                                'license_plate' => $assignment->vehicle->license_plate,
                            ] : null,
                        ];
                    }),
                    'driver' => $driverAssignments->map(function($assignment) {
                        return [
                            'id' => $assignment->id,
                            'driver_id' => $assignment->driver_id,
                            'status' => $assignment->status,
                            'assignment_type' => $assignment->assignment_type,
                            'requires_approval' => $assignment->requires_approval,
                            'manually_confirmed' => $assignment->manually_confirmed,
                            'assigned_from' => $assignment->assigned_from,
                            'assigned_to' => $assignment->assigned_to,
                            'booking_item_id' => $assignment->booking_item_id,
                            'decline_reason' => $assignment->decline_reason,
                            'driver' => $assignment->driver ? [
                                'id' => $assignment->driver->id,
                                'name' => $assignment->driver->user ? 
                                    $assignment->driver->user->first_name . ' ' . $assignment->driver->user->last_name 
                                    : 'Unknown Driver',
                                'license_number' => $assignment->driver->license_no,
                            ] : null,
                        ];
                    }),
                ],
                'tracking' => $trackingPayload,
            ];

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Assignment details retrieved successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error getting assignment details: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get assignment details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function receivePayment(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:cash,card,bank_transfer,online,cheque,driver_cash,other'],
            'payment_stage' => ['required', 'in:deposit,advance,part_payment,final_payment,account_payment'],
            'payment_purpose' => ['sometimes', 'in:booking_payment,service_deposit,security_deposit'],
            'reference' => ['nullable', 'string', 'max:120'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $booking = Booking::findOrFail($bookingId);
        $summary = $this->paymentLedger->receive($booking, $data, Auth::id());

        return response()->json([
            'status' => 'success',
            'message' => 'Payment received and outstanding balance updated.',
            'data' => $summary,
        ]);
    }

    public function addPaymentScheduleItem(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'due_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $booking = Booking::findOrFail($bookingId);
        $summary = $this->paymentLedger->addScheduleItem($booking, $data, Auth::id());

        return response()->json([
            'status' => 'success',
            'message' => 'Payment due date added to the booking schedule.',
            'data' => $summary,
        ], 201);
    }

    public function generatePaymentSchedule(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate([
            'frequency' => ['required', 'in:full_payment,monthly,every_two_months,every_six_months'],
            'start_date' => ['required', 'date'],
            'installment_amount' => ['nullable', 'required_unless:frequency,full_payment', 'numeric', 'gt:0'],
            'reminder_days' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);
        $booking = Booking::findOrFail($bookingId);
        return response()->json([
            'status' => 'success',
            'message' => 'Automatic payment schedule generated.',
            'data' => $this->paymentLedger->generateSchedule($booking, $data, Auth::id()),
        ], 201);
    }

    public function setCommissionOwner(Request $request, string $bookingId): JsonResponse
    {
        $data = $request->validate([
            'commission_owner_staff_id' => ['nullable', 'uuid', 'exists:staff,id'],
        ]);
        $booking = Booking::findOrFail($bookingId);
        abort_unless((bool) $booking->is_corporate_booking, 422, 'An explicit commission owner is only needed for corporate bookings.');
        $booking->update(['commission_owner_staff_id' => $data['commission_owner_staff_id'] ?? null]);
        return response()->json([
            'status' => 'success',
            'message' => $booking->commission_owner_staff_id
                ? 'Corporate booking commission owner assigned.'
                : 'Corporate booking commission owner cleared.',
        ]);
    }

    public function refundSecurityDeposit(Request $request, string $bookingId, BookingPaymentReceipt $receipt): JsonResponse
    {
        abort_unless($receipt->booking_id === $bookingId, 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'refund_method' => ['required', 'in:cash,card,bank_transfer,online,cheque,other'],
            'reference' => ['nullable', 'string', 'max:120'],
            'refunded_at' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $booking = Booking::findOrFail($bookingId);
        return response()->json(['status' => 'success', 'message' => 'Refundable deposit refund recorded.', 'data' => $this->paymentLedger->refundSecurityDeposit($booking, $receipt, $data, Auth::id())]);
    }

    private function buildPricingMetrics($bookingItem, $assignment = null): array
    {
        $pricingBreakdown = is_array($bookingItem?->pricing_breakdown) ? $bookingItem->pricing_breakdown : [];
        $metadata = is_array($bookingItem?->metadata) ? $bookingItem->metadata : [];
        $distanceDetails = $metadata['distance_details']
            ?? $pricingBreakdown['distance_details']
            ?? $pricingBreakdown['base_pricing']['distance_details']
            ?? null;
        $distanceDetails = is_array($distanceDetails) ? $distanceDetails : [];
        $kmCalculations = $pricingBreakdown['km_calculations']
            ?? $pricingBreakdown['base_pricing']['km_calculations']
            ?? ($distanceDetails['km_calculations'] ?? null);
        $kmCalculations = is_array($kmCalculations) ? $kmCalculations : [];
        $summary = is_array($pricingBreakdown['summary'] ?? null) ? $pricingBreakdown['summary'] : [];
        $basePricing = is_array($pricingBreakdown['base_pricing'] ?? null) ? $pricingBreakdown['base_pricing'] : [];
        $finalPricing = is_array($pricingBreakdown['final_pricing'] ?? null)
            ? $pricingBreakdown['final_pricing']
            : [];
        $finalAuditInputs = is_array($finalPricing['audit']['inputs'] ?? null)
            ? $finalPricing['audit']['inputs']
            : [];
        $finalDistanceDetails = is_array($finalPricing['distance_details'] ?? null)
            ? $finalPricing['distance_details']
            : [];
        $finalKmCalculations = is_array($finalPricing['km_calculations'] ?? null)
            ? $finalPricing['km_calculations']
            : [];
        $variables = $finalPricing['calculation_metadata']['resolved_variables']
            ?? $finalPricing['calculation_metadata']['variables_used']
            ?? $pricingBreakdown['calculation_metadata']['resolved_variables']
            ?? $basePricing['calculation_metadata']['resolved_variables']
            ?? $pricingBreakdown['calculation_metadata']['variables_used']
            ?? $basePricing['calculation_metadata']['variables_used']
            ?? [];
        $variables = is_array($variables) ? $variables : [];

        $hireKm = $this->firstNumeric([
            $finalAuditInputs['distance_km'] ?? null,
            $finalDistanceDetails['journey_distance'] ?? null,
            $finalDistanceDetails['actual_journey_distance'] ?? null,
            $finalKmCalculations['journey_distance'] ?? null,
            $assignment?->total_distance_km,
            $distanceDetails['journey_distance'] ?? null,
            $distanceDetails['actual_journey_distance'] ?? null,
            $kmCalculations['journey_distance'] ?? null,
            $kmCalculations['actual_journey_distance'] ?? null,
            $pricingBreakdown['total_journey_distance_km'] ?? null,
            $metadata['total_journey_distance_km'] ?? null,
        ]);

        $waitingSeconds = $assignment?->total_waiting_time_seconds;
        $waitingHours = $this->firstNumeric([
            isset($finalAuditInputs['waiting_minutes'])
                ? ((float) $finalAuditInputs['waiting_minutes'] / 60)
                : null,
            $variables['waiting_hours'] ?? null,
            $pricingBreakdown['waiting_hours'] ?? null,
            $basePricing['waiting_hours'] ?? null,
            $waitingSeconds !== null ? ((int) $waitingSeconds / 3600) : null,
        ]);
        $waitingMinutes = $this->firstNumeric([
            $finalAuditInputs['waiting_minutes'] ?? null,
            $variables['waiting_minutes'] ?? null,
            $pricingBreakdown['waiting_minutes'] ?? null,
            $basePricing['waiting_minutes'] ?? null,
            $waitingSeconds !== null ? ((int) $waitingSeconds / 60) : null,
            $waitingHours !== null ? $waitingHours * 60 : null,
        ]);
        $actualDurationMinutes = $this->firstNumeric([
            $finalAuditInputs['duration_minutes'] ?? null,
            $assignment?->trip_started_at && $assignment?->trip_completed_at
                ? $assignment->trip_started_at->diffInSeconds($assignment->trip_completed_at) / 60
                : null,
            $bookingItem?->duration_minutes,
            $bookingItem?->duration_hours !== null ? (int) $bookingItem->duration_hours * 60 : null,
        ]);

        $waitingRate = $this->firstNumeric([
            $variables['waiting_charge_per_hour'] ?? null,
            $variables['waiting_rate_per_hour'] ?? null,
            $pricingBreakdown['waiting_charge_per_hour'] ?? null,
            $basePricing['waiting_charge_per_hour'] ?? null,
        ]);

        $waitingCharge = $this->firstNumeric([
            $pricingBreakdown['waiting_charge'] ?? null,
            $basePricing['waiting_charge'] ?? null,
            $waitingHours !== null && $waitingRate !== null ? $waitingHours * $waitingRate : null,
        ]);

        return [
            'currency' => $bookingItem?->currency,
            'base_amount' => $this->firstNumeric([
                $summary['base_total'] ?? null,
                $basePricing['base_amount'] ?? null,
                $basePricing['total_amount_without_customizations'] ?? null,
                $bookingItem?->unit_price,
            ]),
            'total_amount' => $this->firstNumeric([
                $finalPricing['audit']['final_base'] ?? null,
                $finalPricing['total_amount'] ?? null,
                $summary['grand_total'] ?? null,
                $summary['total'] ?? null,
                $basePricing['total_amount'] ?? null,
                $bookingItem?->total_price,
            ]),
            'duration_days' => isset($finalAuditInputs['duration_minutes'])
                ? (int) ceil((float) $finalAuditInputs['duration_minutes'] / 1440)
                : ($bookingItem?->duration_days !== null ? (int) $bookingItem->duration_days : null),
            'duration_hours' => isset($finalAuditInputs['duration_minutes'])
                ? round((float) $finalAuditInputs['duration_minutes'] / 60, 3)
                : ($bookingItem?->duration_hours !== null ? (int) $bookingItem->duration_hours : null),
            'duration_minutes' => $actualDurationMinutes,
            'included_duration_days' => $bookingItem?->duration_days !== null ? (int) $bookingItem->duration_days : null,
            'included_duration_hours' => $bookingItem?->duration_hours !== null ? (int) $bookingItem->duration_hours : null,
            'included_duration_minutes' => $bookingItem?->duration_minutes !== null
                ? (int) $bookingItem->duration_minutes
                : ($bookingItem?->duration_hours !== null ? (int) $bookingItem->duration_hours * 60 : null),
            'journey_duration_seconds' => $this->firstNumeric([
                isset($finalAuditInputs['duration_minutes'])
                    ? ((float) $finalAuditInputs['duration_minutes'] * 60)
                    : null,
                $assignment?->trip_started_at && $assignment?->trip_completed_at
                    ? $assignment->trip_started_at->diffInSeconds($assignment->trip_completed_at)
                    : null,
                $metadata['journey_duration_seconds'] ?? null,
                $distanceDetails['journey_duration_seconds'] ?? null,
                $distanceDetails['duration_seconds'] ?? null,
            ]),
            'hire_km' => $hireKm,
            'included_km' => $this->firstNumeric([
                $finalDistanceDetails['allowed_km'] ?? null,
                $finalKmCalculations['allowed_km'] ?? null,
                $distanceDetails['allowed_km'] ?? null,
                $kmCalculations['allowed_km'] ?? null,
                $distanceDetails['included_km'] ?? null,
            ]),
            'extra_km' => $this->firstNumeric([
                $finalDistanceDetails['extra_km'] ?? null,
                $finalKmCalculations['extra_km'] ?? null,
                $distanceDetails['extra_km'] ?? null,
                $kmCalculations['extra_km'] ?? null,
            ]),
            'waiting_hours' => $waitingHours,
            'waiting_minutes' => $waitingMinutes,
            'pickup_waiting_minutes' => $this->firstNumeric([
                $finalAuditInputs['pickup_waiting_minutes'] ?? null,
                $assignment?->pickup_waiting_time_seconds !== null ? (int) $assignment->pickup_waiting_time_seconds / 60 : null,
            ]),
            'hire_waiting_minutes' => $this->firstNumeric([
                $finalAuditInputs['hire_waiting_minutes'] ?? null,
                $assignment?->hire_waiting_time_seconds !== null ? (int) $assignment->hire_waiting_time_seconds / 60 : null,
            ]),
            'total_waiting_minutes' => $this->firstNumeric([
                $finalAuditInputs['total_waiting_minutes'] ?? null,
                $waitingMinutes,
            ]),
            'waiting_rate_per_hour' => $waitingRate,
            'waiting_charge' => $waitingCharge,
            'pricing_breakdown' => $pricingBreakdown,
            'distance_details' => $distanceDetails ?: null,
        ];
    }

    private function firstNumeric(array $values): ?float
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return round((float) $value, 3);
            }
        }

        return null;
    }

    private function resolveTrackingAssignment($driverAssignments, ?string $selectedBookingItemId)
    {
        if ($driverAssignments->isEmpty()) {
            return null;
        }

        if ($selectedBookingItemId) {
            $itemScoped = $driverAssignments
                ->filter(fn ($assignment) => (string) $assignment->booking_item_id === (string) $selectedBookingItemId)
                ->values();

            if ($itemScoped->isNotEmpty()) {
                $driverAssignments = $itemScoped;
            }
        }

        $activeAssignment = $driverAssignments->first(function ($assignment) {
            $phase = $assignment->trip_phase?->value ?? (string) $assignment->trip_phase;
            return in_array($phase, ['accepted', 'pickup_arrived', 'in_progress'], true);
        });

        if ($activeAssignment) {
            return $activeAssignment;
        }

        return $driverAssignments
            ->sortByDesc(function ($assignment) {
                return $assignment->assigned_from
                    ? strtotime((string) $assignment->assigned_from)
                    : 0;
            })
            ->first();
    }

    private function buildTrackingPayload(
        $tripAssignment,
        $selectedDriver,
        bool $includeTracking,
        int $trackingLimit,
        $selectedBookingItem = null,
        $pickupLocation = null,
        $dropoffLocation = null,
        $pickupLatitude = null,
        $pickupLongitude = null,
        $dropoffLatitude = null,
        $dropoffLongitude = null,
        $bookingDispatch = null
    ): array
    {
        $livePayload = [
            'driver_id' => $selectedDriver?->id,
            'driver_name' => $selectedDriver
                ? trim((string) (
                    $selectedDriver->user?->first_name . ' ' . $selectedDriver->user?->last_name
                ))
                : null,
            'is_online' => (bool) ($selectedDriver->is_online ?? false),
            'last_active_at' => $selectedDriver?->last_active_at?->toIso8601String(),
            'latitude' => $selectedDriver && $selectedDriver->current_latitude !== null
                ? (float) $selectedDriver->current_latitude
                : null,
            'longitude' => $selectedDriver && $selectedDriver->current_longitude !== null
                ? (float) $selectedDriver->current_longitude
                : null,
            'has_location' => $selectedDriver
                ? ($selectedDriver->current_latitude !== null && $selectedDriver->current_longitude !== null)
                : false,
        ];

        $assignmentPayload = null;
        $operationalRecords = [
            'dispatch' => $bookingDispatch ? [
                'status' => $bookingDispatch->dispatch_status instanceof \BackedEnum
                    ? $bookingDispatch->dispatch_status->value
                    : (string) $bookingDispatch->dispatch_status,
                'dispatched_at' => $this->toUtcIsoTimestamp($bookingDispatch->dispatched_at),
                'actual_return_at' => $this->toUtcIsoTimestamp($bookingDispatch->actual_return_at),
                'source' => 'booking_dispatch',
            ] : null,
            'assignment' => null,
            'route' => [
                'source' => 'driver_route_points',
                'pricing_effect' => 'none',
                'total_points' => 0,
                'first_recorded_at' => null,
                'latest_recorded_at' => null,
            ],
        ];
        $routePayload = [
            'total_points' => 0,
            'returned_points' => 0,
            'truncated' => false,
            'pre_pickup_points' => 0,
            'post_pickup_points' => 0,
            'distance_summary' => [
                'km_to_pickup' => null,
                'trip_km' => null,
                'tracked_total_km' => null,
                'recorded_trip_km' => null,
                'source' => 'not_available',
            ],
            'points' => [],
            'first_point' => null,
            'latest_point' => null,
            'reference_points' => [
                'accept' => null,
                'current_driver' => null,
                'planned_pickup' => null,
                'planned_dropoff' => null,
                'trip_start' => null,
                'pickup' => null,
                'dropoff' => null,
                'stops' => [],
            ],
            'lifecycle_location_compliance' => null,
        ];

        if ($tripAssignment) {
            $tripAssignment->loadMissing(['bookingItem', 'stops']);
            $assignmentPayload = [
                'id' => $tripAssignment->id,
                'booking_item_id' => $tripAssignment->booking_item_id,
                'status' => $tripAssignment->status,
                'trip_phase' => $tripAssignment->trip_phase?->value ?? (string) $tripAssignment->trip_phase,
                'created_at' => $this->toUtcIsoTimestamp($tripAssignment->created_at),
                'scheduled_from' => $this->bookingItemDateTime(
                    $tripAssignment->bookingItem ?? $selectedBookingItem,
                    'from'
                ),
                'assigned_from' => $this->toUtcIsoTimestamp($tripAssignment->assigned_from),
                'assigned_to' => $this->toUtcIsoTimestamp($tripAssignment->assigned_to),
                'confirmed_at' => $this->toUtcIsoTimestamp($tripAssignment->confirmed_at),
                'actual_start' => $this->toUtcIsoTimestamp($tripAssignment->actual_start),
                'actual_end' => $this->toUtcIsoTimestamp($tripAssignment->actual_end),
                'trip_started_at' => $this->toUtcIsoTimestamp($tripAssignment->trip_started_at),
                'trip_start_latitude' => $tripAssignment->trip_start_latitude !== null ? (float) $tripAssignment->trip_start_latitude : null,
                'trip_start_longitude' => $tripAssignment->trip_start_longitude !== null ? (float) $tripAssignment->trip_start_longitude : null,
                'trip_completed_at' => $this->toUtcIsoTimestamp($tripAssignment->trip_completed_at),
                'pickup_arrived_at' => $this->toUtcIsoTimestamp($tripAssignment->pickup_arrived_at),
                'total_distance_km' => $tripAssignment->total_distance_km !== null
                    ? (float) $tripAssignment->total_distance_km
                    : null,
                'total_waiting_time_seconds' => $tripAssignment->total_waiting_time_seconds !== null
                    ? (int) $tripAssignment->total_waiting_time_seconds
                    : null,
                'pickup_arrival_latitude' => $tripAssignment->pickup_arrival_latitude !== null
                    ? (float) $tripAssignment->pickup_arrival_latitude
                    : null,
                'pickup_arrival_longitude' => $tripAssignment->pickup_arrival_longitude !== null
                    ? (float) $tripAssignment->pickup_arrival_longitude
                    : null,
                'final_latitude' => $tripAssignment->final_latitude !== null
                    ? (float) $tripAssignment->final_latitude
                    : null,
                'final_longitude' => $tripAssignment->final_longitude !== null
                    ? (float) $tripAssignment->final_longitude
                    : null,
                'stops' => $this->mapPersistedAssignmentStops($tripAssignment),
                'events' => $this->buildAssignmentEventTimeline(
                    $tripAssignment,
                    $tripAssignment->bookingItem ?? $selectedBookingItem
                ),
            ];
            $operationalRecords['assignment'] = [
                'accepted_at' => $this->toUtcIsoTimestamp($tripAssignment->confirmed_at),
                'arrived_at_pickup_at' => $this->toUtcIsoTimestamp($tripAssignment->pickup_arrived_at),
                'trip_started_at' => $this->toUtcIsoTimestamp($tripAssignment->trip_started_at ?? $tripAssignment->actual_start),
                'trip_start_latitude' => $tripAssignment->trip_start_latitude !== null ? (float) $tripAssignment->trip_start_latitude : null,
                'trip_start_longitude' => $tripAssignment->trip_start_longitude !== null ? (float) $tripAssignment->trip_start_longitude : null,
                'trip_completed_at' => $this->toUtcIsoTimestamp($tripAssignment->trip_completed_at ?? $tripAssignment->actual_end),
                'pickup_arrival_latitude' => $tripAssignment->pickup_arrival_latitude !== null ? (float) $tripAssignment->pickup_arrival_latitude : null,
                'pickup_arrival_longitude' => $tripAssignment->pickup_arrival_longitude !== null ? (float) $tripAssignment->pickup_arrival_longitude : null,
                'final_latitude' => $tripAssignment->final_latitude !== null ? (float) $tripAssignment->final_latitude : null,
                'final_longitude' => $tripAssignment->final_longitude !== null ? (float) $tripAssignment->final_longitude : null,
                'source' => 'driver_assignment',
            ];

            $firstPoint = null;
            $latestPoint = null;
            $distanceSummary = [
                'km_to_pickup' => null,
                'trip_km' => $tripAssignment->total_distance_km !== null ? (float) $tripAssignment->total_distance_km : null,
                'tracked_total_km' => null,
                'recorded_trip_km' => $tripAssignment->total_distance_km !== null ? (float) $tripAssignment->total_distance_km : null,
                'source' => $tripAssignment->total_distance_km !== null ? 'driver_assignment' : 'not_available',
            ];

            if ($includeTracking) {
                $routePointsQuery = RoutePoint::query()
                    ->where(function ($query) use ($tripAssignment, $selectedDriver) {
                        $query->where('assignment_id', $tripAssignment->id);

                        $trackingStart = $tripAssignment->assigned_from ?? $tripAssignment->confirmed_at;
                        $trackingEnd = $tripAssignment->trip_completed_at
                            ?? $tripAssignment->assigned_to
                            ?? now();

                        if ($selectedDriver && $trackingStart && $trackingEnd) {
                            $query->orWhere(function ($unassignedQuery) use ($selectedDriver, $trackingStart, $trackingEnd) {
                                $unassignedQuery
                                    ->whereNull('assignment_id')
                                    ->whereBetween('recorded_at', [$trackingStart, $trackingEnd])
                                    ->whereHas('session', function ($sessionQuery) use ($selectedDriver) {
                                        $sessionQuery->where('driver_id', $selectedDriver->id);
                                    });
                            });
                        }
                    });

                $totalPoints = (clone $routePointsQuery)->count();
                $points = $routePointsQuery
                    ->orderByDesc('recorded_at')
                    ->limit($trackingLimit)
                    ->get([
                        'id',
                        'session_id',
                        'assignment_id',
                        'latitude',
                        'longitude',
                        'altitude',
                        'speed',
                        'heading',
                        'accuracy',
                        'recorded_at',
                        'created_at',
                    ])
                    ->sortBy('recorded_at')
                    ->values()
                    ->map(function ($point) use ($tripAssignment) {
                        return [
                            'id' => $point->id,
                            'session_id' => $point->session_id,
                            'assignment_id' => $point->assignment_id,
                            'tracking_phase' => $this->classifyTrackingPointPhase($point, $tripAssignment),
                            'latitude' => $point->latitude !== null ? (float) $point->latitude : null,
                            'longitude' => $point->longitude !== null ? (float) $point->longitude : null,
                            'altitude' => $point->altitude !== null ? (float) $point->altitude : null,
                            'speed' => $point->speed !== null ? (float) $point->speed : null,
                            'heading' => $point->heading !== null ? (float) $point->heading : null,
                            'accuracy' => $point->accuracy !== null ? (float) $point->accuracy : null,
                            'recorded_at' => $this->toUtcIsoTimestamp($point->recorded_at),
                            'created_at' => $this->toUtcIsoTimestamp($point->created_at),
                        ];
                    })
                    ->all();

                $calculatedDistances = $this->calculateTrackingDistanceSummary($points);
                $distanceSummary = [
                    'km_to_pickup' => $calculatedDistances['km_to_pickup'],
                    'trip_km' => $tripAssignment->total_distance_km !== null
                        ? (float) $tripAssignment->total_distance_km
                        : $calculatedDistances['trip_km'],
                    'tracked_total_km' => $calculatedDistances['tracked_total_km'],
                    'recorded_trip_km' => $tripAssignment->total_distance_km !== null
                        ? (float) $tripAssignment->total_distance_km
                        : null,
                    'source' => $tripAssignment->total_distance_km !== null
                        ? 'driver_assignment_with_tracking_split'
                        : 'route_points',
                ];

                $firstPoint = count($points) > 0 ? $points[0] : null;
                $latestPoint = count($points) > 0 ? $points[count($points) - 1] : null;
                $operationalRecords['route'] = [
                    'source' => 'driver_route_points',
                    'pricing_effect' => 'none',
                    'total_points' => $totalPoints,
                    'first_recorded_at' => $firstPoint['recorded_at'] ?? null,
                    'latest_recorded_at' => $latestPoint['recorded_at'] ?? null,
                ];

                $pickupArrivedAtTs = $tripAssignment->pickup_arrived_at?->timestamp;
                $phase = $tripAssignment->trip_phase?->value ?? (string) $tripAssignment->trip_phase;
                $prePickupPoints = 0;
                $postPickupPoints = 0;

                foreach ($points as $point) {
                    $recordedAtTs = isset($point['recorded_at']) && $point['recorded_at']
                        ? strtotime((string) $point['recorded_at'])
                        : false;

                    if ($pickupArrivedAtTs && $recordedAtTs !== false) {
                        if ($recordedAtTs <= $pickupArrivedAtTs) {
                            $prePickupPoints++;
                        } else {
                            $postPickupPoints++;
                        }
                        continue;
                    }

                    if ($phase === 'accepted') {
                        $prePickupPoints++;
                    } else {
                        $postPickupPoints++;
                    }
                }

                $routePayload = [
                    'total_points' => $totalPoints,
                    'returned_points' => count($points),
                    'truncated' => $totalPoints > count($points),
                    'pre_pickup_points' => $prePickupPoints,
                    'post_pickup_points' => $postPickupPoints,
                    'distance_summary' => $distanceSummary,
                    'points' => $points,
                    'first_point' => $firstPoint,
                    'latest_point' => $latestPoint,
                    'reference_points' => [
                        'accept' => null,
                        'current_driver' => null,
                        'pickup' => null,
                        'dropoff' => null,
                        'stops' => [],
                    ],
                ];
            }
            else {
                $routePayload['distance_summary'] = $distanceSummary;
            }

            $bookingItem = $tripAssignment->bookingItem ?: $selectedBookingItem;

            $pickupPoint = $this->buildMapPoint(
                $bookingItem?->pickup_location ?? $pickupLocation,
                $bookingItem?->pickup_latitude ?? $pickupLatitude,
                $bookingItem?->pickup_longitude ?? $pickupLongitude,
                $bookingItem?->pickup_landmark ?? null,
                'Pickup'
            );
            $dropoffPoint = $this->buildMapPoint(
                $bookingItem?->dropoff_location ?? $dropoffLocation,
                $bookingItem?->dropoff_latitude ?? $dropoffLatitude,
                $bookingItem?->dropoff_longitude ?? $dropoffLongitude,
                $bookingItem?->dropoff_landmark ?? null,
                'Drop-off'
            );
            $stopPoints = $this->mapPersistedAssignmentStops($tripAssignment);
            if (empty($stopPoints)) {
                $stopPoints = $this->extractStopPointsFromBookingItem($bookingItem);
            }

            $acceptPoint = null;
            if ($tripAssignment->confirmed_at) {
                if ($firstPoint && $this->isValidCoordinate($firstPoint['latitude'] ?? null, $firstPoint['longitude'] ?? null)) {
                    $acceptPoint = [
                        'label' => 'Driver Accepted',
                        'latitude' => (float) $firstPoint['latitude'],
                        'longitude' => (float) $firstPoint['longitude'],
                        'timestamp' => $this->toUtcIsoTimestamp($tripAssignment->confirmed_at),
                        'source' => 'route_point',
                    ];
                } elseif ($this->isValidCoordinate($livePayload['latitude'], $livePayload['longitude'])) {
                    $acceptPoint = [
                        'label' => 'Driver Accepted',
                        'latitude' => (float) $livePayload['latitude'],
                        'longitude' => (float) $livePayload['longitude'],
                        'timestamp' => $this->toUtcIsoTimestamp($tripAssignment->confirmed_at),
                        'source' => 'driver_live_location',
                    ];
                }
            }

            $currentDriverPoint = null;
            if ($this->isValidCoordinate($livePayload['latitude'], $livePayload['longitude'])) {
                $currentDriverPoint = [
                    'label' => $tripAssignment->confirmed_at ? 'Driver Current Location' : 'Driver Live Location',
                    'latitude' => (float) $livePayload['latitude'],
                    'longitude' => (float) $livePayload['longitude'],
                    'timestamp' => $livePayload['last_active_at'],
                    'source' => 'driver_live_location',
                ];
            } elseif ($latestPoint && $this->isValidCoordinate($latestPoint['latitude'] ?? null, $latestPoint['longitude'] ?? null)) {
                $currentDriverPoint = [
                    'label' => $tripAssignment->confirmed_at ? 'Driver Current Location' : 'Driver Last Known Location',
                    'latitude' => (float) $latestPoint['latitude'],
                    'longitude' => (float) $latestPoint['longitude'],
                    'timestamp' => $latestPoint['recorded_at'] ?? null,
                    'source' => 'route_point',
                ];
            }

            $pickupReference = null;
            if ($this->isValidCoordinate($tripAssignment->pickup_arrival_latitude, $tripAssignment->pickup_arrival_longitude)) {
                $pickupReference = [
                    'label' => 'Pickup Arrived',
                    'latitude' => (float) $tripAssignment->pickup_arrival_latitude,
                    'longitude' => (float) $tripAssignment->pickup_arrival_longitude,
                    'timestamp' => $this->toUtcIsoTimestamp($tripAssignment->pickup_arrived_at),
                    'source' => 'pickup_arrival',
                ];
            }

            $dropoffReference = null;
            if ($this->isValidCoordinate($tripAssignment->final_latitude, $tripAssignment->final_longitude)) {
                $dropoffReference = [
                    'label' => 'Trip End',
                    'latitude' => (float) $tripAssignment->final_latitude,
                    'longitude' => (float) $tripAssignment->final_longitude,
                    'timestamp' => $this->toUtcIsoTimestamp($tripAssignment->trip_completed_at),
                    'source' => 'trip_completion',
                ];
            }

            $tripStartReference = $this->buildActualLifecyclePoint(
                'Trip Started',
                $tripAssignment->trip_start_latitude,
                $tripAssignment->trip_start_longitude,
                $tripAssignment->trip_started_at,
                'trip_start'
            );

            $routePayload['reference_points'] = [
                'accept' => $acceptPoint,
                'current_driver' => $currentDriverPoint,
                'planned_pickup' => $pickupPoint,
                'planned_dropoff' => $dropoffPoint,
                'trip_start' => $tripStartReference,
                'pickup' => $pickupReference,
                'dropoff' => $dropoffReference,
                'stops' => $stopPoints,
            ];
            $routePayload['lifecycle_location_compliance'] = [
                'tolerance_meters' => (int) config('booking_observability.lifecycle_location_tolerance_meters', 250),
                'pickup' => $this->compareLifecycleLocation($pickupPoint, $pickupReference),
                'trip_start' => $this->compareLifecycleLocation($pickupPoint, $tripStartReference),
                'dropoff' => $this->compareLifecycleLocation($dropoffPoint, $dropoffReference),
                'pricing_effect' => 'none',
            ];
        }
        elseif ($selectedBookingItem) {
            $pickupPoint = $this->buildMapPoint(
                $selectedBookingItem->pickup_location ?? $pickupLocation,
                $selectedBookingItem->pickup_latitude ?? $pickupLatitude,
                $selectedBookingItem->pickup_longitude ?? $pickupLongitude,
                $selectedBookingItem->pickup_landmark ?? null,
                'Pickup'
            );
            $dropoffPoint = $this->buildMapPoint(
                $selectedBookingItem->dropoff_location ?? $dropoffLocation,
                $selectedBookingItem->dropoff_latitude ?? $dropoffLatitude,
                $selectedBookingItem->dropoff_longitude ?? $dropoffLongitude,
                $selectedBookingItem->dropoff_landmark ?? null,
                'Drop-off'
            );

            $routePayload['reference_points'] = [
                'accept' => null,
                'current_driver' => $this->isValidCoordinate($livePayload['latitude'], $livePayload['longitude']) ? [
                    'label' => 'Driver Live Location',
                    'latitude' => (float) $livePayload['latitude'],
                    'longitude' => (float) $livePayload['longitude'],
                    'timestamp' => $livePayload['last_active_at'],
                    'source' => 'driver_live_location',
                ] : null,
                'planned_pickup' => $pickupPoint,
                'planned_dropoff' => $dropoffPoint,
                'trip_start' => null,
                'pickup' => $pickupPoint ? [
                    ...$pickupPoint,
                    'timestamp' => null,
                ] : null,
                'dropoff' => $dropoffPoint ? [
                    ...$dropoffPoint,
                    'timestamp' => null,
                ] : null,
                'stops' => $this->extractStopPointsFromBookingItem($selectedBookingItem),
            ];
        }

        return [
            'enabled' => $includeTracking,
            'live' => $livePayload,
            'assignment' => $assignmentPayload,
            'route' => $routePayload,
            'operational_records' => $operationalRecords,
        ];
    }

    private function buildActualLifecyclePoint(string $label, $latitude, $longitude, $timestamp, string $source): ?array
    {
        if (!$this->isValidCoordinate($latitude, $longitude)) {
            return null;
        }

        return [
            'label' => $label,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'timestamp' => $this->toUtcIsoTimestamp($timestamp),
            'source' => $source,
        ];
    }

    private function compareLifecycleLocation(?array $planned, ?array $actual): array
    {
        $tolerance = (int) config('booking_observability.lifecycle_location_tolerance_meters', 250);
        if (!$planned || !$actual
            || !$this->isValidCoordinate($planned['latitude'] ?? null, $planned['longitude'] ?? null)
            || !$this->isValidCoordinate($actual['latitude'] ?? null, $actual['longitude'] ?? null)) {
            return ['status' => 'not_available', 'distance_meters' => null, 'within_tolerance' => null];
        }

        $earthRadius = 6371000;
        $lat1 = deg2rad((float) $planned['latitude']);
        $lat2 = deg2rad((float) $actual['latitude']);
        $deltaLat = $lat2 - $lat1;
        $deltaLng = deg2rad((float) $actual['longitude'] - (float) $planned['longitude']);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;
        $meters = (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));

        return [
            'status' => $meters <= $tolerance ? 'within_tolerance' : 'review_required',
            'distance_meters' => $meters,
            'within_tolerance' => $meters <= $tolerance,
        ];
    }

    private function classifyTrackingPointPhase($point, $assignment): string
    {
        $recordedAt = $point->recorded_at;
        if ($assignment?->confirmed_at && $recordedAt && $recordedAt->lt($assignment->confirmed_at)) {
            return 'before_accept';
        }

        if ($assignment?->pickup_arrived_at && $recordedAt && $recordedAt->gte($assignment->pickup_arrived_at)) {
            return 'pickup_to_dropoff';
        }

        $phase = $assignment?->trip_phase?->value ?? (string) $assignment?->trip_phase;
        if (!$assignment?->pickup_arrived_at && in_array($phase, ['pickup_arrived', 'in_progress', 'completed'], true)) {
            return 'pickup_to_dropoff';
        }

        return 'accepted_to_pickup';
    }

    private function buildAssignmentEventTimeline($assignment, $bookingItem = null): array
    {
        if (!$assignment) {
            return [];
        }

        $events = [
            [
                'key' => 'assigned',
                'label' => 'Assigned',
                'timestamp' => $this->toUtcIsoTimestamp($assignment->created_at),
                'source' => 'driver_assignment.created_at',
            ],
            [
                'key' => 'scheduled',
                'label' => 'Assignment scheduled',
                'timestamp' => $this->bookingItemDateTime($bookingItem, 'from')
                    ?? $this->toUtcIsoTimestamp($assignment->assigned_from),
                'source' => $this->bookingItemDateTime($bookingItem, 'from')
                    ? 'booking_item.from_date + from_time'
                    : 'driver_assignment.assigned_from',
            ],
            [
                'key' => 'confirmed',
                'label' => 'Driver accepted',
                'timestamp' => $this->toUtcIsoTimestamp($assignment->confirmed_at),
                'source' => 'driver_assignment.confirmed_at',
            ],
            [
                'key' => 'pickup_arrived',
                'label' => 'Arrived at pickup',
                'timestamp' => $this->toUtcIsoTimestamp($assignment->pickup_arrived_at),
                'source' => 'driver_assignment.pickup_arrived_at',
                'latitude' => $assignment->pickup_arrival_latitude !== null ? (float) $assignment->pickup_arrival_latitude : null,
                'longitude' => $assignment->pickup_arrival_longitude !== null ? (float) $assignment->pickup_arrival_longitude : null,
            ],
            [
                'key' => 'trip_started',
                'label' => 'Trip started',
                'timestamp' => $this->toUtcIsoTimestamp($assignment->trip_started_at ?? $assignment->actual_start),
                'source' => $assignment->trip_started_at ? 'driver_assignment.trip_started_at' : 'driver_assignment.actual_start',
                'latitude' => $assignment->trip_start_latitude !== null ? (float) $assignment->trip_start_latitude : null,
                'longitude' => $assignment->trip_start_longitude !== null ? (float) $assignment->trip_start_longitude : null,
            ],
            [
                'key' => 'trip_completed',
                'label' => 'Trip completed',
                'timestamp' => $this->toUtcIsoTimestamp($assignment->trip_completed_at ?? $assignment->actual_end),
                'source' => $assignment->trip_completed_at ? 'driver_assignment.trip_completed_at' : 'driver_assignment.actual_end',
                'latitude' => $assignment->final_latitude !== null ? (float) $assignment->final_latitude : null,
                'longitude' => $assignment->final_longitude !== null ? (float) $assignment->final_longitude : null,
            ],
        ];

        return collect($events)
            ->filter(fn ($event) => !empty($event['timestamp']))
            ->sortBy(fn ($event) => strtotime((string) $event['timestamp']) ?: 0)
            ->values()
            ->all();
    }

    private function bookingItemDateTime($bookingItem, string $boundary): ?string
    {
        $dateField = $boundary === 'to' ? 'to_date' : 'from_date';
        $timeField = $boundary === 'to' ? 'to_time' : 'from_time';
        $date = $bookingItem?->{$dateField};

        if (!$date) {
            return null;
        }

        $timestamp = $date instanceof Carbon
            ? $date->copy()
            : Carbon::parse((string) $date);
        $time = $bookingItem?->{$timeField};

        if ($time) {
            $timestamp->setTimeFromTimeString((string) $time);
        }

        return $this->toUtcIsoTimestamp($timestamp);
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

    private function calculateTrackingDistanceSummary(array $points): array
    {
        $kmToPickup = 0.0;
        $tripKm = 0.0;
        $trackedTotalKm = 0.0;
        $lastPoint = null;
        $hasPrePickupSegment = false;
        $hasTripSegment = false;

        foreach ($points as $point) {
            if (!$this->isValidCoordinate($point['latitude'] ?? null, $point['longitude'] ?? null)) {
                continue;
            }

            if ($lastPoint && $this->isValidCoordinate($lastPoint['latitude'] ?? null, $lastPoint['longitude'] ?? null)) {
                $segmentKm = $this->haversineDistanceKm(
                    (float) $lastPoint['latitude'],
                    (float) $lastPoint['longitude'],
                    (float) $point['latitude'],
                    (float) $point['longitude']
                );

                $trackedTotalKm += $segmentKm;

                $phase = $point['tracking_phase'] ?? $lastPoint['tracking_phase'] ?? null;
                if ($phase === 'pickup_to_dropoff') {
                    $tripKm += $segmentKm;
                    $hasTripSegment = true;
                } elseif ($phase === 'accepted_to_pickup') {
                    $kmToPickup += $segmentKm;
                    $hasPrePickupSegment = true;
                }
            }

            $lastPoint = $point;
        }

        return [
            'km_to_pickup' => $hasPrePickupSegment ? round($kmToPickup, 2) : null,
            'trip_km' => $hasTripSegment ? round($tripKm, 2) : null,
            'tracked_total_km' => $trackedTotalKm > 0 ? round($trackedTotalKm, 2) : null,
        ];
    }

    private function haversineDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function extractStopPointsFromBookingItem($bookingItem): array
    {
        if (!$bookingItem) {
            return [];
        }

        $metadata = is_array($bookingItem->metadata) ? $bookingItem->metadata : [];
        $orderedStops = $metadata['multi_route_stop_order'] ?? [];
        $orderedStops = $this->normalizeArrayPayload($orderedStops);

        if (empty($orderedStops)) {
            $pickupStops = $this->normalizeArrayPayload($metadata['multi_pickup_locations'] ?? []);
            $dropoffStops = $this->normalizeArrayPayload($metadata['multi_dropoff_locations'] ?? []);

            $fallbackOrder = 1;
            foreach ($pickupStops as $stop) {
                $stop['type'] = 'pickup';
                $stop['route_order'] = $stop['route_order'] ?? $fallbackOrder++;
                $orderedStops[] = $stop;
            }
            foreach ($dropoffStops as $stop) {
                $stop['type'] = 'dropoff';
                $stop['route_order'] = $stop['route_order'] ?? $fallbackOrder++;
                $orderedStops[] = $stop;
            }
        }

        $normalized = [];
        foreach ($orderedStops as $index => $stop) {
            if (!is_array($stop)) {
                continue;
            }

            $location = is_array($stop['location'] ?? null) ? $stop['location'] : $stop;
            $latitude = $this->toNullableFloat($location['latitude'] ?? $location['lat'] ?? null);
            $longitude = $this->toNullableFloat($location['longitude'] ?? $location['lng'] ?? null);

            if (!$this->isValidCoordinate($latitude, $longitude)) {
                continue;
            }

            $type = strtolower((string) ($stop['type'] ?? 'stop'));
            if (!in_array($type, ['pickup', 'dropoff'], true)) {
                $type = 'stop';
            }

            $routeOrder = isset($stop['route_order']) && is_numeric($stop['route_order'])
                ? (int) $stop['route_order']
                : ($index + 1);

            $address = $location['address']
                ?? $location['formatted_address']
                ?? $location['label']
                ?? $stop['label']
                ?? null;

            $normalized[] = [
                'booking_stop_id' => $stop['stop_id']
                    ?? $stop['stopId']
                    ?? $location['stop_id']
                    ?? $location['stopId']
                    ?? null,
                'type' => $type,
                'route_order' => $routeOrder,
                'label' => ucfirst($type) . ' Stop ' . $routeOrder,
                'address' => $address ? (string) $address : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        usort($normalized, function ($left, $right) {
            return (int) ($left['route_order'] ?? 0) <=> (int) ($right['route_order'] ?? 0);
        });

        $typeCounters = [];
        foreach ($normalized as $index => $stop) {
            $type = (string) ($stop['type'] ?? 'stop');
            $typeCounters[$type] = ($typeCounters[$type] ?? 0) + 1;
            $labelPrefix = $type === 'pickup' ? 'Pickup' : ($type === 'dropoff' ? 'Drop-off' : 'Stop');
            $normalized[$index]['type_sequence'] = $typeCounters[$type];
            $normalized[$index]['label'] = "{$labelPrefix} {$typeCounters[$type]}";
            $normalized[$index]['display_label'] = $normalized[$index]['label'];
        }

        return array_values($normalized);
    }

    private function mapPersistedAssignmentStops($assignment): array
    {
        if (!$assignment || !$assignment->relationLoaded('stops')) {
            return [];
        }

        return $assignment->stops
            ->map(function ($stop) {
                $displayLabel = $stop->label ?: (
                    $stop->stop_type === 'pickup'
                        ? 'Pickup ' . ($stop->type_sequence ?: $stop->route_order)
                        : 'Drop-off ' . ($stop->type_sequence ?: $stop->route_order)
                );

                return [
                    'id' => $stop->id,
                    'booking_stop_id' => $stop->booking_stop_id,
                    'type' => $stop->stop_type,
                    'type_sequence' => $stop->type_sequence !== null ? (int) $stop->type_sequence : null,
                    'route_order' => (int) $stop->route_order,
                    'status' => $stop->status,
                    'label' => $displayLabel,
                    'display_label' => $displayLabel,
                    'address' => $stop->address,
                    'latitude' => $stop->latitude !== null ? (float) $stop->latitude : null,
                    'longitude' => $stop->longitude !== null ? (float) $stop->longitude : null,
                    'arrived_at' => $this->toUtcIsoTimestamp($stop->arrived_at),
                    'completed_at' => $this->toUtcIsoTimestamp($stop->completed_at),
                    'completed_action' => $stop->completed_action,
                    'skip_reason' => $stop->skip_reason,
                ];
            })
            ->values()
            ->all();
    }

    private function buildMapPoint($location, $fallbackLat, $fallbackLng, ?string $fallbackLabel, string $defaultLabel): ?array
    {
        $decodedLocation = $location;
        if (is_string($decodedLocation)) {
            $decoded = json_decode($decodedLocation, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $decodedLocation = $decoded;
            } else {
                $decodedLocation = ['address' => $decodedLocation];
            }
        }

        $latitude = $this->toNullableFloat(
            (is_array($decodedLocation) ? ($decodedLocation['latitude'] ?? $decodedLocation['lat'] ?? null) : null) ?? $fallbackLat
        );
        $longitude = $this->toNullableFloat(
            (is_array($decodedLocation) ? ($decodedLocation['longitude'] ?? $decodedLocation['lng'] ?? null) : null) ?? $fallbackLng
        );

        if (!$this->isValidCoordinate($latitude, $longitude)) {
            return null;
        }

        $address = is_array($decodedLocation)
            ? ($decodedLocation['address'] ?? $decodedLocation['formatted_address'] ?? null)
            : null;
        $label = is_array($decodedLocation)
            ? ($decodedLocation['label'] ?? $decodedLocation['name'] ?? null)
            : null;

        return [
            'label' => $fallbackLabel ?: $label ?: $defaultLabel,
            'address' => $address ? (string) $address : null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'source' => 'booking_location',
        ];
    }

    private function normalizeArrayPayload($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            return [];
        }

        return is_array($value) ? $value : [];
    }

    private function toNullableFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function isValidCoordinate($latitude, $longitude): bool
    {
        return $latitude !== null && $longitude !== null
            && is_numeric($latitude) && is_numeric($longitude);
    }

    /**
     * Perform vehicle/driver swap with financial calculations
     */
    public function performSwap(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|string|exists:bookings,id',
            'booking_item_id' => 'nullable|string|exists:booking_items,id',
            'swap_type' => 'required|string|in:vehicle,driver,both',
            'new_vehicle_id' => 'nullable|string|exists:vehicles,id|required_if:swap_type,vehicle,both',
            'new_driver_id' => 'nullable|string|exists:drivers,id|required_if:swap_type,driver,both',
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
                $booking = Booking::with(['customer', 'bookingItems'])->findOrFail($request->booking_id);
                $selectedBookingItem = null;

                if ($request->filled('booking_item_id')) {
                    $selectedBookingItem = $booking->bookingItems->firstWhere('id', $request->booking_item_id);
                    if (!$selectedBookingItem) {
                        throw new \InvalidArgumentException('Selected booking item does not belong to this booking');
                    }
                } else {
                    $selectedBookingItem = $booking->bookingItems
                        ->sortBy(function ($item) {
                            return sprintf(
                                '%08d-%s',
                                (int) ($item->trip_number ?? 0),
                                (string) ($item->id ?? '')
                            );
                        })
                        ->first();
                }

                $swapTime = Carbon::now('UTC');
                $oldVehicleId = $selectedBookingItem?->vehicle_id ?? $booking->vehicle_id;
                $oldDriverId = $selectedBookingItem?->driver_id ?? $booking->driver_id;
                $newVehicleId = $request->new_vehicle_id;
                $newDriverId = $request->new_driver_id;
                $reason = $request->reason;
                $serviceTypeId = $selectedBookingItem?->service_type_id ?? $booking->service_type_id ?? $booking->service_type;
                $customerName = trim((string) (
                    $booking->customer?->full_name
                    ?? $booking->customer?->name
                    ?? (
                        ($booking->customer?->user?->first_name ?? '')
                        . ' '
                        . ($booking->customer?->user?->last_name ?? '')
                    )
                ));
                if ($customerName === '') {
                    $customerName = 'Unknown Customer';
                }

                // Close current assignments at swap time
                if ($oldVehicleId && ($request->swap_type === 'vehicle' || $request->swap_type === 'both')) {
                    $booking->vehicleAssignments()
                        ->where('vehicle_id', $oldVehicleId)
                        ->where('status', 'active')
                        ->update(['actual_end' => $swapTime]);
                }

                if ($oldDriverId && ($request->swap_type === 'driver' || $request->swap_type === 'both')) {
                    $driverAssignments = $booking->driverAssignments()
                        ->where('driver_id', $oldDriverId)
                        ->where('status', 'active')
                        ->when(
                            $selectedBookingItem?->id,
                            fn($query) => $query->where('booking_item_id', $selectedBookingItem->id)
                        );

                    $driverAssignments->update(['actual_end' => $swapTime]);
                }

                // Calculate remaining period from now to original end
                $originalEnd = Carbon::parse($selectedBookingItem?->to_date ?? $booking->to_date);
                $remainingHours = $swapTime->diffInHours($originalEnd);
                $remainingDays = max(1, (int) ceil($remainingHours / 24));

                $addons = [];

                // Create new assignments for remaining period
                if ($newVehicleId && ($request->swap_type === 'vehicle' || $request->swap_type === 'both')) {
                    $this->assignmentService->createVehicleAssignment([
                        'vehicle_id' => $newVehicleId,
                        'booking_id' => $booking->id,
                        'customer_name' => $customerName,
                        'service_type' => $serviceTypeId,
                        'assigned_from' => $swapTime,
                        'assigned_to' => $originalEnd,
                        'assignment_type' => 'primary',
                        'status' => 'active',
                        'requires_approval' => false,
                        'assignment_notes' => "Swap from vehicle {$oldVehicleId}. Reason: {$reason}",
                    ]);

                    if ($selectedBookingItem) {
                        $selectedBookingItem->update(['vehicle_id' => $newVehicleId]);
                    }
                    if (!$selectedBookingItem || $booking->bookingItems->count() <= 1) {
                        $booking->update(['vehicle_id' => $newVehicleId]);
                    }

                    // Calculate price difference for remaining period
                    $oldVehiclePricing = $this->calculateVehiclePricingForPeriod($oldVehicleId, $swapTime, $originalEnd);
                    $newVehiclePricing = $this->calculateVehiclePricingForPeriod($newVehicleId, $swapTime, $originalEnd);
                    $priceDiff = $newVehiclePricing - $oldVehiclePricing;

                    if ($priceDiff != 0) {
                        $addons[] = $this->createSwapAddon($booking->id, 'vehicle_price_difference', $priceDiff, 1);
                    }
                }

                if ($newDriverId && ($request->swap_type === 'driver' || $request->swap_type === 'both')) {
                    $this->assignmentService->createDriverAssignment([
                        'driver_id' => $newDriverId,
                        'booking_id' => $booking->id,
                        'booking_item_id' => $selectedBookingItem?->id,
                        'customer_name' => $customerName,
                        'service_type' => $serviceTypeId,
                        'assigned_from' => $swapTime,
                        'assigned_to' => $originalEnd,
                        'assignment_type' => 'primary',
                        'status' => 'active',
                        'requires_approval' => false,
                        'assignment_notes' => "Swap from driver {$oldDriverId}. Reason: {$reason}",
                    ]);

                    if ($selectedBookingItem) {
                        $selectedBookingItem->update(['driver_id' => $newDriverId]);
                    }
                    if (!$selectedBookingItem || $booking->bookingItems->count() <= 1) {
                        $booking->update(['driver_id' => $newDriverId]);
                    }
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

                $financialAdjustment = $this->applyBookingFinancialAdjustments(
                    $booking,
                    $addons,
                    'swap',
                    [
                        'swap_type' => $request->swap_type,
                        'reason' => $reason,
                        'booking_item_id' => $selectedBookingItem?->id,
                        'remaining_days' => $remainingDays,
                    ]
                );

                // Store swap record for audit
                $swapRecord = [
                    'booking_id' => $booking->id,
                    'booking_item_id' => $selectedBookingItem?->id,
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
                        'booking_item_id' => $selectedBookingItem?->id,
                        'swap_record' => $swapRecord,
                        'addons_created' => $addons,
                        'financial_adjustment' => $financialAdjustment,
                        'financial_summary' => $this->getBookingFinancialSummary($booking),
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
            'booking_item_id' => 'nullable|string|exists:booking_items,id',
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
                                'booking_item_id' => $request->booking_item_id,
                                'swap_type' => 'vehicle',
                                'new_vehicle_id' => $request->replacement_vehicle_id,
                                'reason' => 'vehicle_breakdown',
                                'notes' => $request->description,
                                'photos' => $request->photos,
                            ]));
                        }
                        break;
                }

                $financialAdjustment = $this->applyBookingFinancialAdjustments(
                    $booking,
                    $addons,
                    'breakdown',
                    [
                        'action' => $request->action,
                        'booking_item_id' => $request->booking_item_id,
                        'description' => $request->description,
                        'location' => $request->location,
                    ]
                );

                // Log breakdown incident
                $incidentRecord = [
                    'booking_id' => $booking->id,
                    'booking_item_id' => $request->booking_item_id,
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
                        'financial_adjustment' => $financialAdjustment,
                        'financial_summary' => $this->getBookingFinancialSummary($booking),
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
            $quantity = max(1, $quantity);

            // Create or find vehicle addon for this type
            $vehicleAddon = VehicleAddon::firstOrCreate([
                'name' => $this->getAddonName($type),
            ], [
                'description' => $this->getAddonDescription($type),
                'amount' => abs($amount),
                'rate_type' => 'flat',
                'billing_type' => 'per_day',
                'addon_type' => 'fee',
                'pricing_type' => 'fixed',
                'quantity_unit' => 'pieces',
            ]);

            // Create booking addon
            $bookingAddon = BookingAddon::create([
                'booking_id' => $bookingId,
                'addon_id' => $vehicleAddon->id,
                'qty' => $quantity,
                'rate' => $amount, // Can be negative for credits
                'amount' => $amount * $quantity,
                'label' => $this->getAddonName($type),
                'created_user_id' => Auth::id(),
            ]);

            return [
                'addon_id' => $bookingAddon->id,
                'booking_addon_id' => $bookingAddon->id,
                'vehicle_addon_id' => $vehicleAddon->id,
                'type' => $type,
                'quantity' => $quantity,
                'unit_amount' => $amount,
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

    private function applyBookingFinancialAdjustments(
        Booking $booking,
        array $addons,
        string $activityType,
        array $context = []
    ): array {
        $validAddons = array_values(array_filter($addons, function ($addon) {
            return is_array($addon) && array_key_exists('amount', $addon);
        }));

        $delta = round(
            array_reduce($validAddons, function ($carry, $addon) {
                return $carry + (float) ($addon['amount'] ?? 0);
            }, 0.0),
            2
        );

        $booking->refresh();

        $before = [
            'addons_cost' => (float) ($booking->addons_cost ?? 0),
            'total_estimated' => (float) ($booking->total_estimated ?? 0),
            'total_actual' => (float) ($booking->total_actual ?? $booking->total_estimated ?? 0),
            'amount_to_pay' => (float) ($booking->amount_to_pay ?? $booking->total_actual ?? $booking->total_estimated ?? 0),
        ];

        if (abs($delta) > 0.00001) {
            $booking->addons_cost = round($before['addons_cost'] + $delta, 2);
            $booking->total_estimated = round($before['total_estimated'] + $delta, 2);
            $booking->total_actual = round($before['total_actual'] + $delta, 2);
            $booking->amount_to_pay = round($before['amount_to_pay'] + $delta, 2);
        }

        $pricingSnapshot = is_array($booking->pricing_snapshot) ? $booking->pricing_snapshot : [];
        $summary = is_array($pricingSnapshot['summary'] ?? null) ? $pricingSnapshot['summary'] : [];
        $addonsPricing = is_array($pricingSnapshot['addons_pricing'] ?? null) ? $pricingSnapshot['addons_pricing'] : [];
        $discountSummary = is_array($pricingSnapshot['discount_summary'] ?? null) ? $pricingSnapshot['discount_summary'] : [];
        $finalBreakdown = is_array($pricingSnapshot['final_breakdown'] ?? null) ? $pricingSnapshot['final_breakdown'] : [];

        if (abs($delta) > 0.00001) {
            $summaryAddons = (float) ($summary['addons_total'] ?? $before['addons_cost']);
            $summaryTotal = (float) ($summary['total'] ?? $before['total_estimated']);
            $discountTotal = (float) ($discountSummary['total_discount_amount'] ?? $booking->discount_amount ?? 0);

            $summary['addons_total'] = round($summaryAddons + $delta, 2);
            $summary['total'] = round($summaryTotal + $delta, 2);
            $addonsPricing['addons_total'] = round(
                (float) ($addonsPricing['addons_total'] ?? $before['addons_cost']) + $delta,
                2
            );
            $finalBreakdown['final_amount'] = round($summary['total'] - $discountTotal, 2);
        }

        $pricingSnapshot['summary'] = $summary;
        $pricingSnapshot['addons_pricing'] = $addonsPricing;
        $pricingSnapshot['final_breakdown'] = $finalBreakdown;
        $booking->pricing_snapshot = $pricingSnapshot;

        $workflowData = is_array($booking->workflow_data) ? $booking->workflow_data : [];
        $activities = is_array($workflowData['operational_cost_activities'] ?? null)
            ? $workflowData['operational_cost_activities']
            : [];

        $activities[] = [
            'type' => $activityType,
            'delta' => $delta,
            'booking_item_id' => $context['booking_item_id'] ?? null,
            'addons' => array_map(function ($addon) {
                return [
                    'type' => $addon['type'] ?? null,
                    'amount' => (float) ($addon['amount'] ?? 0),
                    'quantity' => (int) ($addon['quantity'] ?? 1),
                ];
            }, $validAddons),
            'context' => $context,
            'performed_by' => Auth::id(),
            'performed_at' => now()->toISOString(),
        ];

        $workflowData['operational_cost_activities'] = array_slice($activities, -100);
        $booking->workflow_data = $workflowData;

        $booking->save();
        $booking->refresh();

        return [
            'delta' => $delta,
            'before' => $before,
            'after' => [
                'addons_cost' => (float) ($booking->addons_cost ?? 0),
                'total_estimated' => (float) ($booking->total_estimated ?? 0),
                'total_actual' => (float) ($booking->total_actual ?? 0),
                'amount_to_pay' => (float) ($booking->amount_to_pay ?? 0),
            ],
            'activity_type' => $activityType,
        ];
    }

    private function getBookingFinancialSummary(Booking $booking): array
    {
        $booking->refresh();
        $pricingSnapshot = is_array($booking->pricing_snapshot) ? $booking->pricing_snapshot : [];
        $summary = is_array($pricingSnapshot['summary'] ?? null) ? $pricingSnapshot['summary'] : [];
        $discountSummary = is_array($pricingSnapshot['discount_summary'] ?? null) ? $pricingSnapshot['discount_summary'] : [];
        $finalBreakdown = is_array($pricingSnapshot['final_breakdown'] ?? null) ? $pricingSnapshot['final_breakdown'] : [];

        return [
            'base_amount' => (float) ($booking->base_amount ?? 0),
            'addons_cost' => (float) ($booking->addons_cost ?? 0),
            'discount_amount' => (float) ($booking->discount_amount ?? 0),
            'tax_amount' => (float) ($booking->tax_amount ?? 0),
            'total_estimated' => (float) ($booking->total_estimated ?? 0),
            'total_actual' => (float) ($booking->total_actual ?? 0),
            'amount_to_pay' => (float) ($booking->amount_to_pay ?? 0),
            'currency' => $summary['currency'] ?? ($booking->currency ?? 'LKR'),
            'summary_total' => (float) ($summary['total'] ?? $booking->total_estimated ?? 0),
            'summary_addons_total' => (float) ($summary['addons_total'] ?? $booking->addons_cost ?? 0),
            'discount_summary_total' => (float) ($discountSummary['total_discount_amount'] ?? $booking->discount_amount ?? 0),
            'final_amount' => (float) ($finalBreakdown['final_amount'] ?? $booking->total_estimated ?? 0),
        ];
    }
}
