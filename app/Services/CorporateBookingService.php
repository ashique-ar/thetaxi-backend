<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
use App\Models\Booking\BookingItem;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Corporate\CorporateEmployeeLocation;
use App\Models\Customer;
use App\Models\Service\ServicePackage;
use App\Notifications\BookingLifecycleNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CorporateBookingService
{
    public function __construct(
        protected BookingFlowService $bookingFlowService,
        protected ContractualDistanceSnapshotProjector $distanceSnapshotProjector,
        protected CorporateSubmittedResponseProjector $submittedResponseProjector,
        protected CorporateFinancialProjectionService $financialProjection,
        protected CorporateBookingNotificationService $corporateBookingNotifications,
    )
    {
    }

    // ─── Booking Creation ─────────────────────────────────────────────

    /**
     * Create a corporate booking for an employee.
     */
    public function createBooking(CorporateEmployee $employee, array $data): Booking
    {
        $corporate = $employee->corporate;

        $data = $this->prepareCorporateBookingPayload($corporate, $data);
        $data['defer_confirmation'] = true;

        $creditApprovalRequired = $this->requiresCreditApproval($corporate, $data);

        return DB::transaction(function () use ($employee, $corporate, $data, $creditApprovalRequired) {
            $needsApproval = $corporate->approval_required || $creditApprovalRequired;

            $params = array_merge($data, [
                'customer_id' => $this->resolveCustomerIdForEmployee($employee),
                'is_corporate_booking' => true,
                'corporate_account_id' => $corporate->id,
                'employee_id' => $employee->user_id,
                'corporate_department_id' => $employee->department_id,
                'corporate_division_id' => $employee->division_id,
                'created_by_user_id' => Auth::id(),
            ]);

            if ($needsApproval) {
                $booking = $this->bookingFlowService->submitBookingForApproval($params);
            } else {
                $booking = $this->bookingFlowService->confirmBooking($params);
            }

            $this->applyCorporateRequestStatus($booking, $needsApproval, Auth::id());

            // Ensure corporate fields are set on the booking
            $booking->update([
                'is_corporate_booking' => true,
                'corporate_account_id' => $corporate->id,
                'employee_id' => $employee->user_id,
                'corporate_department_id' => $employee->department_id,
                'corporate_division_id' => $employee->division_id,
                'created_by_user_id' => Auth::id(),
            ]);

            $this->logAudit('corporate_booking_created', 'Booking', $booking->id, [
                'corporate_id' => $corporate->id,
                'employee_id' => $employee->user_id,
                'needs_approval' => $needsApproval,
                'credit_limit_approval_required' => $creditApprovalRequired,
            ]);

            if ($needsApproval) {
                $this->notifyApprovalRequested($booking, $employee);
            }

            $this->corporateBookingNotifications->notifyInternalTeam($booking);

            return $booking->fresh();
        });
    }

    /**
     * Create a corporate-funded booking for a passenger who is not an employee.
     */
    public function createGeneralBooking(CorporateEmployee $requester, array $data): Booking
    {
        $corporate = $requester->corporate;
        $data = $this->prepareCorporateBookingPayload($corporate, $data);
        $data['defer_confirmation'] = true;

        $creditApprovalRequired = $this->requiresCreditApproval($corporate, $data);

        return DB::transaction(function () use ($requester, $corporate, $data, $creditApprovalRequired) {
            $needsApproval = ($corporate->approval_required && ! $corporate->exempt_coordinator_from_approval)
                || $creditApprovalRequired;
            $params = array_merge($data, [
                'customer_id' => null,
                'is_corporate_booking' => true,
                'corporate_account_id' => $corporate->id,
                'employee_id' => null,
                'corporate_department_id' => null,
                'corporate_division_id' => null,
                'created_by_user_id' => $requester->user_id,
            ]);

            $booking = $needsApproval
                ? $this->bookingFlowService->submitBookingForApproval($params)
                : $this->bookingFlowService->confirmBooking($params);

            $this->applyCorporateRequestStatus($booking, $needsApproval, $requester->user_id);
            $booking->update([
                'is_corporate_booking' => true,
                'corporate_account_id' => $corporate->id,
                'employee_id' => null,
                'corporate_department_id' => null,
                'corporate_division_id' => null,
                'created_by_user_id' => $requester->user_id,
            ]);

            $this->logAudit('general_corporate_booking_created', 'Booking', $booking->id, [
                'corporate_id' => $corporate->id,
                'coordinator_id' => $requester->user_id,
                'needs_approval' => $needsApproval,
                'credit_limit_approval_required' => $creditApprovalRequired,
                'passenger' => data_get($data, 'corporate_contact.name'),
            ]);

            if ($needsApproval) {
                $this->notifyApprovalRequested($booking, $requester);
            }

            $this->corporateBookingNotifications->notifyInternalTeam($booking);

            return $booking->fresh();
        });
    }

    public function cancelRecurringBooking(Booking $booking, string $scope, string $userId, ?string $reason = null): array
    {
        return $this->bookingFlowService->cancelRecurringBooking($booking->id, $scope, $userId, $reason);
    }

    /**
     * Create a booking on behalf of another employee (coordinator flow).
     */
    public function createBookingForEmployee(
        CorporateEmployee $coordinator,
        CorporateEmployee $targetEmployee,
        array $data,
    ): Booking
    {
        $corporate = $coordinator->corporate;

        $data = $this->prepareCorporateBookingPayload($corporate, $data);
        $data['defer_confirmation'] = true;

        $creditApprovalRequired = $this->requiresCreditApproval($corporate, $data);

        return DB::transaction(function () use ($coordinator, $targetEmployee, $corporate, $data, $creditApprovalRequired) {
            $needsApproval = ($corporate->approval_required && ! $corporate->exempt_coordinator_from_approval)
                || $creditApprovalRequired;

            $params = array_merge($data, [
                'customer_id' => $this->resolveCustomerIdForEmployee($targetEmployee),
                'is_corporate_booking' => true,
                'corporate_account_id' => $corporate->id,
                'employee_id' => $targetEmployee->user_id,
                'corporate_department_id' => $targetEmployee->department_id,
                'corporate_division_id' => $targetEmployee->division_id,
                'created_by_user_id' => $coordinator->user_id,
            ]);

            if ($needsApproval) {
                $booking = $this->bookingFlowService->submitBookingForApproval($params);
            } else {
                $booking = $this->bookingFlowService->confirmBooking($params);
            }

            $this->applyCorporateRequestStatus($booking, $needsApproval, $coordinator->user_id);

            // Ensure corporate fields are set on the booking
            $booking->update([
                'is_corporate_booking' => true,
                'corporate_account_id' => $corporate->id,
                'employee_id' => $targetEmployee->user_id,
                'corporate_department_id' => $targetEmployee->department_id,
                'corporate_division_id' => $targetEmployee->division_id,
                'created_by_user_id' => $coordinator->user_id,
            ]);

            $this->logAudit('corporate_booking_created_for_employee', 'Booking', $booking->id, [
                'corporate_id' => $corporate->id,
                'coordinator_id' => $coordinator->user_id,
                'target_employee_id' => $targetEmployee->user_id,
                'needs_approval' => $needsApproval,
                'credit_limit_approval_required' => $creditApprovalRequired,
                'coordinator_exempt' => $corporate->exempt_coordinator_from_approval,
            ]);

            if ($needsApproval) {
                $this->notifyApprovalRequested($booking, $targetEmployee);
            }

            $this->corporateBookingNotifications->notifyInternalTeam($booking);

            return $booking->fresh();
        });
    }

    /**
     * Validate that the vehicle group is assigned to the corporate.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    private function validateVehicleGroup(Corporate $corporate, ?string $vehicleGroupId): void
    {
        if (!$vehicleGroupId) {
            return;
        }

        $assigned = $corporate->vehicleGroups()->where('vehicle_groups.id', $vehicleGroupId)->exists();

        if (!$assigned) {
            abort(422, 'The selected vehicle group is not assigned to your corporate.');
        }
    }

    public function createStaffTransportBooking(Corporate $corporate, array $data): Booking
    {
        $contactEmployee = $corporate->employees()->where('is_active', true)->findOrFail($data['contact_employee_id']);
        unset($data['contact_employee_id']);
        $data = $this->prepareCorporateBookingPayload($corporate, $data);
        // Program activation authorizes its recurring journeys; pricing and dispatch
        // still use the same booking flow as other corporate work.
        return $this->bookingFlowService->confirmBooking(array_merge($data, [
            'customer_id' => $this->resolveCustomerIdForEmployee($contactEmployee),
            'employee_id' => null,
            'is_corporate_booking' => true,
            'corporate_account_id' => $corporate->id,
            'created_by_user_id' => Auth::id(),
        ]));
    }

    public function previewStaffTransportPricing(Corporate $corporate, array $data): array
    {
        unset($data['contact_employee_id']);
        return $this->bookingFlowService->calculatePricing($this->prepareCorporateBookingPayload($corporate, $data) + [
            'is_corporate_booking' => true, 'corporate_account_id' => $corporate->id,
        ]);
    }

    public function amendStaffTransportBooking(Booking $booking, array $data): Booking
    {
        unset($data['contact_employee_id']);
        $data = $this->prepareCorporateBookingPayload($booking->corporateAccount, $data);
        return $this->bookingFlowService->updateBooking($booking->id, $data + [
            'is_corporate_booking' => true,
            'corporate_account_id' => $booking->corporate_account_id,
        ], []);
    }

    private function prepareCorporateBookingPayload(Corporate $corporate, array $data): array
    {
        $data['payment_collection_method'] = $data['payment_collection_method']
            ?? $corporate->default_payment_arrangement
            ?? 'monthly_invoice';
        $data['payment_responsibility'] = in_array($data['payment_collection_method'], ['monthly_invoice'], true)
            ? 'corporate'
            : 'customer';
        // Use the same canonical dynamic-field and booking-item normalization as
        // internal submissions before applying corporate ownership restrictions.
        $data = $this->bookingFlowService->normalizeDynamicCalculationParams($data);
        $items = $this->bookingItemsFromPayload($data);

        foreach ($items as $index => $item) {
            $serviceTypeId = $item['service_type_id'] ?? ($item['service_type'] ?? null);
            $this->validateVehicleGroup($corporate, $item['vehicle_group_id'] ?? null);
            $this->validateServiceType($corporate, $serviceTypeId);
            $this->validateServicePackage(
                $serviceTypeId,
                $item['service_package_id'] ?? ($item['package_id'] ?? data_get($item, 'metadata.service_package_id')),
            );
            $this->validateStopOwnership($corporate, is_array($item['metadata'] ?? null) ? $item['metadata'] : []);

            unset($items[$index]['vehicle_id'], $items[$index]['driver_id']);
        }

        if (!empty($items)) {
            $data['booking_items'] = array_values($items);
            $first = $data['booking_items'][0];
            $data['vehicle_group_id'] = $first['vehicle_group_id'] ?? ($data['vehicle_group_id'] ?? null);
            $data['service_type_id'] = $first['service_type_id'] ?? ($first['service_type'] ?? ($data['service_type_id'] ?? null));
            $data['service_type'] = $data['service_type_id'];
        } else {
            $this->validateVehicleGroup($corporate, $data['vehicle_group_id'] ?? null);
            $this->validateServiceType($corporate, $data['service_type_id'] ?? ($data['service_type'] ?? null));
        }

        unset(
            $data['vehicle_id'],
            $data['driver_id'],
            $data['specific_vehicle_id'],
            $data['specific_driver_id'],
            $data['vehicle_driver_assignments']
        );

        return $data;
    }

    private function requiresCreditApproval(Corporate $corporate, array $data): bool
    {
        return ($data['payment_collection_method'] ?? null) === 'monthly_invoice'
            && ($this->financialProjection->accountSummary($corporate->id)['summary']['credit_limit_exceeded'] ?? false);
    }

    private function validateServicePackage(?string $serviceTypeId, ?string $packageId): void
    {
        if (! $packageId) {
            return;
        }

        if (! $serviceTypeId || ! ServicePackage::query()
            ->whereKey($packageId)
            ->where('service_type_id', $serviceTypeId)
            ->where('is_active', true)
            ->exists()) {
            abort(422, 'The selected package is not available for this service.');
        }
    }

    private function validateStopOwnership(Corporate $corporate, array $metadata): void
    {
        $contacts = array_filter([
            $metadata['primary_pickup_contact'] ?? null,
            $metadata['primary_dropoff_contact'] ?? null,
            ...$this->arrayValue($metadata['ordered_additional_stops'] ?? null),
            ...$this->arrayValue($metadata['additional_pickup_locations'] ?? null),
            ...$this->arrayValue($metadata['additional_dropoff_locations'] ?? null),
        ], 'is_array');

        foreach ($contacts as $contact) {
            $employeeId = $contact['employee_id'] ?? null;
            if ($employeeId && ! CorporateEmployee::query()
                ->where('corporate_id', $corporate->id)
                ->whereKey($employeeId)
                ->exists()) {
                abort(422, 'A selected stop employee does not belong to your corporate.');
            }

            $locationId = $contact['employee_location_id'] ?? null;
            if ($locationId && ! CorporateEmployeeLocation::query()
                ->whereKey($locationId)
                ->whereHas('employee', fn ($query) => $query->where('corporate_id', $corporate->id))
                ->when($employeeId, fn ($query) => $query->where('corporate_employee_id', $employeeId))
                ->exists()) {
                abort(422, 'A selected stop location does not belong to your corporate employee.');
            }
        }
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function bookingItemsFromPayload(array $data): array
    {
        if (isset($data['booking_items']) && is_array($data['booking_items'])) {
            return $data['booking_items'];
        }

        if (!empty($data['vehicle_group_id']) || !empty($data['service_type_id']) || !empty($data['service_type'])) {
            return [
                [
                    'vehicle_group_id' => $data['vehicle_group_id'] ?? null,
                    'service_type_id' => $data['service_type_id'] ?? ($data['service_type'] ?? null),
                ]
            ];
        }

        return [];
    }

    private function firstVehicleGroupId(array $data): ?string
    {
        return $data['booking_items'][0]['vehicle_group_id'] ?? ($data['vehicle_group_id'] ?? null);
    }

    private function firstServiceTypeId(array $data): ?string
    {
        return $data['booking_items'][0]['service_type_id']
            ?? ($data['booking_items'][0]['service_type'] ?? ($data['service_type_id'] ?? ($data['service_type'] ?? null)));
    }

    private function validateServiceType(Corporate $corporate, ?string $serviceTypeId): void
    {
        if (!$serviceTypeId) {
            abort(422, 'A service type is required for corporate bookings.');
        }

        $assigned = $corporate->serviceTypes()
            ->where('service_types.id', $serviceTypeId)
            ->exists();

        if (!$assigned) {
            abort(422, 'The selected service is not assigned to your corporate.');
        }
    }

    private function applyCorporateRequestStatus(Booking $booking, bool $requiresApproval, ?string $actorId): void
    {
        $seriesQuery = $booking->recurring_series_id
            ? Booking::query()
                ->where('recurring_series_id', $booking->recurring_series_id)
                ->where('id', '!=', $booking->id)
            : null;

        if ($requiresApproval) {
            $attributes = [
                'status' => 'pending_approval',
                'confirmed' => false,
                'confirmed_at' => null,
                'requires_approval' => true,
                'approval_status' => 'pending',
                'approval_requested_by' => $actorId,
                'approval_requested_at' => $booking->approval_requested_at ?? now(),
                'workflow_step' => 'pending_approval',
            ];

            $booking->update($attributes);
            $seriesQuery?->update($attributes);

            BookingApproval::firstOrCreate(
                [
                    'booking_id' => $booking->id,
                    'status' => BookingApproval::STATUS_PENDING,
                ],
                [
                    'requested_by' => $actorId,
                    'override_reasons' => $booking->override_reasons ?? [],
                    'justification' => $booking->approval_justification,
                    'priority' => $booking->approval_priority ?? 'normal',
                ]
            );

            if ($seriesQuery) {
                Booking::query()
                    ->where('recurring_series_id', $booking->recurring_series_id)
                    ->where('id', '!=', $booking->id)
                    ->pluck('id')
                    ->each(function ($bookingId) use ($actorId, $booking) {
                        BookingApproval::firstOrCreate(
                            [
                                'booking_id' => $bookingId,
                                'status' => BookingApproval::STATUS_PENDING,
                            ],
                            [
                                'requested_by' => $actorId,
                                'override_reasons' => $booking->override_reasons ?? [],
                                'justification' => $booking->approval_justification,
                                'priority' => $booking->approval_priority ?? 'normal',
                            ]
                        );
                    });
            }

            return;
        }

        $attributes = [
            'status' => 'approved',
            'confirmed' => false,
            'confirmed_at' => null,
            'requires_approval' => false,
            'approval_status' => 'approved',
            'approval_by' => $actorId,
            'approval_at' => now(),
            'workflow_step' => 'approved',
        ];

        $booking->update($attributes);
        $seriesQuery?->update($attributes);
    }

    // ─── Booking Queries & Filters ────────────────────────────────────

    private function notifyApprovalRequested(Booking $booking, CorporateEmployee $employee): void
    {
        try {
            $employee->loadMissing('user');
            $employee->user?->notify(new BookingLifecycleNotification(
                $booking,
                'Approval requested',
                'Your corporate booking request is awaiting approval.',
                'booking_approval_requested',
            ));
        } catch (\Throwable $exception) {
            Log::warning('Corporate approval request notification could not be queued', [
                'booking_id' => $booking->id,
                'employee_id' => $employee->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Get paginated bookings for a corporate with optional filters.
     */
    public function getBookingsForCorporate(string $corporateId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->corporateBookingItemQuery($corporateId);

        $this->applyBookingItemFilters($query, $filters);

        $paginator = $query
            ->orderByDesc('booking_items.from_date')
            ->orderByDesc('booking_items.from_time')
            ->orderByDesc('booking_items.created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->transformBookingItemPaginator($paginator, $filters);
    }

    /**
     * Get paginated bookings for a specific employee.
     */
    public function getBookingsForEmployee(string $userId, string $corporateId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->corporateActorBookingItemQuery($userId, $corporateId);

        $this->applyBookingItemFilters($query, $filters);

        $paginator = $query
            ->orderByDesc('booking_items.from_date')
            ->orderByDesc('booking_items.from_time')
            ->orderByDesc('booking_items.created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->transformBookingItemPaginator($paginator, $filters);
    }

    private function corporateActorBookingItemQuery(string $userId, string $corporateId)
    {
        return BookingItem::query()
            ->whereHas('booking', function ($bookingQuery) use ($userId, $corporateId) {
                $bookingQuery
                    ->where('corporate_account_id', $corporateId)
                    ->where('is_corporate_booking', true)
                    ->where(function ($ownerQuery) use ($userId) {
                        $ownerQuery->where('employee_id', $userId)
                            ->orWhere('created_by_user_id', $userId);
                    });
            })
            ->with($this->corporateBookingItemRelations());
    }

    /**
     * Get the approval queue for a corporate (pending_approval bookings only).
     */
    public function getApprovalQueue(string $corporateId, array $filters = []): LengthAwarePaginator
    {
        $query = Booking::where('corporate_account_id', $corporateId)
            ->where('status', 'pending_approval');

        $this->applyBookingFilters($query, $filters);

        $paginator = $query
            ->with([
                'customer',
                'corporateAccount',
                'corporateDepartment',
                'corporateDivision',
                'employee.user',
                'vehicleGroup',
                'vehicle',
                'driver',
                'latestApproval.approver',
                'createdBy',
            ])
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->transformBookingPaginator($paginator, $filters);
    }

    public function getCorporateBookingDetails(
        Booking $booking,
        bool $canViewPayments = false,
        string $bookingScope = 'employee',
    ): array
    {
        $booking->loadMissing([
            'customer',
            'corporateAccount',
            'corporateDepartment',
            'corporateDivision',
            'employee.user',
            'vehicleGroup',
            'vehicle',
            'driver',
            'bookingItems.serviceType',
            'bookingItems.vehicleGroup',
            'vehicleAssignments.vehicle',
            'driverAssignments.driver',
            'dispatch',
            'qc',
            'approvals.approver',
            'latestApproval.approver',
            'createdBy',
        ]);

        $payload = $this->mapBooking($booking, true);
        $payload['trips'] = $booking->bookingItems->map(function ($item) use ($canViewPayments) {
            $trip = [
                'id' => $item->id,
                'service_type' => $item->serviceType?->name,
                'vehicle_group' => $item->vehicleGroup?->name,
                'pickup_location' => $this->locationLabel($item->pickup_location ?? null),
                'dropoff_location' => $this->locationLabel($item->dropoff_location ?? null),
                'from_date' => $this->dateIso($item->from_date),
                'to_date' => $this->dateIso($item->to_date),
                'submitted_responses' => $this->submittedResponseProjector->project($item),
            ];

            if ($canViewPayments) {
                $trip['contractual_distance_breakdown'] = $this->distanceSnapshotProjector->project(
                    is_array($item->pricing_breakdown) ? $item->pricing_breakdown : []
                );
            }

            return $trip;
        })->values();
        $payload['approvals'] = $booking->approvals->map(fn(BookingApproval $approval) => [
            'id' => $approval->id,
            'status' => $approval->status,
            'comments' => $approval->comments ?? $approval->notes ?? null,
            'approved_at' => optional($approval->approved_at)->toISOString(),
            'approver' => $approval->approver ? [
                'id' => $approval->approver->id,
                'first_name' => $approval->approver->first_name,
                'last_name' => $approval->approver->last_name,
            ] : null,
        ])->values();
        $payload['assignments'] = [
            'vehicles' => $booking->vehicleAssignments->map(fn($assignment) => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'vehicle_name' => $assignment->vehicle?->name ?? $assignment->vehicle?->title,
                'license_plate' => $assignment->vehicle?->license_plate,
            ])->values(),
            'drivers' => $booking->driverAssignments->map(fn($assignment) => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'driver_name' => $assignment->driver?->name,
                'driver_phone' => $assignment->driver?->phone,
            ])->values(),
        ];
        $payload['visibility'] = [
            'can_view_payments' => $canViewPayments || $this->isCashBooking($booking),
            'booking_scope' => $bookingScope,
            'corporate_id' => $booking->corporate_account_id,
            'department_id' => $booking->corporate_department_id,
            'division_id' => $booking->corporate_division_id,
            'points' => ['booked_pickup', 'booked_stops', 'booked_dropoff'],
            'raw_tracking' => false,
        ];

        if ($canViewPayments) {
            $payload['contractual_distance_breakdown'] = $this->distanceSnapshotProjector->project(
                is_array($booking->pricing_snapshot) ? $booking->pricing_snapshot : []
            );
        }

        return $payload;
    }

    /**
     * Apply common booking filters to a query builder.
     */
    private function applyBookingFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $status = $filters['status'];
            if ($status === 'pending') {
                $query->whereIn('status', ['pending', 'pending_approval']);
            } elseif ($status === 'approved') {
                $query->whereIn('status', ['approved', 'confirmed']);
            } elseif ($status === 'assigned') {
                $query->whereIn('status', ['assigned', 'allocated', 'in_progress']);
            } else {
                $query->where('status', $status);
            }
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'like', "%{$search}%")
                    ->orWhereHas(
                        'customer',
                        fn($customerQuery) =>
                        $customerQuery->where('name', 'like', "%{$search}%")
                    )
                    ->orWhereHas(
                        'employee.user',
                        fn($userQuery) =>
                        $userQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                    );
            });
        }

        if (!empty($filters['department_id'])) {
            $query->where('corporate_department_id', $filters['department_id']);
        }

        if (!empty($filters['division_id'])) {
            $query->where('corporate_division_id', $filters['division_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
    }

    private function transformBookingPaginator(LengthAwarePaginator $paginator, array $filters = []): LengthAwarePaginator
    {
        $canViewPayments = ($filters['can_view_payments'] ?? false) === true;
        $paginator->setCollection(
            $paginator->getCollection()->map(fn(Booking $booking) => $this->mapBooking($booking, $canViewPayments))
        );

        return $paginator;
    }

    private function corporateBookingItemQuery(string $corporateId)
    {
        return BookingItem::query()
            ->whereHas('booking', fn($bookingQuery) => $bookingQuery->where('corporate_account_id', $corporateId))
            ->with($this->corporateBookingItemRelations());
    }

    private function corporateBookingItemRelations(): array
    {
        return [
            'booking.customer',
            'booking.corporateAccount',
            'booking.corporateDepartment',
            'booking.corporateDivision',
            'booking.employee.user',
            'booking.latestApproval.approver',
            'booking.createdBy',
            'booking.bookingItems:id,booking_id,vehicle_id,driver_id,is_self_driven,from_date,from_time,created_at',
            'booking.dispatch',
            'booking.qc',
            'serviceType',
            'vehicleGroup',
            'vehicle',
            'driver.user',
        ];
    }

    private function transformBookingItemPaginator(LengthAwarePaginator $paginator, array $filters = []): LengthAwarePaginator
    {
        $canViewPayments = ($filters['can_view_payments'] ?? false) === true;
        $paginator->setCollection(
            $paginator->getCollection()->map(fn(BookingItem $item) => $this->mapBookingItem($item, $canViewPayments))
        );

        return $paginator;
    }

    private function applyBookingItemFilters($query, array $filters): void
    {
        $bookingFilters = $filters;
        unset($bookingFilters['date_from'], $bookingFilters['date_to']);

        $query->whereHas('booking', function ($bookingQuery) use ($bookingFilters) {
            $this->applyBookingFilters($bookingQuery, $bookingFilters);
        });

        if (!empty($filters['date_from'])) {
            $query->whereDate('booking_items.from_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('booking_items.to_date', '<=', $filters['date_to']);
        }
    }

    private function mapBookingItem(BookingItem $item, bool $canViewPayments = false): array
    {
        $booking = $item->booking;
        $employeeUser = $booking?->employee?->user;
        $lifecycleContract = $booking
            ? app(BookingLifecycleService::class)->getLifecycleContract($booking, (string) $item->id)
            : null;
        $approval = $booking?->latestApproval;
        $bookingItems = $booking?->bookingItems ?? collect();
        $itemCount = max(1, $bookingItems->count());
        $itemIndex = $bookingItems->search(fn($candidate) => (string) $candidate->id === (string) $item->id);
        $sequence = $itemIndex === false ? 1 : $itemIndex + 1;
        $itemCode = $item->item_code ?: sprintf('%s-I%02d', $booking?->booking_number ?? $booking?->id ?? 'Booking', $sequence);

        $payload = [
            'id' => $item->id,
            'booking_id' => $booking?->id,
            'booking_item_id' => $item->id,
            'booking_number' => $booking?->booking_number,
            'item_code' => $itemCode,
            'item_sequence' => $sequence,
            'item_count' => $itemCount,
            'is_multi_item' => $itemCount > 1,
            'is_corporate_booking' => (bool) $booking?->is_corporate_booking,
            'corporate_account_id' => $booking?->corporate_account_id,
            'corporate_name' => $booking?->corporateAccount?->name,
            'employee_id' => $booking?->employee_id,
            'employee_name' => $employeeUser
                ? trim($employeeUser->first_name . ' ' . $employeeUser->last_name)
                : ($booking?->customer?->name ?? null),
            'employee_email' => $employeeUser?->email,
            'corporate_department_id' => $booking?->corporate_department_id,
            'corporate_division_id' => $booking?->corporate_division_id,
            'department' => $booking?->corporateDepartment?->name,
            'division' => $booking?->corporateDivision?->name,
            'created_by_user_id' => $booking?->created_by_user_id,
            'created_by_name' => $booking?->createdBy?->name,
            'status' => $item->status ?: $booking?->status,
            'booking_status' => $booking?->status,
            'lifecycle_contract' => $lifecycleContract,
            'allowed_actions' => $lifecycleContract['allowed_actions'] ?? [],
            'blocking_reasons' => $lifecycleContract['blocking_reasons'] ?? [],
            'approval_status' => $booking?->approval_status,
            'requires_approval' => (bool) ($item->requires_approval ?: $booking?->requires_approval),
            'service_type_id' => $item->service_type_id,
            'service_type_name' => $item->serviceType?->name,
            'vehicle_group_id' => $item->vehicle_group_id,
            'vehicle_group_name' => $item->vehicleGroup?->name,
            'pickup_location' => $this->locationLabel($item->pickup_location ?? null),
            'dropoff_location' => $this->locationLabel($item->dropoff_location ?? null),
            'pickup_date' => $this->dateIso($item->from_date),
            'dropoff_date' => $this->dateIso($item->to_date),
            'from_time' => $item->from_time,
            'to_time' => $item->to_time,
            'assigned_vehicle' => $item->vehicle ? [
                'id' => $item->vehicle->id,
                'name' => $item->vehicle->title,
                'license_plate' => $item->vehicle->license_plate,
            ] : null,
            'assigned_driver' => $item->driver ? [
                'id' => $item->driver->id,
                'name' => $item->driver->name ?? trim(($item->driver->user?->first_name ?? '') . ' ' . ($item->driver->user?->last_name ?? '')),
                'phone' => $item->driver->phone ?? $item->driver->user?->phone,
            ] : null,
            'approval' => $approval ? [
                'id' => $approval->id,
                'booking_id' => $approval->booking_id,
                'status' => $approval->status,
                'approved_by_user_id' => $approval->approved_by_user_id ?? null,
                'comments' => $approval->comments ?? $approval->notes ?? null,
                'approved_at' => optional($approval->approved_at)->toISOString(),
                'approver' => $approval->approver ? [
                    'id' => $approval->approver->id,
                    'first_name' => $approval->approver->first_name,
                    'last_name' => $approval->approver->last_name,
                ] : null,
            ] : null,
            'created_at' => $this->dateIso($item->created_at),
            'updated_at' => $this->dateIso($item->updated_at),
        ];

        $canViewBookingPayments = $canViewPayments || $this->isCashBooking($booking);
        if ($canViewBookingPayments) {
            $payload += [
                'total_cost' => (float) ($item->total_price ?? 0),
                'currency' => $item->currency ?? $booking?->currency,
                'payment_status' => $booking?->payment_status,
                'payment_method' => $booking?->payment_method,
                'payment_responsibility' => $booking?->payment_responsibility,
                'payment_collection_method' => $booking?->payment_collection_method,
                'payment_collection_status' => $booking?->payment_collection_status,
                'pricing_scope' => data_get($item->pricing_breakdown, 'pricing_scope')
                    ?? data_get($booking?->pricing_snapshot, 'pricing_scope'),
            ];
        }

        return $payload;
    }

    private function mapBooking(Booking $booking, bool $canViewPayments = false): array
    {
        $employeeUser = $booking->employee?->user;
        $approval = $booking->latestApproval;
        $lifecycleContract = app(BookingLifecycleService::class)->getLifecycleContract($booking);

        $payload = [
            'id' => $booking->id,
            'booking_number' => $booking->booking_number,
            'is_corporate_booking' => (bool) $booking->is_corporate_booking,
            'corporate_account_id' => $booking->corporate_account_id,
            'corporate_name' => $booking->corporateAccount?->name,
            'employee_id' => $booking->employee_id,
            'employee_name' => $employeeUser
                ? trim($employeeUser->first_name . ' ' . $employeeUser->last_name)
                : ($booking->customer?->name ?? null),
            'employee_email' => $employeeUser?->email,
            'corporate_department_id' => $booking->corporate_department_id,
            'corporate_division_id' => $booking->corporate_division_id,
            'department' => $booking->corporateDepartment?->name,
            'division' => $booking->corporateDivision?->name,
            'created_by_user_id' => $booking->created_by_user_id,
            'created_by_name' => $booking->createdBy?->name,
            'status' => $booking->status,
            'lifecycle_contract' => $lifecycleContract,
            'allowed_actions' => $lifecycleContract['allowed_actions'],
            'blocking_reasons' => $lifecycleContract['blocking_reasons'],
            'approval_status' => $booking->approval_status,
            'requires_approval' => (bool) $booking->requires_approval,
            'is_recurring' => (bool) $booking->is_recurring,
            'recurrence_pattern' => $booking->recurrence_pattern,
            'recurrence_end_date' => $this->dateIso($booking->recurrence_end_date),
            'recurrence_days' => $booking->recurrence_days,
            'recurring_series_id' => $booking->recurring_series_id,
            'recurring_sequence' => $booking->recurring_sequence,
            'recurring_occurrence_date' => $this->dateIso($booking->recurring_occurrence_date),
            'vehicle_group_id' => $booking->vehicle_group_id,
            'vehicle_group_name' => $booking->vehicleGroup?->name,
            'pickup_location' => $this->locationLabel($booking->pickup_location ?? null),
            'dropoff_location' => $this->locationLabel($booking->dropoff_location ?? null),
            'pickup_date' => $this->dateIso($booking->from_date),
            'dropoff_date' => $this->dateIso($booking->to_date),
            'assigned_vehicle' => $booking->vehicle ? [
                'id' => $booking->vehicle->id,
                'name' => $booking->vehicle->title,
                'license_plate' => $booking->vehicle->license_plate,
            ] : null,
            'assigned_driver' => $booking->driver ? [
                'id' => $booking->driver->id,
                'name' => $booking->driver->name,
                'phone' => $booking->driver->phone,
            ] : null,
            'approval' => $approval ? [
                'id' => $approval->id,
                'booking_id' => $approval->booking_id,
                'status' => $approval->status,
                'approved_by_user_id' => $approval->approved_by_user_id ?? null,
                'comments' => $approval->comments ?? $approval->notes ?? null,
                'approved_at' => optional($approval->approved_at)->toISOString(),
                'approver' => $approval->approver ? [
                    'id' => $approval->approver->id,
                    'first_name' => $approval->approver->first_name,
                    'last_name' => $approval->approver->last_name,
                ] : null,
            ] : null,
            'created_at' => $this->dateIso($booking->created_at),
            'updated_at' => $this->dateIso($booking->updated_at),
        ];

        if ($canViewPayments || $this->isCashBooking($booking)) {
            $payload += [
                'total_cost' => (float) ($booking->total_estimated ?? $booking->total_actual ?? 0),
                'currency' => $booking->currency,
                'payment_status' => $booking->payment_status,
                'payment_method' => $booking->payment_method,
                'payment_responsibility' => $booking->payment_responsibility,
                'payment_collection_method' => $booking->payment_collection_method,
                'payment_collection_status' => $booking->payment_collection_status,
                'pricing_scope' => data_get($booking->pricing_snapshot, 'pricing_scope'),
            ];
        }

        return $payload;
    }

    private function isCashBooking(?Booking $booking): bool
    {
        return strtolower((string) $booking?->payment_collection_method) === 'cash_to_driver';
    }

    private function locationLabel(mixed $location): ?string
    {
        if (is_array($location)) {
            return $location['address'] ?? $location['label'] ?? null;
        }

        if (is_object($location)) {
            return $location->address ?? $location->label ?? null;
        }

        return $location ? (string) $location : null;
    }

    private function dateIso(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->toISOString();
        }

        return (string) $value;
    }

    // ─── Reporting & Export ───────────────────────────────────────────

    /**
     * Get summary statistics for a corporate's bookings.
     */
    public function getBookingSummaryStats(string $corporateId, array $filters = []): array
    {
        $query = Booking::where('corporate_account_id', $corporateId);

        $bookingFilters = $filters;
        unset($bookingFilters['date_from'], $bookingFilters['date_to'], $bookingFilters['created_from'], $bookingFilters['created_to']);
        $this->applyBookingFilters($query, $bookingFilters);

        if (!empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }
        if (!empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $query->whereHas('bookingItems', function ($itemQuery) use ($filters) {
                if (!empty($filters['date_from'])) {
                    $itemQuery->whereDate('from_date', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $itemQuery->whereDate('from_date', '<=', $filters['date_to']);
                }
            });
        }

        $totalCount = (clone $query)->count();
        $estimatedValue = (float) (clone $query)->sum('total_estimated');
        $finalizedValue = (float) (clone $query)->whereNotNull('total_actual')->sum('total_actual');
        $finalizedBookingCount = (clone $query)->whereNotNull('total_actual')->count();
        $tripQuery = $this->corporateBookingItemQuery($corporateId);
        $tripQuery->setEagerLoads([]);
        $this->applyBookingItemFilters($tripQuery, $filters);
        $tripCount = (clone $tripQuery)->count('booking_items.id');
        $matchingBookingItemIds = (clone $tripQuery)->pluck('booking_items.id');

        $byStatus = (clone $query)
            ->select('status')
            ->selectRaw('count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byDepartment = (clone $query)
            ->leftJoin('corporate_departments', 'bookings.corporate_department_id', '=', 'corporate_departments.id')
            ->selectRaw("COALESCE(corporate_departments.name, 'Unassigned') as department_name")
            ->selectRaw('count(*) as count')
            ->groupByRaw("COALESCE(corporate_departments.name, 'Unassigned')")
            ->pluck('count', 'department_name')
            ->toArray();

        $financial = [];
        if (($filters['can_view_payments'] ?? false) === true) {
            $financial = ['financial_metrics_visible' => true, ...$this->financialProjection->summarizeBookings(
                (clone $query)->get(['bookings.id', 'bookings.total_actual']),
                $matchingBookingItemIds,
            )];
        } else {
            $financial = ['financial_metrics_visible' => false];
        }

        return [
            'total_bookings' => $totalCount,
            'booking_count' => $totalCount,
            'trip_count' => $tripCount,
            'total_count' => $totalCount,
            'total_cost' => $estimatedValue,
            'estimated_value' => $estimatedValue,
            'finalized_value' => $finalizedValue,
            'finalized_booking_count' => $finalizedBookingCount,
            'unfinalized_booking_count' => max(0, $totalCount - $finalizedBookingCount),
            'by_status' => $byStatus,
            'by_department' => $byDepartment,
            'bookings_by_status' => $byStatus,
            'bookings_by_department' => $byDepartment,
            ...$financial,
        ];
    }

    /**
     * Export corporate bookings to CSV and return the file path.
     */
    public function exportBookings(string $corporateId, array $filters = []): string
    {
        $query = $this->corporateBookingItemQuery($corporateId);

        $this->applyBookingItemFilters($query, $filters);

        $items = $query
            ->orderByDesc('booking_items.from_date')
            ->orderByDesc('booking_items.from_time')
            ->orderByDesc('booking_items.created_at')
            ->get();

        $filename = 'exports/corporate_bookings_' . $corporateId . '_' . now()->format('Ymd_His') . '.csv';

        $canViewPayments = ($filters['can_view_payments'] ?? false) === true;
        $csv = 'booking_number,item_code,employee_name,department,division,service_type,vehicle_category,from_date,to_date,status';
        if ($canViewPayments) {
            $csv .= ',payment_method,payment_collection_status,total_cost';
        }
        $csv .= "\n";

        foreach ($items as $item) {
            $row = $this->mapBookingItem($item, $canViewPayments);
            $cells = [
                $this->csvEscape($row['booking_number'] ?? ''),
                $this->csvEscape($row['item_code'] ?? ''),
                $this->csvEscape($row['employee_name'] ?? ''),
                $this->csvEscape($row['department'] ?? ''),
                $this->csvEscape($row['division'] ?? ''),
                $this->csvEscape($row['service_type_name'] ?? ''),
                $this->csvEscape($row['vehicle_group_name'] ?? ''),
                $this->csvEscape($row['pickup_date'] ?? ''),
                $this->csvEscape($row['dropoff_date'] ?? ''),
                $this->csvEscape($row['status'] ?? ''),
            ];
            if ($canViewPayments) {
                $cells[] = $this->csvEscape($row['payment_collection_method'] ?? $row['payment_method'] ?? '');
                $cells[] = $this->csvEscape($row['payment_collection_status'] ?? $row['payment_status'] ?? '');
                $cells[] = $this->csvEscape($row['total_cost'] ?? '0');
            }
            $csv .= implode(',', $cells) . "\n";
        }

        Storage::put($filename, $csv);

        return $filename;
    }

    /**
     * Escape a value for CSV output.
     */
    private function csvEscape(mixed $value): string
    {
        $value = (string) $value;

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    // ─── Audit Logging ────────────────────────────────────────────────

    private function resolveCustomerIdForEmployee(CorporateEmployee $employee): string
    {
        $customer = Customer::firstOrCreate(
            ['user_id' => $employee->user_id],
            [
                'user_id' => $employee->user_id,
                'type' => 'business',
                'sub_type' => 'local',
                'category' => 'regular',
                'created_user_id' => Auth::id(),
                'updated_user_id' => Auth::id(),
            ]
        );

        return (string) $customer->id;
    }

    private function logAudit(string $action, string $entity, ?string $entityId, array $details = []): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'timestamp' => now(),
            'details' => $details,
        ]);
    }
}
