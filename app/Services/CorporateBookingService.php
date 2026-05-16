<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CorporateBookingService
{
    public function __construct(
        protected BookingFlowService $bookingFlowService,
    ) {}

    // ─── Booking Creation ─────────────────────────────────────────────

    /**
     * Create a corporate booking for an employee.
     */
    public function createBooking(CorporateEmployee $employee, array $data): Booking
    {
        $corporate = $employee->corporate;

        $data = $this->prepareCorporateBookingPayload($corporate, $data);

        return DB::transaction(function () use ($employee, $corporate, $data) {
            $needsApproval = $corporate->approval_required;

            $params = array_merge($data, [
                'customer_id'            => $this->resolveCustomerIdForEmployee($employee),
                'is_corporate_booking'   => true,
                'corporate_account_id'   => $corporate->id,
                'employee_id'            => $employee->user_id,
                'corporate_department_id' => $employee->department_id,
                'corporate_division_id'  => $employee->division_id,
                'created_by_user_id'     => Auth::id(),
            ]);

            if ($needsApproval) {
                $booking = $this->bookingFlowService->submitBookingForApproval($params);
            } else {
                $booking = $this->bookingFlowService->confirmBooking($params);
            }

            $this->applyCorporateRequestStatus($booking, $needsApproval, Auth::id());

            // Ensure corporate fields are set on the booking
            $booking->update([
                'is_corporate_booking'    => true,
                'corporate_account_id'    => $corporate->id,
                'employee_id'             => $employee->user_id,
                'corporate_department_id' => $employee->department_id,
                'corporate_division_id'   => $employee->division_id,
                'created_by_user_id'      => Auth::id(),
            ]);

            $this->logAudit('corporate_booking_created', 'Booking', $booking->id, [
                'corporate_id'  => $corporate->id,
                'employee_id'   => $employee->user_id,
                'needs_approval' => $needsApproval,
            ]);

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
    ): Booking {
        $corporate = $coordinator->corporate;

        $data = $this->prepareCorporateBookingPayload($corporate, $data);

        return DB::transaction(function () use ($coordinator, $targetEmployee, $corporate, $data) {
            $needsApproval = $corporate->approval_required;

            // If corporate exempts coordinators from approval, skip it
            if ($corporate->exempt_coordinator_from_approval) {
                $needsApproval = false;
            }

            $params = array_merge($data, [
                'customer_id'            => $this->resolveCustomerIdForEmployee($targetEmployee),
                'is_corporate_booking'   => true,
                'corporate_account_id'   => $corporate->id,
                'employee_id'            => $targetEmployee->user_id,
                'corporate_department_id' => $targetEmployee->department_id,
                'corporate_division_id'  => $targetEmployee->division_id,
                'created_by_user_id'     => $coordinator->user_id,
            ]);

            if ($needsApproval) {
                $booking = $this->bookingFlowService->submitBookingForApproval($params);
            } else {
                $booking = $this->bookingFlowService->confirmBooking($params);
            }

            $this->applyCorporateRequestStatus($booking, $needsApproval, $coordinator->user_id);

            // Ensure corporate fields are set on the booking
            $booking->update([
                'is_corporate_booking'    => true,
                'corporate_account_id'    => $corporate->id,
                'employee_id'             => $targetEmployee->user_id,
                'corporate_department_id' => $targetEmployee->department_id,
                'corporate_division_id'   => $targetEmployee->division_id,
                'created_by_user_id'      => $coordinator->user_id,
            ]);

            $this->logAudit('corporate_booking_created_for_employee', 'Booking', $booking->id, [
                'corporate_id'       => $corporate->id,
                'coordinator_id'     => $coordinator->user_id,
                'target_employee_id' => $targetEmployee->user_id,
                'needs_approval'     => $needsApproval,
                'coordinator_exempt' => $corporate->exempt_coordinator_from_approval,
            ]);

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

    private function prepareCorporateBookingPayload(Corporate $corporate, array $data): array
    {
        $items = $this->bookingItemsFromPayload($data);

        foreach ($items as $index => $item) {
            $this->validateVehicleGroup($corporate, $item['vehicle_group_id'] ?? null);
            $this->validateServiceType($corporate, $item['service_type_id'] ?? ($item['service_type'] ?? null));

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

    private function bookingItemsFromPayload(array $data): array
    {
        if (isset($data['booking_items']) && is_array($data['booking_items'])) {
            return $data['booking_items'];
        }

        if (!empty($data['vehicle_group_id']) || !empty($data['service_type_id']) || !empty($data['service_type'])) {
            return [[
                'vehicle_group_id' => $data['vehicle_group_id'] ?? null,
                'service_type_id' => $data['service_type_id'] ?? ($data['service_type'] ?? null),
            ]];
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

    /**
     * Get paginated bookings for a corporate with optional filters.
     */
    public function getBookingsForCorporate(string $corporateId, array $filters = []): LengthAwarePaginator
    {
        $query = Booking::where('corporate_account_id', $corporateId);

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

    /**
     * Get paginated bookings for a specific employee.
     */
    public function getBookingsForEmployee(string $userId, array $filters = []): LengthAwarePaginator
    {
        $query = Booking::where('employee_id', $userId)
            ->where('is_corporate_booking', true);

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

    public function getCorporateBookingDetails(Booking $booking, bool $canViewPayments = false): array
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
            'approvals.approver',
            'latestApproval.approver',
            'createdBy',
        ]);

        $payload = $this->mapBooking($booking, $canViewPayments);
        $payload['trips'] = $booking->bookingItems->map(fn ($item) => [
            'id' => $item->id,
            'service_type' => $item->serviceType?->name,
            'vehicle_group' => $item->vehicleGroup?->name,
            'pickup_location' => $this->locationLabel($item->pickup_location ?? null),
            'dropoff_location' => $this->locationLabel($item->dropoff_location ?? null),
            'from_date' => $this->dateIso($item->from_date),
            'to_date' => $this->dateIso($item->to_date),
            'form_responses' => $item->metadata ?? [],
        ])->values();
        $payload['approvals'] = $booking->approvals->map(fn (BookingApproval $approval) => [
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
            'vehicles' => $booking->vehicleAssignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'vehicle_name' => $assignment->vehicle?->name ?? $assignment->vehicle?->title,
                'license_plate' => $assignment->vehicle?->license_plate,
            ])->values(),
            'drivers' => $booking->driverAssignments->map(fn ($assignment) => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'driver_name' => $assignment->driver?->name,
                'driver_phone' => $assignment->driver?->phone,
            ])->values(),
        ];
        $payload['visibility'] = [
            'can_view_payments' => $canViewPayments,
        ];

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
        } else {
            $query->whereIn('status', ['pending', 'pending_approval']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customerQuery) =>
                        $customerQuery->where('name', 'like', "%{$search}%")
                    )
                    ->orWhereHas('employee.user', fn ($userQuery) =>
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
        $canViewPayments = (bool) ($filters['can_view_payments'] ?? false);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Booking $booking) => $this->mapBooking($booking, $canViewPayments))
        );

        return $paginator;
    }

    private function mapBooking(Booking $booking, bool $canViewPayments = false): array
    {
        $employeeUser = $booking->employee?->user;
        $approval = $booking->latestApproval;

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
                'name' => $booking->vehicle->name ?? $booking->vehicle->title,
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

        if ($canViewPayments) {
            $payload += [
                'total_cost' => (float) ($booking->total_estimated ?? $booking->total_actual ?? 0),
                'currency' => $booking->currency,
                'payment_status' => $booking->payment_status,
                'payment_method' => $booking->payment_method,
                'pricing_scope' => data_get($booking->pricing_snapshot, 'pricing_scope'),
            ];
        }

        return $payload;
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

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $totalCount = (clone $query)->count();
        $totalCost  = (clone $query)->sum('total_estimated');

        $byStatus = (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byDepartment = (clone $query)
            ->select('corporate_department_id', DB::raw('count(*) as count'))
            ->groupBy('corporate_department_id')
            ->pluck('count', 'corporate_department_id')
            ->toArray();

        return [
            'total_count'           => $totalCount,
            'total_cost'            => (float) $totalCost,
            'bookings_by_status'    => $byStatus,
            'bookings_by_department' => $byDepartment,
        ];
    }

    /**
     * Export corporate bookings to CSV and return the file path.
     */
    public function exportBookings(string $corporateId, array $filters = []): string
    {
        $query = Booking::where('corporate_account_id', $corporateId);

        $this->applyBookingFilters($query, $filters);

        $bookings = $query
            ->with(['customer', 'corporateDepartment', 'corporateDivision', 'vehicleGroup'])
            ->orderByDesc('created_at')
            ->get();

        $filename = 'exports/corporate_bookings_' . $corporateId . '_' . now()->format('Ymd_His') . '.csv';

        $csv = "booking_number,employee_name,department,division,vehicle_category,from_date,to_date,status,total_cost\n";

        foreach ($bookings as $booking) {
            $csv .= implode(',', [
                $this->csvEscape($booking->booking_number ?? ''),
                $this->csvEscape($booking->customer?->name ?? ''),
                $this->csvEscape($booking->corporateDepartment?->name ?? ''),
                $this->csvEscape($booking->corporateDivision?->name ?? ''),
                $this->csvEscape($booking->vehicleGroup?->name ?? ''),
                $this->csvEscape($booking->from_date ?? ''),
                $this->csvEscape($booking->to_date ?? ''),
                $this->csvEscape($booking->status ?? ''),
                $this->csvEscape($booking->total_estimated ?? '0'),
            ]) . "\n";
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
            'user_id'   => Auth::id(),
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'timestamp' => now(),
            'details'   => $details,
        ]);
    }
}
