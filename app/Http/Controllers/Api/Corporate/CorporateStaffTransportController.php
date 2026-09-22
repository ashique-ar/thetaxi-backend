<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Corporate\CorporateTransportParticipation;
use App\Services\CorporateBookingService;
use App\Services\CorporateStaffTransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateStaffTransportController extends Controller
{
    public function __construct(
        protected CorporateStaffTransportService $transportService,
        protected CorporateBookingService $bookingService,
    ) {
        $this->middleware(function (Request $request, $next) {
            if ($request->route('corporate')) {
                $corporate = $request->route('corporate');
                $request->merge([
                    'corporate_id' => is_object($corporate) ? $corporate->id : (string) $corporate,
                ]);
            }

            $request->route()->forgetParameter('corporate');
            return $next($request);
        });

        $this->middleware('permission:staff-transport.manage|staff-transport.override|staff-transport.generate|view_all_bookings')->only(['programs', 'shifts', 'routes', 'members', 'roster', 'logs', 'generatedBooking', 'locations', 'exportRoster']);
        $this->middleware('permission:staff-transport.manage')->only(['storeProgram', 'updateProgram', 'storeShift', 'updateShift', 'deleteShift', 'storeRoute', 'updateRoute', 'deleteRoute', 'storeMember', 'updateMember', 'deleteMember', 'buildRoster', 'storeLocation']);
        $this->middleware('permission:staff-transport.override')->only(['setParticipation']);
        $this->middleware('permission:staff-transport.generate')->only(['generate']);
    }

    public function programs(Request $request): JsonResponse
    {
        $programs = $this->transportService->programs($request->corporate_id, $request->only(['page', 'per_page', 'is_active']));

        return response()->json([
            'status' => 'success',
            'data' => ['programs' => $programs],
        ]);
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $program = $this->transportService->createProgram($request->corporate_id, $this->validateProgram($request));

        return response()->json(['status' => 'success', 'data' => ['program' => $program]], 201);
    }

    public function updateProgram(Request $request, string $program): JsonResponse
    {
        $program = $this->transportService->updateProgram($request->corporate_id, $program, $this->validateProgram($request, true));

        return response()->json(['status' => 'success', 'data' => ['program' => $program]]);
    }

    public function shifts(Request $request, string $program): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['shifts' => $this->transportService->shifts($request->corporate_id, $program)],
        ]);
    }

    public function storeShift(Request $request, string $program): JsonResponse
    {
        $shift = $this->transportService->saveShift($request->corporate_id, $program, $this->validateShift($request));
        return response()->json(['status' => 'success', 'data' => ['shift' => $shift]], 201);
    }

    public function updateShift(Request $request, string $program, string $shift): JsonResponse
    {
        $shift = $this->transportService->saveShift($request->corporate_id, $program, $this->validateShift($request, true), $shift);
        return response()->json(['status' => 'success', 'data' => ['shift' => $shift]]);
    }

    public function deleteShift(Request $request, string $program, string $shift): JsonResponse
    {
        $this->transportService->deleteShift($request->corporate_id, $program, $shift);
        return response()->json(['status' => 'success']);
    }

    public function routes(Request $request, string $program): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['routes' => $this->transportService->routes($request->corporate_id, $program)],
        ]);
    }

    public function storeRoute(Request $request, string $program): JsonResponse
    {
        $route = $this->transportService->saveRoute($request->corporate_id, $program, $this->validateRoute($request));
        return response()->json(['status' => 'success', 'data' => ['route' => $route]], 201);
    }

    public function updateRoute(Request $request, string $program, string $route): JsonResponse
    {
        $route = $this->transportService->saveRoute($request->corporate_id, $program, $this->validateRoute($request, true), $route);
        return response()->json(['status' => 'success', 'data' => ['route' => $route]]);
    }

    public function deleteRoute(Request $request, string $program, string $route): JsonResponse
    {
        $this->transportService->deleteRoute($request->corporate_id, $program, $route);
        return response()->json(['status' => 'success']);
    }

    public function members(Request $request, string $program, string $route): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['members' => $this->transportService->members($request->corporate_id, $program, $route)],
        ]);
    }

    public function storeMember(Request $request, string $program, string $route): JsonResponse
    {
        $member = $this->transportService->saveMember($request->corporate_id, $program, $route, $this->validateMember($request));
        return response()->json(['status' => 'success', 'data' => ['member' => $member]], 201);
    }

    public function updateMember(Request $request, string $program, string $route, string $member): JsonResponse
    {
        $member = $this->transportService->saveMember($request->corporate_id, $program, $route, $this->validateMember($request, true), $member);
        return response()->json(['status' => 'success', 'data' => ['member' => $member]]);
    }

    public function deleteMember(Request $request, string $program, string $route, string $member): JsonResponse
    {
        $this->transportService->deleteMember($request->corporate_id, $program, $route, $member);
        return response()->json(['status' => 'success']);
    }

    public function buildRoster(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'program_id' => ['nullable', 'uuid'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:31'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->transportService->buildCalendar($request->corporate_id, $validated['date'], (int) ($validated['days'] ?? 14), $validated['program_id'] ?? null),
        ]);
    }

    public function roster(Request $request): JsonResponse
    {
        $roster = $this->transportService->roster($request->corporate_id, $request->only(['date', 'program_id', 'route_id', 'shift_id', 'direction', 'status', 'page', 'per_page']));

        return response()->json([
            'status' => 'success',
            'data' => ['roster' => $roster['items'], 'date' => $roster['date'], 'summary' => $roster['summary']],
        ]);
    }

    public function exportRoster(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $corporateId = $request->corporate_id;
        return response()->streamDownload(function () use ($corporateId, $data) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Date', 'Employee', 'Route', 'Shift', 'Participation', 'Trip status', 'Attendance', 'Attendance time', 'Reason'], ',', '"', '');
            $page = 1;
            do {
                $rows = $this->transportService->roster($corporateId, ['date' => $data['date'], 'page' => $page, 'per_page' => 200])['items'];
                foreach ($rows as $row) {
                    $values = [$row['service_date'], trim(($row['employee']['user']['first_name'] ?? '').' '.($row['employee']['user']['last_name'] ?? '')),
                        $row['route']['name'] ?? '', $row['shift']['name'] ?? '', $row['status'], $row['trip_status'], $row['attendance_status'], $row['attendance_at'], $row['attendance_reason'] ?? $row['reason']];
                    $values = array_map(fn ($value) => preg_match('/^[=+@\\t\\r-]/', (string) $value) ? "'".$value : $value, $values);
                    fputcsv($stream, $values, ',', '"', '');
                }
            } while ($page++ < $rows->lastPage());
            fclose($stream);
        }, 'staff-transport-'.$data['date'].'.csv', ['Content-Type' => 'text/csv']);
    }

    public function myCalendar(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('corporate_employee');
        abort_unless($employee, 403, 'Select an employee corporate context to use My Transport.');

        return response()->json([
            'status' => 'success',
            'data' => [
                'calendar' => $this->transportService->employeeCalendar(
                    $employee,
                    $request->only(['date_from', 'date_to', 'page', 'per_page'])
                ),
            ],
        ]);
    }

    public function setParticipation(Request $request, string $participation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', [
                CorporateTransportParticipation::STATUS_INCLUDED,
                CorporateTransportParticipation::STATUS_OPTED_OUT,
                CorporateTransportParticipation::STATUS_ON_LEAVE,
                CorporateTransportParticipation::STATUS_COORDINATOR_INCLUDED,
                CorporateTransportParticipation::STATUS_COORDINATOR_EXCLUDED,
                CorporateTransportParticipation::STATUS_NO_SHOW,
                'reject_request',
            ])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'participation' => $this->transportService->setParticipationStatus(
                    $request->corporate_id,
                    $participation,
                    $validated['status'],
                    $validated['reason'] ?? null,
                    true
                ),
            ],
        ]);
    }

    public function setMyParticipation(Request $request, string $participation): JsonResponse
    {
        abort_unless($request->attributes->get('corporate_employee'), 403, 'Select an employee corporate context to use My Transport.');
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', [
                CorporateTransportParticipation::STATUS_INCLUDED,
                CorporateTransportParticipation::STATUS_OPTED_OUT,
                CorporateTransportParticipation::STATUS_ON_LEAVE,
            ])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'participation' => $this->transportService->setEmployeeParticipationStatus(
                    $request->attributes->get('corporate_employee'),
                    $participation,
                    $validated['status'],
                    $validated['reason'] ?? null
                ),
            ],
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->transportService->generateForDate($validated['date'], $request->corporate_id, (bool) ($validated['dry_run'] ?? false), false),
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['logs' => $this->transportService->logs($request->corporate_id, $request->only(['page', 'per_page']))],
        ]);
    }

    public function generatedBooking(Request $request, string $booking): JsonResponse
    {
        $booking = \App\Models\Booking\Booking::where('corporate_account_id', $request->corporate_id)
            ->where('booking_source', 'corporate_staff_transport')
            ->findOrFail($booking);

        return response()->json([
            'status' => 'success',
            'data' => ['booking' => $this->bookingService->getCorporateBookingDetails($booking, $this->canViewPayments($request))],
        ]);
    }

    public function locations(Request $request): JsonResponse
    {
        $employee = \App\Models\Corporate\CorporateEmployee::where('corporate_id', $request->corporate_id)->findOrFail($request->query('employee_id'));
        return response()->json(['status' => 'success', 'data' => ['locations' => $employee->locations()->where('is_active', true)->get()]]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'corporate_employee_id' => ['required', 'uuid'], 'label' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:1000'], 'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $employee = \App\Models\Corporate\CorporateEmployee::where('corporate_id', $request->corporate_id)->where('is_active', true)->findOrFail($data['corporate_employee_id']);
        $location = $employee->locations()->create($data + ['is_active' => true]);
        return response()->json(['status' => 'success', 'data' => ['location' => $location]], 201);
    }

    private function validateProgram(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive,draft'],
            'timezone' => ['sometimes', 'timezone'],
            'default_opt_mode' => ['sometimes', 'string', 'in:opt_out,opt_in'],
            'cutoff_minutes_before' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string'],
            'settings' => ['nullable', 'array:excluded_dates'],
            'settings.excluded_dates' => ['sometimes', 'array', 'max:366'],
            'settings.excluded_dates.*' => ['date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateShift(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'pickup_time' => [$partial ? 'sometimes' : 'required', 'date_format:H:i'],
            'dropoff_time' => ['nullable', 'date_format:H:i'],
            'operating_days' => ['nullable', 'array'],
            'operating_days.*' => ['in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'cutoff_minutes_before' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateRoute(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:150'],
            'direction' => ['sometimes', 'string', 'in:pickup,dropoff'],
            'service_type_id' => ['nullable', 'uuid', 'exists:service_types,id'],
            'vehicle_group_id' => ['nullable', 'uuid', 'exists:vehicle_groups,id'],
            'origin_location' => ['nullable', 'array'],
            'destination_location' => ['nullable', 'array'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateMember(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'corporate_employee_id' => [$partial ? 'sometimes' : 'required', 'uuid', 'exists:corporate_employees,id'],
            'shift_id' => ['nullable', 'uuid', 'exists:corporate_transport_shifts,id'],
            'pickup_location_id' => ['nullable', 'uuid', 'exists:corporate_employee_locations,id'],
            'dropoff_location_id' => ['nullable', 'uuid', 'exists:corporate_employee_locations,id'],
            'route_order' => ['sometimes', 'integer', 'min:1'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function canViewPayments(Request $request): bool
    {
        $user = $request->user();
        $employee = $request->attributes->get('corporate_employee');
        $corporate = $employee?->corporate;

        return \App\Services\CorporatePortalPermission::allows($request, 'view_payments')
            || (\App\Services\CorporatePortalPermission::allows($request, 'create_bookings_for_others') && (bool) $corporate?->coordinator_can_view_payments);
    }
}
