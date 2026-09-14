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
use App\Models\Corporate\CorporateEmployeeLocation;
use Illuminate\Validation\ValidationException;
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
        foreach (['vehicle_group_id' => 'vehicleGroups', 'service_type_id' => 'serviceTypes'] as $field => $relation) {
            if (!empty($data[$field]) && !$program->corporate->$relation()->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => ['Choose an option assigned to this company.']]);
            }
        }
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
        $member = $memberId ? $route->members()->findOrFail($memberId) : null;
        $data = array_merge($member?->only(['corporate_employee_id', 'shift_id', 'pickup_location_id', 'dropoff_location_id']) ?? [], $data);
        $employee = CorporateEmployee::where('corporate_id', $corporateId)->where('is_active', true)->findOrFail($data['corporate_employee_id']);
        if (!empty($data['shift_id'])) $route->program->shifts()->findOrFail($data['shift_id']);
        foreach (['pickup_location_id', 'dropoff_location_id'] as $field) {
            if (!empty($data[$field])) CorporateEmployeeLocation::where('corporate_employee_id', $employee->id)->where('is_active', true)->findOrFail($data[$field]);
        }
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
            ->with(['employee.user', 'employee.department', 'employee.division', 'route.program', 'shift', 'bookingItem.driver.user', 'bookingItem.vehicle', 'driverStops']);

        foreach (['program_id', 'route_id', 'shift_id', 'direction', 'status'] as $filter) {
            if (!empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }

        return [
            'date' => $date,
            'summary' => (clone $query)->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status')->all(),
            'items' => $query->orderBy('service_date')->orderBy('id')->paginate(min(200, max(1, (int) ($filters['per_page'] ?? 50))), ['*'], 'page', $filters['page'] ?? null)->through(fn ($row) => $this->participationView($row, true)),
        ];
    }

    public function employeeCalendar(CorporateEmployee $employee, array $filters): LengthAwarePaginator
    {
        $from = Carbon::parse($filters['date_from'] ?? now()->toDateString())->toDateString();
        $to = Carbon::parse($filters['date_to'] ?? now()->addDays(14)->toDateString())->toDateString();

        return CorporateTransportParticipation::where('corporate_employee_id', $employee->id)
            ->whereBetween('service_date', [$from, $to])
            ->with(['route.program', 'shift', 'bookingItem', 'driverStops'])
            ->orderBy('service_date')
            ->paginate(min(200, max(1, (int) ($filters['per_page'] ?? 50))))->through(fn ($row) => $this->participationView($row, false));
    }

    public function buildCalendar(string $corporateId, string $date, int $days = 14, ?string $programId = null): array
    {
        $created = 0;
        for ($offset = 0; $offset < min(31, max(1, $days)); $offset++) {
            $created += $this->buildRoster($corporateId, Carbon::parse($date)->addDays($offset)->toDateString(), $programId)['created'];
        }
        return ['created' => $created];
    }

    public function buildRoster(string $corporateId, string $date, ?string $programId = null): array
    {
        return DB::transaction(fn () => $this->buildRosterLocked($corporateId, $date, $programId), 3);
    }

    private function buildRosterLocked(string $corporateId, string $date, ?string $programId): array
    {
        $programs = CorporateTransportProgram::where('corporate_id', $corporateId)
            ->where('is_active', true)
            ->where('status', 'active')
            ->when($programId, fn ($q) => $q->where('id', $programId))
            ->with(['routes.members.employee.user', 'routes.members.pickupLocation', 'routes.members.dropoffLocation', 'shifts'])
            ->get();

        $created = 0;
        $eligibleIds = [];
        foreach ($programs as $program) {
            if (!$this->programRunsOn($program, $date)) continue;
            foreach ($program->routes->where('is_active', true)->sortBy('id') as $route) {
                CorporateTransportRoute::whereKey($route->id)->lockForUpdate()->firstOrFail();
                foreach ($program->shifts->where('is_active', true) as $shift) {
                    if (!$this->shiftRunsOn($shift, $date)) {
                        continue;
                    }
                    $frozen = CorporateTransportParticipation::where('route_id', $route->id)->where('shift_id', $shift->id)->where('service_date', $date)->whereNotNull('frozen_at')->exists();
                    if ($frozen) continue;
                    foreach ($route->members->where('is_active', true) as $member) {
                        if ($member->shift_id && $member->shift_id !== $shift->id) {
                            continue;
                        }
                        if (!$member->employee?->is_active || !$this->memberEffectiveOn($member, $date)) {
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
                                'status' => $program->default_opt_mode === 'opt_in' ? CorporateTransportParticipation::STATUS_OPTED_OUT : CorporateTransportParticipation::STATUS_INCLUDED,
                                'metadata' => ['route_member_id' => $member->id],
                            ]
                        );
                        $eligibleIds[] = $participation->id;
                        if ($participation->status === 'unavailable') {
                            $metadata = $participation->metadata ?? [];
                            $participation->update(['status' => $metadata['previous_status'] ?? 'opted_out']);
                        }
                        $created += $participation->wasRecentlyCreated ? 1 : 0;
                    }
                }
            }
        }

        CorporateTransportParticipation::where('service_date', $date)->whereNull('frozen_at')
            ->whereHas('route.program', fn ($q) => $q->where('corporate_id', $corporateId))
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->whereNotIn('id', $eligibleIds)->get()->each(function ($row) {
                if ($row->status === 'unavailable') return;
                $row->update(['status' => 'unavailable', 'metadata' => array_merge($row->metadata ?? [], ['previous_status' => $row->status])]);
            });
        return ['created' => $created];
    }

    public function setParticipationStatus(string $corporateId, string $participationId, string $status, ?string $reason = null, bool $allowFrozen = false): CorporateTransportParticipation
    {
        return DB::transaction(function () use ($corporateId, $participationId, $status, $reason) {
            $participation = CorporateTransportParticipation::whereHas('route.program', fn ($q) => $q->where('corporate_id', $corporateId))->findOrFail($participationId);
            CorporateTransportRoute::whereKey($participation->route_id)->lockForUpdate()->firstOrFail();
            $participation->refresh();
            if (!trim((string) $reason)) throw ValidationException::withMessages(['reason' => ['Explain the coordinator change.']]);
            $metadata = $participation->metadata ?? [];
            $metadata['last_change_request'] = $metadata['change_request'] ?? null;
            unset($metadata['change_request']);
            if ($status === 'reject_request') {
                $participation->update(['metadata' => $metadata, 'reason' => $reason, 'changed_by_user_id' => Auth::id()]);
                return $participation->fresh();
            }
            if ($participation->booking_id) {
                $booking = Booking::whereKey($participation->booking_id)->lockForUpdate()->firstOrFail();
                if (!in_array($booking->status, ['confirmed', 'approved', 'pending'], true)
                    || $booking->bookingItems()->whereNotIn('status', ['confirmed', 'approved', 'pending'])->exists()
                    || $booking->bookingItems()->whereNotNull('final_priced_at')->exists()
                    || $booking->driverAssignments()->whereNotIn('status', ['cancelled', 'rejected', 'completed'])->exists()) {
                    throw ValidationException::withMessages(['booking' => ['This journey is assigned, started, cancelled or finalized. Dispatch must release its assignment before a pre-trip manifest amendment. Finished journeys cannot be amended.']]);
                }
            }
            $participation->update(['status' => $status, 'reason' => $reason, 'metadata' => $metadata, 'changed_by_user_id' => Auth::id()]);
            if ($participation->booking_id) {
                $this->generateLockedOccurrence($participation->program_id, $participation->route_id, $participation->shift_id,
                    $participation->service_date->toDateString(), $participation->direction, false, true, true);
            }
            return $participation->fresh();
        });
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

        return DB::transaction(function () use ($employee, $participationId, $status, $reason) {
            $participation = CorporateTransportParticipation::where('corporate_employee_id', $employee->id)->findOrFail($participationId);
            CorporateTransportRoute::whereKey($participation->route_id)->lockForUpdate()->firstOrFail();
            $participation->refresh();
            if ($participation->status === 'unavailable') throw ValidationException::withMessages(['participation' => ['You are not scheduled for this journey. Contact your coordinator.']]);
            if ($participation->frozen_at || $this->isPastCutoff($participation->route, $participation->shift, $participation->service_date->toDateString())) {
                if (!trim((string) $reason)) throw ValidationException::withMessages(['reason' => ['The deadline has passed. Enter a reason to request a coordinator change.']]);
                if ($participation->service_date->toDateString() < now($participation->route->program->timezone)->toDateString()) {
                    throw ValidationException::withMessages(['date' => ['Past journeys cannot be changed.']]);
                }
                $metadata = $participation->metadata ?? [];
                $metadata['change_request'] = ['status' => $status, 'reason' => $reason, 'requested_at' => now()->toIso8601String(), 'user_id' => Auth::id()];
                $participation->update(['metadata' => $metadata]);
            } else {
                $participation->update(['status' => $status, 'reason' => $reason, 'changed_by_user_id' => Auth::id()]);
            }
            return $participation->fresh();
        });
    }

    public function generateForDate(string $date, ?string $corporateId = null, bool $dryRun = false, bool $force = false): array
    {
        if (!$dryRun) return $this->processDate($date, $corporateId, false, $force);
        DB::beginTransaction();
        try {
            return $this->processDate($date, $corporateId, true, $force);
        } finally {
            DB::rollBack();
        }
    }

    private function processDate(string $date, ?string $corporateId, bool $dryRun, bool $force): array
    {
        $summary = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'dry_run' => $dryRun, 'ready' => 0, 'details' => []];

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
                $summary['details'][] = ['route_id' => $occurrence->route_id, 'message' => $e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : $e->getMessage()];
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
        return DB::transaction(function () use ($programId, $routeId, $shiftId, $date, $direction, $dryRun, $force) {
            CorporateTransportRoute::where('program_id', $programId)->lockForUpdate()->findOrFail($routeId);
            return $this->generateLockedOccurrence($programId, $routeId, $shiftId, $date, $direction, $dryRun, $force);
        });
    }

    private function generateLockedOccurrence(string $programId, string $routeId, string $shiftId, string $date, string $direction, bool $dryRun, bool $force, bool $amend = false): array
    {
        $program = CorporateTransportProgram::with('corporate')->findOrFail($programId);
        $route = CorporateTransportRoute::with(['vehicleGroup', 'serviceType', 'members.pickupLocation', 'members.dropoffLocation', 'members.employee.user'])->findOrFail($routeId);
        $shift = $program->shifts()->findOrFail($shiftId);
        $existing = $this->findGeneratedBooking($programId, $routeId, $shiftId, $date, $direction);
        if ($existing && !$amend) return ['status' => 'skipped', 'booking_id' => $existing->id];
        if (!$program->corporate?->is_active || !$this->programRunsOn($program, $date) || !$route->is_active || !$shift->is_active || !$this->shiftRunsOn($shift, $date)) {
            return ['status' => 'skipped', 'booking_id' => null];
        }
        if (!in_array($direction, ['pickup', 'dropoff'], true) || $route->direction !== $direction) {
            throw ValidationException::withMessages(['direction' => ['Use separate pickup and dropoff routes for return transport.']]);
        }

        if (!$dryRun && !$force && !$this->isPastCutoff($route, $shift, $date)) {
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
        $eligibleMembers = $route->members->filter(fn ($member) => $member->is_active && $member->employee?->is_active
            && (!$member->shift_id || $member->shift_id === $shiftId) && $this->memberEffectiveOn($member, $date));
        $included = $participations->whereIn('status', $includedStatuses)
            ->whereIn('corporate_employee_id', $eligibleMembers->pluck('corporate_employee_id'))->values();

        if ($included->isEmpty()) {
            $existing = $this->findGeneratedBooking($programId, $routeId, $shiftId, $date, $direction);
            if ($existing && !$dryRun) {
                app(BookingFlowService::class)->updateBookingStatus($existing->id, 'cancelled', 'No included staff transport passengers', (string) Auth::id());
            }
            $this->logGeneration($programId, $routeId, $shiftId, $date, $direction, $existing?->id, 'skipped', 0, 'No included employees', $dryRun);
            return ['status' => 'skipped', 'booking_id' => $existing?->id];
        }

        $metadata = $this->buildRouteMetadata($route, $shift, $date, $direction, $included);
        if ($route->capacity && $included->count() > $route->capacity) {
            throw ValidationException::withMessages(['capacity' => ['Capacity exceeded. Choose a larger vehicle group or split this route before generation.']]);
        }
        if (!$route->vehicle_group_id || !$route->service_type_id) {
            throw ValidationException::withMessages(['route' => ['Select a vehicle group and service type.']]);
        }
        [$from, $to] = $this->journeyTimes($program, $shift, $date);
        $itemPayload = [
            'vehicle_group_id' => $route->vehicle_group_id,
            'service_type_id' => $route->service_type_id,
            'service_type' => $route->service_type_id,
            'from_date' => $from->toIso8601String(), 'from_time' => $from->format('H:i'),
            'to_date' => $to->toIso8601String(), 'to_time' => $to->format('H:i'),
            'pickup_location' => $metadata['primary_pickup'],
            'dropoff_location' => $metadata['primary_dropoff'],
            'metadata' => $metadata['metadata'],
        ];
        $payload = ['booking_items' => [$itemPayload], 'passenger_count' => $included->count(), 'contact_employee_id' => $included->first()->corporate_employee_id,
            'payment_collection_method' => 'monthly_invoice', 'payment_responsibility' => 'corporate'];
        if ($dryRun) {
            app(CorporateBookingService::class)->previewStaffTransportPricing($program->corporate, $payload);
            return ['status' => 'ready', 'booking_id' => null, 'passengers' => $included->count()];
        }
        if ($amend && $existing) {
            $payload['booking_items'][0]['id'] = $existing->bookingItems()->firstOrFail()->id;
            $booking = app(CorporateBookingService::class)->amendStaffTransportBooking($existing, $payload);
        } else {
            $booking = app(CorporateBookingService::class)->createStaffTransportBooking($program->corporate, $payload);
        }
        $booking->forceFill([
            'staff_transport_occurrence_key' => hash('sha256', implode('|', [$programId, $routeId, $shiftId, $date, $direction])),
            'booking_source' => 'corporate_staff_transport',
            'workflow_data' => array_merge($booking->workflow_data ?? [], [
                'source' => 'corporate_staff_transport', 'program_id' => $programId,
                'route_id' => $routeId, 'shift_id' => $shiftId, 'service_date' => $date, 'direction' => $direction,
            ]),
        ]);
        $booking->save();
        $item = $booking->bookingItems()->firstOrFail();
        foreach ($participations as $participation) {
            $participation->update([
                'booking_id' => $booking->id, 'booking_item_id' => $item->id,
                'booking_stop_id' => $included->contains('id', $participation->id) ? 'booking-item:'.$item->id.':staff-transport:'.$participation->id : null,
                'frozen_at' => now(),
            ]);
        }
        foreach ($participations as $participation) {
            $participation->employee?->user?->notify(new \App\Notifications\CorporateTransportNotification($route->name.' on '.$date.' has been '.($amend ? 'updated' : 'scheduled').'. Check My Transport for your participation.'));
        }
        $this->logGeneration($programId, $routeId, $shiftId, $date, $direction, $booking->id, $amend ? 'updated' : 'created', $included->count(), 'Generated priced staff transport booking', false);
        return ['status' => $amend ? 'updated' : 'created', 'booking_id' => $booking->id];
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
        $membersByEmployee = $route->members->filter(fn ($member) => $member->is_active && (!$member->shift_id || $member->shift_id === $shift->id))->keyBy('corporate_employee_id');
        $stops = $included
            ->sortBy(fn ($participation) => $membersByEmployee[$participation->corporate_employee_id]->route_order ?? 999999)
            ->values()
            ->map(function (CorporateTransportParticipation $participation, int $index) use ($membersByEmployee, $direction) {
                $member = $membersByEmployee[$participation->corporate_employee_id] ?? null;
                $location = $direction === 'dropoff'
                    ? ($member?->dropoffLocation ?: $member?->pickupLocation)
                    : ($member?->pickupLocation ?: $member?->dropoffLocation);
                if (!$location || !$location->is_active || !$location->address || !is_numeric($location->latitude) || !is_numeric($location->longitude)) {
                    throw ValidationException::withMessages(['locations' => ['Every included employee needs an active pickup/dropoff point with coordinates.']]);
                }
                $employee = $participation->employee;

                return [
                    'stop_id' => 'staff-transport:' . $participation->id,
                    'type' => $direction === 'dropoff' ? 'dropoff' : 'pickup',
                    'route_order' => $index + 1,
                    'label' => trim(($employee?->user?->first_name ?? '') . ' ' . ($employee?->user?->last_name ?? '')) ?: $employee?->employee_code,
                    'address' => $location?->address,
                    'latitude' => $location?->latitude,
                    'longitude' => $location?->longitude,
                    'employee_id' => $employee?->id,
                    'contact_name' => trim(($employee?->user?->first_name ?? '') . ' ' . ($employee?->user?->last_name ?? '')),
                    'contact_phone' => $employee?->user?->phone,
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

        $office = $direction === 'pickup' ? $route->destination_location : $route->origin_location;
        if (empty($office['address']) || !is_numeric($office['latitude'] ?? null) || !is_numeric($office['longitude'] ?? null)) {
            throw ValidationException::withMessages(['route' => ['Select the office location with coordinates.']]);
        }
        $primaryPickup = $direction === 'pickup' ? $stops[0] : $office;
        $primaryDropoff = $direction === 'dropoff' ? end($stops) : $office;

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
                'corporate_department_passengers' => $included->groupBy(fn ($p) => $p->employee?->department_id ?? 'unallocated')->map->count()->all(),
                'multi_pickup_locations' => $direction === 'pickup' ? array_slice($stops, 1) : [],
                'multi_dropoff_locations' => $direction === 'dropoff' ? array_slice($stops, 0, -1) : [],
                'multi_route_stop_order' => $direction === 'pickup' ? array_slice($stops, 1) : array_slice($stops, 0, -1),
                'staff_transport_stops' => $direction === 'pickup'
                    ? array_merge($stops, [array_merge($office, ['type' => 'dropoff', 'stop_id' => 'staff-office:'.$route->id])])
                    : array_merge([array_merge($office, ['type' => 'pickup', 'stop_id' => 'staff-office:'.$route->id])], $stops),
            ],
        ];
    }

    private function findGeneratedBooking(string $programId, string $routeId, string $shiftId, string $date, string $direction): ?Booking
    {
        return Booking::withTrashed()->where('booking_source', 'corporate_staff_transport')
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

    private function participationView(CorporateTransportParticipation $row, bool $coordinator): array
    {
        $route = $row->route;
        $shift = $row->shift;
        $program = $route?->program;
        $cutoff = $program && $shift ? Carbon::parse($row->service_date->toDateString().' '.$shift->pickup_time, $program->timezone)
            ->subMinutes($shift->cutoff_minutes_before ?? $program->cutoff_minutes_before ?? 720) : null;
        $stop = $row->driverStops->sortByDesc('updated_at')->first();
        $result = $row->only(['id', 'program_id', 'route_id', 'shift_id', 'corporate_employee_id', 'direction', 'status', 'booking_id', 'booking_item_id', 'frozen_at', 'reason']);
        $result['service_date'] = $row->service_date->toDateString();
        $result['route'] = $route?->only(['id', 'name', 'direction']);
        $result['shift'] = $shift?->only(['id', 'name', 'pickup_time', 'dropoff_time']);
        $result['cutoff_at'] = $cutoff?->toIso8601String();
        $result['can_change'] = $row->status !== 'unavailable' && !$row->frozen_at && $cutoff && now()->lt($cutoff);
        $result['trip_status'] = $row->bookingItem?->status;
        $result['driver'] = $row->bookingItem?->driver?->user?->only(['first_name', 'last_name', 'phone']);
        $result['vehicle'] = $row->bookingItem?->vehicle?->only(['license_plate']);
        $result['attendance_status'] = $stop?->status;
        $result['attendance_at'] = $stop?->completed_at?->toIso8601String();
        $result['attendance_reason'] = $stop?->skip_reason;
        $manifest = $row->bookingItem?->metadata['staff_transport_stops'] ?? [];
        $ownStop = collect($manifest)->firstWhere('stop_id', 'staff-transport:'.$row->id);
        if (!$ownStop && !$row->frozen_at && $route) {
            $member = $route->members()->where('corporate_employee_id', $row->corporate_employee_id)
                ->where(fn ($q) => $q->whereNull('shift_id')->orWhere('shift_id', $row->shift_id))->first();
            $location = $row->direction === 'pickup' ? ($member?->pickupLocation ?: $member?->dropoffLocation) : ($member?->dropoffLocation ?: $member?->pickupLocation);
            $ownStop = $location?->only(['address', 'latitude', 'longitude']);
        }
        $result['location'] = $ownStop ? array_intersect_key($ownStop, array_flip(['address', 'latitude', 'longitude'])) : null;
        $result['eligible'] = $row->frozen_at || ($program && $this->programRunsOn($program, $row->service_date->toDateString()));
        $result['change_request'] = $row->metadata['change_request'] ?? null;
        if ($coordinator) $result['employee'] = ['id' => $row->corporate_employee_id, 'employee_code' => $row->employee?->employee_code,
            'user' => $row->employee?->user?->only(['first_name', 'last_name'])];
        return $result;
    }

    private function programRunsOn(CorporateTransportProgram $program, string $date): bool
    {
        return $program->is_active && $program->status === 'active'
            && (!$program->start_date || $program->start_date->toDateString() <= $date)
            && (!$program->end_date || $program->end_date->toDateString() >= $date)
            && !in_array($date, $program->settings['excluded_dates'] ?? [], true);
    }

    public function journeyTimes(CorporateTransportProgram $program, CorporateTransportShift $shift, string $date): array
    {
        $timezone = $program->timezone ?: 'Asia/Colombo';
        $from = Carbon::parse($date . ' ' . $shift->pickup_time, $timezone);
        $to = $shift->dropoff_time ? Carbon::parse($date . ' ' . $shift->dropoff_time, $timezone) : $from->copy()->addHour();
        if ($to->lte($from)) $to->addDay();
        return [$from->utc(), $to->utc()];
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
