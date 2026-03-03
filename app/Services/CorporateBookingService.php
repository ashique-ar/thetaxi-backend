<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Corporate\CorporateRateChart;
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

        $this->validateVehicleGroup($corporate, $data['vehicle_group_id'] ?? null);

        $rateChart = $this->applyRateChartPricing(
            $corporate,
            $data['vehicle_group_id'] ?? null,
            $data['service_type_id'] ?? null,
        );

        return DB::transaction(function () use ($employee, $corporate, $data, $rateChart) {
            $needsApproval = $corporate->approval_required;

            $params = array_merge($data, [
                'customer_id'            => $employee->user_id,
                'is_corporate_booking'   => true,
                'corporate_account_id'   => $corporate->id,
                'employee_id'            => $employee->user_id,
                'corporate_department_id' => $employee->department_id,
                'corporate_division_id'  => $employee->division_id,
                'created_by_user_id'     => Auth::id(),
            ]);

            if ($rateChart) {
                $params['corporate_rate_chart'] = [
                    'id'            => $rateChart->id,
                    'per_km_rate'   => $rateChart->per_km_rate,
                    'per_hour_rate' => $rateChart->per_hour_rate,
                    'fixed_route_pricing' => $rateChart->fixed_route_pricing,
                ];
            }

            if ($needsApproval) {
                $booking = $this->bookingFlowService->submitBookingForApproval($params);
            } else {
                $booking = $this->bookingFlowService->confirmBooking($params);
            }

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
                'rate_chart_id' => $rateChart?->id,
            ]);

            return $booking->fresh();
        });
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

        $this->validateVehicleGroup($corporate, $data['vehicle_group_id'] ?? null);

        $rateChart = $this->applyRateChartPricing(
            $corporate,
            $data['vehicle_group_id'] ?? null,
            $data['service_type_id'] ?? null,
        );

        return DB::transaction(function () use ($coordinator, $targetEmployee, $corporate, $data, $rateChart) {
            $needsApproval = $corporate->approval_required;

            // If corporate exempts coordinators from approval, skip it
            if ($corporate->exempt_coordinator_from_approval) {
                $needsApproval = false;
            }

            $params = array_merge($data, [
                'customer_id'            => $targetEmployee->user_id,
                'is_corporate_booking'   => true,
                'corporate_account_id'   => $corporate->id,
                'employee_id'            => $targetEmployee->user_id,
                'corporate_department_id' => $targetEmployee->department_id,
                'corporate_division_id'  => $targetEmployee->division_id,
                'created_by_user_id'     => $coordinator->user_id,
            ]);

            if ($rateChart) {
                $params['corporate_rate_chart'] = [
                    'id'            => $rateChart->id,
                    'per_km_rate'   => $rateChart->per_km_rate,
                    'per_hour_rate' => $rateChart->per_hour_rate,
                    'fixed_route_pricing' => $rateChart->fixed_route_pricing,
                ];
            }

            if ($needsApproval) {
                $booking = $this->bookingFlowService->submitBookingForApproval($params);
            } else {
                $booking = $this->bookingFlowService->confirmBooking($params);
            }

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
                'rate_chart_id'      => $rateChart?->id,
            ]);

            return $booking->fresh();
        });
    }

    /**
     * Find the most specific matching rate chart for a corporate.
     *
     * Priority:
     *  1. Match both vehicle_group_id AND service_type_id
     *  2. Match vehicle_group_id only (service_type_id is null)
     *  3. Match service_type_id only (vehicle_group_id is null)
     *  4. Catch-all (both null)
     */
    public function applyRateChartPricing(
        Corporate $corporate,
        ?string $vehicleGroupId,
        ?string $serviceTypeId,
    ): ?CorporateRateChart {
        $charts = $corporate->rateCharts()->where('is_active', true)->get();

        // 1. Both match
        if ($vehicleGroupId && $serviceTypeId) {
            $match = $charts->first(fn (CorporateRateChart $c) =>
                $c->vehicle_group_id === $vehicleGroupId && $c->service_type_id === $serviceTypeId
            );
            if ($match) {
                return $match;
            }
        }

        // 2. Vehicle group only
        if ($vehicleGroupId) {
            $match = $charts->first(fn (CorporateRateChart $c) =>
                $c->vehicle_group_id === $vehicleGroupId && $c->service_type_id === null
            );
            if ($match) {
                return $match;
            }
        }

        // 3. Service type only
        if ($serviceTypeId) {
            $match = $charts->first(fn (CorporateRateChart $c) =>
                $c->vehicle_group_id === null && $c->service_type_id === $serviceTypeId
            );
            if ($match) {
                return $match;
            }
        }

        // 4. Catch-all (both null)
        return $charts->first(fn (CorporateRateChart $c) =>
            $c->vehicle_group_id === null && $c->service_type_id === null
        );
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

    // ─── Booking Queries & Filters ────────────────────────────────────

    /**
     * Get paginated bookings for a corporate with optional filters.
     */
    public function getBookingsForCorporate(string $corporateId, array $filters = []): LengthAwarePaginator
    {
        $query = Booking::where('corporate_account_id', $corporateId);

        $this->applyBookingFilters($query, $filters);

        return $query
            ->with(['customer', 'corporateDepartment', 'corporateDivision'])
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    /**
     * Get paginated bookings for a specific employee.
     */
    public function getBookingsForEmployee(string $userId, array $filters = []): LengthAwarePaginator
    {
        $query = Booking::where('employee_id', $userId)
            ->where('is_corporate_booking', true);

        $this->applyBookingFilters($query, $filters);

        return $query
            ->with(['customer', 'corporateDepartment', 'corporateDivision'])
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    /**
     * Get the approval queue for a corporate (pending_approval bookings only).
     */
    public function getApprovalQueue(string $corporateId, array $filters = []): LengthAwarePaginator
    {
        $query = Booking::where('corporate_account_id', $corporateId)
            ->where('status', 'pending_approval');

        $this->applyBookingFilters($query, $filters);

        return $query
            ->with(['customer', 'corporateDepartment', 'corporateDivision'])
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    /**
     * Apply common booking filters to a query builder.
     */
    private function applyBookingFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
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
