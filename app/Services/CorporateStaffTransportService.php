<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Corporate\CorporateTransportGenerationLog;
use App\Models\Corporate\CorporateTransportParticipation;
use App\Models\Corporate\CorporateTransportProgram;
use App\Models\Corporate\CorporateTransportRoute;
use App\Models\Corporate\CorporateTransportRouteMember;
use App\Models\Corporate\CorporateTransportShift;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CorporateStaffTransportService
{
    public function programs(string $corporateId, array $filters = []): LengthAwarePaginator
    {
        return CorporateTransportProgram::where('corporate_id', $corporateId)
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOL)))
            ->withCount(['routes', 'shifts'])
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function createProgram(string $corporateId, array $data): CorporateTransportProgram
    {
        return CorporateTransportProgram::create($data + ['corporate_id' => $corporateId]);
    }

    public function updateProgram(string $corporateId, string $programId, array $data): CorporateTransportProgram
    {
        $program = $this->findProgram($corporateId, $programId);
        $program->update($data);
        return $program->fresh();
    }

    public function shifts(string $corporateId, string $programId): Collection
    {
        $program = $this->findProgram($corporateId, $programId);
        return $program->shifts()->orderBy('pickup_time')->get();
    }

    public function saveShift(string $corporateId, string $programId, array $data, ?string $shiftId = null): CorporateTransportShift
    {
        $program = $this->findProgram($corporateId, $programId);
        if ($shiftId) {
            $shift = $program->shifts()->findOrFail($shiftId);
            $shift->update($data);
            return $shift->fresh();
        }

        return $program->shifts()->create($data);
    }

    public function deleteShift(string $corporateId, string $programId, string $shiftId): void
    {
        $this->findProgram($corporateId, $programId)->shifts()->findOrFail($shiftId)->delete();
    }

    public function routes(string $corporateId, string $programId): Collection
    {
        return $this->findProgram($corporateId, $programId)
            ->routes()
            ->with(['vehicleGroup', 'serviceType'])
            ->orderBy('name')
            ->get();
    }

    public function saveRoute(string $corporateId, string $programId, array $data, ?string $routeId = null): CorporateTransportRoute
    {
        $program = $this->findProgram($corporateId, $programId);
        if ($routeId) {
            $route = $program->routes()->findOrFail($routeId);
            $route->update($data);
            return $route->fresh(['vehicleGroup', 'serviceType']);
        }

        return $program->routes()->create($data)->fresh(['vehicleGroup', 'serviceType']);
    }

    public function deleteRoute(string $corporateId, string $programId, string $routeId): void
    {
        $this->findProgram($corporateId, $programId)->routes()->findOrFail($routeId)->delete();
    }

    public function members(string $corporateId, string $programId, string $routeId): Collection
    {
        $route = $this->findRoute($corporateId, $programId, $routeId);
        return $route->members()
            ->with(['employee.user', 'employee.department', 'employee.division', 'shift', 'pickupLocation', 'dropoffLocation'])
            ->orderBy('route_order')
            ->get();
    }

    public function saveMember(string $corporateId, string $programId, string $routeId, array $data, ?string $memberId = null): CorporateTransportRouteMember
    {
        $route = $this->findRoute($corporateId, $programId, $routeId);
        $employee = CorporateEmployee::where('corporate_id', $corporateId)->findOrFail($data['corporate_employee_id']);
        $data['corporate_employee_id'] = $employee->id;

        if ($memberId) {
            $member = $route->members()->findOrFail($memberId);
            $member->update($data);
            return $member->fresh(['employee.user', 'shift', 'pickupLocation', 'dropoffLocation']);
        }

        return $route->members()->create($data)->fresh(['employee.user', 'shift', 'pickupLocation', 'dropoffLocation']);
    }

    public function deleteMember(string $corporateId, string $programId, string $routeId, string $memberId): void
    {
        $this->findRoute($corporateId, $programId, $routeId)->members()->findOrFail($memberId)->delete();
    }

    public function roster(string $corporateId, array $filters): array
    {
        $date = Carbon::parse($filters['date'] ?? now()->toDateString())->toDateString();
        $query = CorporateTransportParticipation::query()
            ->where('service_date', $date)
            ->whereHas('route.program', fn ($q) => $q->where('corporate_id', $corporateId))
            ->with(['employee.user', 'employee.department', 'employee.division', 'route', 'shift', 'booking']);

        foreach (['program_id', 'route_id', 'shift_id', 'direction', 'status'] as $filter) {
            if (!empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        return [
            'date' => $date,
            'items' => $query->orderBy('service_date')->paginate((int) ($filters['per_page'] ?? 50)),
        ];
    }

    public function employeeCalendar(CorporateEmployee $employee, array $filters): LengthAwarePaginator
    {
        $from = Carbon::parse($filters['date_from'] ?? now()->toDateString())->toDateString();
        $to = Carbon::parse($filters['date_to'] ?? now()->addDays(14)->toDateString())->toDateString();

        return CorporateTransportParticipation::where('corporate_employee_id', $employee->id)
            ->whereBetween('service_date', [$from, $to])
            ->with(['route', 'shift', 'booking.driverAssignments.driver', 'booking.vehicleAssignments.vehicle'])
            ->orderBy('service_date')
            ->paginate((int) ($filters['per_page'] ?? 50));
    }

    public function buildRoster(string $corporateId, string $date, ?string $programId = null): array
    {
        $programs = CorporateTransportProgram::where('corporate_id', $corporateId)
            ->where('is_active', true)
            ->where('status', 'active')
            ->when($programId, fn ($q) => $q->where('id', $programId))
            ->with(['routes.members.employee.user', 'routes.members.pickupLocation', 'routes.members.dropoffLocation', 'shifts'])
            ->get();

        $created = 0;
        foreach ($programs as $program) {
            foreach ($program->routes->where('is_active', true) as $route) {
                foreach ($program->shifts->where('is_active', true) as $shift) {
                    if (!$this->shiftRunsOn($shift, $date)) {
                        continue;
                    }
                    foreach ($route->members->where('is_active', true) as $member) {
                        if ($member->shift_id && $member->shift_id !== $shift->id) {
                            continue;
                        }
                        if (!$this->memberEffectiveOn($member, $date)) {
                            continue;
                        }

                        $participation = CorporateTransportParticipation::firstOrCreate(
                            [
                                'route_id' => $route->id,
                                'shift_id' => $shift->id,
                                'corporate_employee_id' => $member->corporate_employee_id,
                                'service_date' => $date,
                                'direction' => $route->direction,
                            ],
                            [
                                'program_id' => $program->id,
                                'status' => CorporateTransportParticipation::STATUS_INCLUDED,
                                'metadata' => ['route_member_id' => $member->id],
                            ]
                        );
                        $created += $participation->wasRecentlyCreated ? 1 : 0;
                    }
                }
            }
        }

        return ['created' => $created];
    }

    public function setParticipationStatus(string $corporateId, string $participationId, string $status, ?string $reason = null, bool $allowFrozen = false): CorporateTransportParticipation
    {
        $participation = CorporateTransportParticipation::whereHas('route.program', fn ($q) => $q->where('corporate_id', $corporateId))
            ->findOrFail($participationId);

        if (!$allowFrozen && $participation->frozen_at) {
            abort(422, 'This transport roster is already frozen. Ask a coordinator to override it.');
        }

        $participation->update([
            'status' => $status,
            'reason' => $reason,
            'changed_by_user_id' => Auth::id(),
        ]);

        return $participation->fresh(['employee.user', 'route', 'shift', 'booking']);
    }

    public function setEmployeeParticipationStatus(CorporateEmployee $employee, string $participationId, string $status, ?string $reason = null): CorporateTransportParticipation
    {
        if (!in_array($status, [
            CorporateTransportParticipation::STATUS_INCLUDED,
            CorporateTransportParticipation::STATUS_OPTED_OUT,
            CorporateTransportParticipation::STATUS_ON_LEAVE,
        ], true)) {
            abort(422, 'Employees can only opt in, opt out, or mark leave.');
        }

        $participation = CorporateTransportParticipation::where('corporate_employee_id', $employee->id)
            ->findOrFail($participationId);

        if ($participation->frozen_at || $this->isPastCutoff($participation->route, $participation->shift, $participation->service_date->toDateString())) {
            abort(422, 'The change deadline has passed for this transport roster.');
        }

        $participation->update([
            'status' => $status,
            'reason' => $reason,
            'changed_by_user_id' => Auth::id(),
        ]);

        return $participation->fresh(['employee.user', 'route', 'shift', 'booking']);
    }

    public function generateForDate(string $date, ?string $corporateId = null, bool $dryRun = false, bool $force = false): array
    {
        $summary = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'dry_run' => $dryRun];

        $corporates = Corporate::query()
            ->when($corporateId, fn ($q) => $q->where('id', $corporateId))
            ->where('is_active', true)
            ->get();

        foreach ($corporates as $corporate) {
            $this->buildRoster($corporate->id, $date);
        }

        $occurrences = CorporateTransportParticipation::query()
            ->select(['program_id', 'route_id', 'shift_id', 'service_date', 'direction'])
            ->where('service_date', $date)
            ->whereHas('route.program', fn ($q) => $q->when($corporateId, fn ($qq) => $qq->where('corporate_id', $corporateId)))
            ->groupBy(['program_id', 'route_id', 'shift_id', 'service_date', 'direction'])
            ->get();

        foreach ($occurrences as $occurrence) {
            try {
                $result = $this->generateOccurrence(
                    $occurrence->program_id,
                    $occurrence->route_id,
                    $occurrence->shift_id,
                    $occurrence->service_date->toDateString(),
                    $occurrence->direction,
                    $dryRun,
                    $force
                );
                $summary['processed']++;
                $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors']++;
                CorporateTransportGenerationLog::create([
                    'program_id' => $occurrence->program_id,
                    'route_id' => $occurrence->route_id,
                    'shift_id' => $occurrence->shift_id,
                    'service_date' => $date,
                    'direction' => $occurrence->direction,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    public function generateOccurrence(string $programId, string $routeId, string $shiftId, string $date, string $direction, bool $dryRun = false, bool $force = false): array
    {
        $program = CorporateTransportProgram::with('corporate')->findOrFail($programId);
        $route = CorporateTransportRoute::with(['vehicleGroup', 'serviceType', 'members.pickupLocation', 'members.dropoffLocation', 'members.employee.user'])->findOrFail($routeId);
        $shift = CorporateTransportShift::findOrFail($shiftId);

        if (!$force && !$this->isPastCutoff($route, $shift, $date)) {
            $this->logGeneration($programId, $routeId, $shiftId, $date, $direction, null, 'skipped', 0, 'Cutoff has not passed yet', $dryRun);
            return ['status' => 'skipped', 'booking_id' => null];
        }

        $participations = CorporateTransportParticipation::where([
                'program_id' => $programId,
                'route_id' => $routeId,
                'shift_id' => $shiftId,
                'service_date' => $date,
                'direction' => $direction,
            ])
            ->with('employee.user')
            ->get();

        $includedStatuses = [
            CorporateTransportParticipation::STATUS_INCLUDED,
            CorporateTransportParticipation::STATUS_COORDINATOR_INCLUDED,
        ];
        $included = $participations->whereIn('status', $includedStatuses)->values();

        if ($included->isEmpty()) {
            $existing = $this->findGeneratedBooking($programId, $routeId, $shiftId, $date, $direction);
            if ($existing && !$dryRun) {
                $existing->markAsCancelled('No included staff transport passengers', Auth::id());
            }
            $this->logGeneration($programId, $routeId, $shiftId, $date, $direction, $existing?->id, 'skipped', 0, 'No included employees', $dryRun);
            return ['status' => 'skipped', 'booking_id' => $existing?->id];
        }

        if ($dryRun) {
            $this->logGeneration($programId, $routeId, $shiftId, $date, $direction, null, 'dry_run', $included->count(), 'Dry run only', true);
            return ['status' => 'skipped', 'booking_id' => null];
        }

        return DB::transaction(function () use ($program, $route, $shift, $date, $direction, $included, $participations, $programId, $routeId, $shiftId) {
            $booking = $this->findGeneratedBooking($programId, $routeId, $shiftId, $date, $direction);
            $firstEmployee = $included->first()->employee;
            $metadata = $this->buildRouteMetadata($route, $shift, $date, $direction, $included);
            $pickupLocation = $metadata['primary_pickup'];
            $dropoffLocation = $metadata['primary_dropoff'];
            $fromDateTime = Carbon::parse($date . ' ' . $shift->pickup_time);
            $toDateTime = $shift->dropoff_time
                ? Carbon::parse($date . ' ' . $shift->dropoff_time)
                : $fromDateTime->copy()->addHour();

            if (!$booking) {
                $booking = Booking::create([
                    'customer_id' => $this->resolveCustomerIdForEmployee($firstEmployee),
                    'booking_date' => now(),
                    'status' => 'approved',
                    'confirmed' => false,
                    'is_corporate_booking' => true,
                    'corporate_account_id' => $program->corporate_id,
                    'employee_id' => $firstEmployee->user_id,
                    'corporate_department_id' => $firstEmployee->department_id,
                    'corporate_division_id' => $firstEmployee->division_id,
                    'passenger_count' => $included->count(),
                    'requires_approval' => false,
                    'approval_status' => 'approved',
                    'approval_by' => Auth::id(),
                    'approval_at' => now(),
                    'created_by_user_id' => Auth::id(),
                    'booking_source' => 'corporate_staff_transport',
                    'workflow_step' => 'approved',
                    'workflow_data' => [
                        'source' => 'corporate_staff_transport',
                        'program_id' => $programId,
                        'route_id' => $routeId,
                        'shift_id' => $shiftId,
                        'service_date' => $date,
                        'direction' => $direction,
                    ],
                ]);
                $status = 'created';
            } else {
                $booking->update([
                    'passenger_count' => $included->count(),
                    'status' => in_array($booking->status, ['cancelled', 'inquiry_cancelled'], true) ? 'approved' : $booking->status,
                    'workflow_data' => array_merge($booking->workflow_data ?? [], [
                        'source' => 'corporate_staff_transport',
                        'program_id' => $programId,
                        'route_id' => $routeId,
                        'shift_id' => $shiftId,
                        'service_date' => $date,
                        'direction' => $direction,
                    ]),
                ]);
                $status = 'updated';
            }

            $item = $booking->bookingItems()->first();
            $itemPayload = [
                'vehicle_group_id' => $route->vehicle_group_id,
                'service_type_id' => $route->service_type_id,
                'quantity' => 1,
                'unit_price' => 0,
                'total_price' => 0,
                'from_date' => $fromDateTime,
                'from_time' => $shift->pickup_time,
                'to_date' => $toDateTime,
                'to_time' => $shift->dropoff_time,
                'pickup_location' => $pickupLocation,
                'dropoff_location' => $dropoffLocation,
                'pickup_latitude' => $pickupLocation['latitude'] ?? null,
                'pickup_longitude' => $pickupLocation['longitude'] ?? null,
                'dropoff_latitude' => $dropoffLocation['latitude'] ?? null,
                'dropoff_longitude' => $dropoffLocation['longitude'] ?? null,
                'currency' => 'LKR',
                'status' => 'confirmed',
                'metadata' => $metadata['metadata'],
            ];

            if ($item) {
                $item->update($itemPayload);
            } else {
                $item = $booking->bookingItems()->create($itemPayload);
            }

            foreach ($participations as $participation) {
                $stopId = 'staff-transport:' . $participation->id;
                $participation->update([
                    'booking_id' => $booking->id,
                    'booking_item_id' => $item->id,
                    'booking_stop_id' => $stopId,
                    'frozen_at' => $participation->frozen_at ?? now(),
                ]);
            }

            $this->logGeneration($programId, $routeId, $shiftId, $date, $direction, $booking->id, $status, $included->count(), 'Generated staff transport booking', false);

            return ['status' => $status, 'booking_id' => $booking->id];
        });
    }

    public function logs(string $corporateId, array $filters = []): LengthAwarePaginator
    {
        return CorporateTransportGenerationLog::where(function ($query) use ($corporateId) {
                $query->whereHas('booking.corporateAccount', fn ($q) => $q->where('corporates.id', $corporateId))
                    ->orWhereHas('program', fn ($q) => $q->where('corporate_id', $corporateId));
            })
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function buildRouteMetadata(CorporateTransportRoute $route, CorporateTransportShift $shift, string $date, string $direction, Collection $included): array
    {
        $membersByEmployee = $route->members->keyBy('corporate_employee_id');
        $stops = $included
            ->sortBy(fn ($participation) => $membersByEmployee[$participation->corporate_employee_id]->route_order ?? 999999)
            ->values()
            ->map(function (CorporateTransportParticipation $participation, int $index) use ($membersByEmployee, $direction) {
                $member = $membersByEmployee[$participation->corporate_employee_id] ?? null;
                $location = $direction === 'dropoff'
                    ? ($member?->dropoffLocation ?: $member?->pickupLocation)
                    : ($member?->pickupLocation ?: $member?->dropoffLocation);
                $employee = $participation->employee;

                return [
                    'stop_id' => 'staff-transport:' . $participation->id,
                    'type' => $direction === 'dropoff' ? 'dropoff' : 'pickup',
                    'route_order' => $index + 1,
                    'label' => trim(($employee?->user?->first_name ?? '') . ' ' . ($employee?->user?->last_name ?? '')) ?: $employee?->employee_code,
                    'address' => $location?->address,
                    'latitude' => $location?->latitude,
                    'longitude' => $location?->longitude,
                    'contact' => [
                        'employee_id' => $employee?->id,
                        'employee_code' => $employee?->employee_code,
                        'contact_name' => trim(($employee?->user?->first_name ?? '') . ' ' . ($employee?->user?->last_name ?? '')),
                        'contact_phone' => $employee?->user?->phone,
                    ],
                ];
            })
            ->filter(fn ($stop) => !empty($stop['address']))
            ->values()
            ->all();

        $primaryPickup = $route->origin_location ?: ($stops[0] ?? []);
        $primaryDropoff = $route->destination_location ?: (end($stops) ?: $primaryPickup);

        return [
            'primary_pickup' => $primaryPickup,
            'primary_dropoff' => $primaryDropoff,
            'metadata' => [
                'staff_transport' => [
                    'route_id' => $route->id,
                    'shift_id' => $shift->id,
                    'service_date' => $date,
                    'direction' => $direction,
                ],
                'multi_pickup_locations' => $direction === 'pickup' ? $stops : [],
                'multi_dropoff_locations' => $direction === 'dropoff' ? $stops : [],
                'multi_route_stop_order' => $stops,
            ],
        ];
    }

    private function findGeneratedBooking(string $programId, string $routeId, string $shiftId, string $date, string $direction): ?Booking
    {
        return Booking::where('booking_source', 'corporate_staff_transport')
            ->where('workflow_data->program_id', $programId)
            ->where('workflow_data->route_id', $routeId)
            ->where('workflow_data->shift_id', $shiftId)
            ->where('workflow_data->service_date', $date)
            ->where('workflow_data->direction', $direction)
            ->first();
    }

    private function isPastCutoff(CorporateTransportRoute $route, CorporateTransportShift $shift, string $date): bool
    {
        $program = $route->relationLoaded('program') ? $route->program : $route->program()->first();
        $timezone = $program?->timezone ?: config('app.timezone', 'UTC');
        $cutoffMinutes = $shift->cutoff_minutes_before ?? $program?->cutoff_minutes_before ?? 720;
        $shiftTime = $shift->pickup_time ?: '00:00';
        $cutoffAt = Carbon::parse($date . ' ' . $shiftTime, $timezone)->subMinutes((int) $cutoffMinutes);

        return now($timezone)->greaterThanOrEqualTo($cutoffAt);
    }

    private function resolveCustomerIdForEmployee(CorporateEmployee $employee): string
    {
        return (string) Customer::firstOrCreate(
            ['user_id' => $employee->user_id],
            ['type' => 'business', 'sub_type' => 'local', 'category' => 'regular', 'created_user_id' => Auth::id(), 'updated_user_id' => Auth::id()]
        )->id;
    }

    private function findProgram(string $corporateId, string $programId): CorporateTransportProgram
    {
        return CorporateTransportProgram::where('corporate_id', $corporateId)->findOrFail($programId);
    }

    private function findRoute(string $corporateId, string $programId, string $routeId): CorporateTransportRoute
    {
        return $this->findProgram($corporateId, $programId)->routes()->findOrFail($routeId);
    }

    private function shiftRunsOn(CorporateTransportShift $shift, string $date): bool
    {
        $days = $shift->operating_days ?: [];
        if (empty($days)) {
            return true;
        }

        $day = strtolower(Carbon::parse($date)->englishDayOfWeek);
        return in_array($day, array_map('strtolower', $days), true);
    }

    private function memberEffectiveOn(CorporateTransportRouteMember $member, string $date): bool
    {
        return (!$member->effective_from || Carbon::parse($member->effective_from)->lte($date))
            && (!$member->effective_to || Carbon::parse($member->effective_to)->gte($date));
    }

    private function logGeneration(string $programId, string $routeId, string $shiftId, string $date, string $direction, ?string $bookingId, string $status, int $count, string $message, bool $dryRun): void
    {
        CorporateTransportGenerationLog::create([
            'program_id' => $programId,
            'route_id' => $routeId,
            'shift_id' => $shiftId,
            'service_date' => $date,
            'direction' => $direction,
            'booking_id' => $bookingId,
            'status' => $status,
            'included_count' => $count,
            'message' => $message,
            'context' => ['dry_run' => $dryRun],
        ]);
    }
}
