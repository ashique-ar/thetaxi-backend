<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Hr\Concerns\AuthorizesAttendanceRequests;
use App\Models\Hr\Attendance\AttendanceDailyResult;
use App\Models\Company;
use App\Models\Staff;
use App\Services\Hr\Attendance\AttendanceResultService;
use App\Services\SingleCompanyScope;
use App\Services\StaffAccessService;
use App\Services\Hr\Ess\HrDomainRequestProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttendanceResultController extends Controller
{
    use AuthorizesAttendanceRequests;

    public function companyOptions(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'selected_id' => ['nullable', 'uuid']]);
        $companyIds = $this->authorizedCompanyIds($request);
        $defaultCompanyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if (! $companyIds->contains($defaultCompanyId)) $defaultCompanyId = null;
        $companies = Company::query()->whereIn('id', $companyIds)
            ->when($data['selected_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when(! empty($data['search']), fn ($query) => $query->whereLikeInsensitive('name', trim($data['search'])))
            ->orderByDesc('is_default')->orderBy('name')->limit(25)->get(['id', 'name', 'is_default']);

        return response()->json(['status' => 'success', 'default_company_id' => $defaultCompanyId, 'data' => $companies->map(fn ($company) => [
            'value' => (string) $company->id,
            'label' => $company->name,
            'is_default' => (bool) $company->is_default,
            'status' => 'active',
        ])->values()]);
    }

    public function index(Request $request, StaffAccessService $access): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'status' => ['nullable', Rule::in(['present', 'absent', 'incomplete', 'half_day', 'insufficient_hours', 'non_working', 'paid_leave', 'unpaid_leave', 'partial_paid_leave', 'partial_unpaid_leave'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $this->actorCompany($request, $data['company_id'] ?? null);
        $staffIds = $access->scope(Staff::query(), $request->user())->where('company_id', $companyId)->when($data['staff_id'] ?? null, fn($q, $id) => $q->whereKey($id))->select('id');
        $latest = DB::table('hr_attendance_daily_results')->selectRaw('company_id, staff_id, work_date, max(result_version) as result_version')->where('company_id', $companyId)->whereIn('staff_id', clone $staffIds)->when($data['from'] ?? null, fn($q, $v) => $q->whereDate('work_date', '>=', $v))->when($data['to'] ?? null, fn($q, $v) => $q->whereDate('work_date', '<=', $v))->groupBy('company_id', 'staff_id', 'work_date');
        $query = DB::table('hr_attendance_daily_results as result')->joinSub($latest, 'latest', fn($join) => $join->on('latest.company_id', '=', 'result.company_id')->on('latest.staff_id', '=', 'result.staff_id')->on('latest.work_date', '=', 'result.work_date')->on('latest.result_version', '=', 'result.result_version'))
            ->where('result.company_id', $companyId)
            ->join('staff as subject_staff', fn($join) => $join->on('subject_staff.id', '=', 'result.staff_id')->on('subject_staff.company_id', '=', 'result.company_id'))
            ->join('users as subject_user', 'subject_user.id', '=', 'subject_staff.user_id')
            ->select(['subject_staff.code as staff_code', 'subject_user.first_name as staff_first_name', 'subject_user.last_name as staff_last_name', 'result.work_date', 'result.result_version', 'result.day_status', 'result.first_in_at', 'result.last_out_at', 'result.worked_minutes', 'result.late_minutes', 'result.payable_minutes', 'result.source_kind'])
            ->when($data['from'] ?? null, fn($q, $v) => $q->whereDate('result.work_date', '>=', $v))->when($data['to'] ?? null, fn($q, $v) => $q->whereDate('result.work_date', '<=', $v))->when($data['status'] ?? null, fn($q, $v) => $q->where('result.day_status', $v))->orderByDesc('result.work_date')->orderBy('result.staff_id');
        $page = $query->paginate($request->integer('per_page', 50));
        $companyIds = Staff::query()->whereIn('id', clone $staffIds)->distinct()->pluck('company_id');
        $readiness = [
            'mapped_event_count' => DB::table('hr_attendance_raw_events')->where('company_id', $companyId)->whereIn('staff_id', clone $staffIds)->where('mapping_status', 'mapped')->when($data['from'] ?? null, fn($q, $v) => $q->whereDate('occurred_at', '>=', $v))->when($data['to'] ?? null, fn($q, $v) => $q->whereDate('occurred_at', '<=', $v))->count(),
            'active_calendar_count' => DB::table('hr_work_calendars')->whereIn('company_id', $companyIds)->where('status', 'active')->count(),
            'active_shift_count' => DB::table('hr_shift_definitions')->whereIn('company_id', $companyIds)->where('status', 'active')->count(),
            'approved_policy_count' => DB::table('hr_attendance_policies')->whereIn('company_id', $companyIds)->where('status', 'approved')->count(),
            'approved_roster_count' => DB::table('hr_roster_assignments')->where('company_id', $companyId)->whereIn('staff_id', clone $staffIds)->whereNotNull('approved_at')->count(),
        ];
        return response()->json(['status' => 'success', 'data' => $page, 'meta' => ['readiness' => $readiness, 'filters' => ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null, 'status' => $data['status'] ?? null]]]);
    }

    public function report(Request $request, StaffAccessService $access): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['present', 'absent', 'incomplete', 'half_day', 'insufficient_hours', 'non_working', 'paid_leave', 'unpaid_leave', 'partial_paid_leave', 'partial_unpaid_leave'])],
            'group_by' => ['required', Rule::in(['work_date', 'status', 'staff', 'company'])],
        ]);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 366, 422, 'Attendance reports are limited to a 367-day range.');

        $companyId = $this->actorCompany($request, $data['company_id'] ?? null);
        $staffIds = $access->scope(Staff::query(), $request->user())->where('company_id', $companyId)->select('id');
        $latest = DB::table('hr_attendance_daily_results')
            ->selectRaw('company_id, staff_id, work_date, max(result_version) as result_version')
            ->where('company_id', $companyId)
            ->whereIn('staff_id', clone $staffIds)
            ->whereDate('work_date', '>=', $data['from'])
            ->whereDate('work_date', '<=', $data['to'])
            ->groupBy('company_id', 'staff_id', 'work_date');
        $rows = DB::table('hr_attendance_daily_results as result')
            ->joinSub($latest, 'latest', fn($join) => $join->on('latest.company_id', '=', 'result.company_id')->on('latest.staff_id', '=', 'result.staff_id')->on('latest.work_date', '=', 'result.work_date')->on('latest.result_version', '=', 'result.result_version'))
            ->where('result.company_id', $companyId)
            ->join('staff', fn ($join) => $join->on('staff.id', '=', 'result.staff_id')->on('staff.company_id', '=', 'result.company_id'))
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->join('companies', 'companies.id', '=', 'result.company_id')
            ->when($data['status'] ?? null, fn($query, $status) => $query->where('result.day_status', $status));

        $aggregates = "count(*) as days, count(distinct result.staff_id) as staff_count, sum(case when result.day_status = 'present' then 1 else 0 end) as present_days, sum(case when result.day_status = 'absent' then 1 else 0 end) as absent_days, sum(case when result.day_status in ('incomplete', 'insufficient_hours') then 1 else 0 end) as exception_days, coalesce(sum(result.worked_minutes), 0) as worked_minutes, coalesce(sum(result.late_minutes), 0) as late_minutes, coalesce(sum(result.early_leave_minutes), 0) as early_leave_minutes, coalesce(sum(result.payable_minutes), 0) as payable_minutes";
        $summary = (clone $rows)->selectRaw($aggregates)->first();
        $groupedQuery = clone $rows;
        match ($data['group_by']) {
            'work_date' => $groupedQuery->selectRaw("result.work_date as key, cast(result.work_date as varchar) as label, {$aggregates}")->groupBy('result.work_date')->orderByDesc('result.work_date'),
            'status' => $groupedQuery->selectRaw("result.day_status as key, result.day_status as label, {$aggregates}")->groupBy('result.day_status')->orderBy('result.day_status'),
            'staff' => $groupedQuery->selectRaw("result.staff_id as key, concat(coalesce(staff.code || ' · ', ''), users.first_name, ' ', users.last_name) as label, {$aggregates}")->groupBy('result.staff_id', 'staff.code', 'users.first_name', 'users.last_name')->orderBy('users.first_name')->orderBy('users.last_name'),
            'company' => $groupedQuery->selectRaw("staff.company_id as key, companies.name as label, {$aggregates}")->groupBy('staff.company_id', 'companies.name')->orderBy('companies.name'),
        };
        $grouped = $groupedQuery->get()->map(static fn (object $row): array => [
            'label' => $row->label,
            'days' => (int) $row->days,
            'staff_count' => (int) $row->staff_count,
            'present_days' => (int) $row->present_days,
            'absent_days' => (int) $row->absent_days,
            'exception_days' => (int) $row->exception_days,
            'worked_minutes' => (int) $row->worked_minutes,
            'late_minutes' => (int) $row->late_minutes,
            'early_leave_minutes' => (int) $row->early_leave_minutes,
            'payable_minutes' => (int) $row->payable_minutes,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'days' => (int) ($summary->days ?? 0),
                    'staff_count' => (int) ($summary->staff_count ?? 0),
                    'present_days' => (int) ($summary->present_days ?? 0),
                    'absent_days' => (int) ($summary->absent_days ?? 0),
                    'exception_days' => (int) ($summary->exception_days ?? 0),
                    'worked_minutes' => (int) ($summary->worked_minutes ?? 0),
                    'late_minutes' => (int) ($summary->late_minutes ?? 0),
                    'early_leave_minutes' => (int) ($summary->early_leave_minutes ?? 0),
                    'payable_minutes' => (int) ($summary->payable_minutes ?? 0),
                ],
                'groups' => $grouped,
                'filters' => [
                    'from' => $data['from'],
                    'to' => $data['to'],
                    'status' => $data['status'] ?? null,
                    'group_by' => $data['group_by'],
                ],
                'generated_at' => now()->toIso8601String(),
            ]
        ]);
    }

    public function calculate(Request $request, AttendanceResultService $service, StaffAccessService $access): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'staff_id' => ['required', 'uuid', 'exists:staff,id'], 'work_date' => ['required', 'date']]);
        $companyId = $this->actorCompany($request, $data['company_id']);
        abort_unless(
            $access->scope(Staff::query(), $request->user())->where('company_id', $companyId)->whereKey($data['staff_id'])->exists(),
            404,
            'Attendance Staff record is outside your authorized scope.'
        );

        return response()->json(['status' => 'success', 'data' => $service->calculate($companyId, $data['staff_id'], $data['work_date'], $request->user()->id)]);
    }

    public function calendars(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid']]);
        $companyId = $this->actorCompany($request, $data['company_id'] ?? null);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_work_calendars')->where('company_id', $companyId)->orderBy('code')->get()]);
    }

    public function calendarDays(Request $request, string $calendarId): JsonResponse
    {
        $calendar = DB::table('hr_work_calendars')->whereIn('company_id', $this->authorizedCompanyIds($request))->find($calendarId);
        abort_unless($calendar, 404);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_work_calendar_days')->where('calendar_id', $calendarId)->orderBy('calendar_date')->get()]);
    }

    public function storeCalendar(Request $request): JsonResponse
    {
        $this->writes();
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone'], 'weekly_working_days' => ['required', 'array', 'min:1'], 'weekly_working_days.*' => ['required', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']]);
        $this->sameCompany($request, $data['company_id']);
        $id = (string) Str::uuid();
        return DB::transaction(function () use ($request, $data, $id) {
            $this->lockActiveCompany($data['company_id']);
            DB::table('hr_work_calendars')->insert($this->json($data, ['weekly_working_days']) + ['id' => $id, 'status' => 'active', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())->withProperties(['company_id' => $data['company_id'], 'status' => 'active'])->log('attendance_calendar_created');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_work_calendars')->find($id)], 201);
        });
    }

    public function updateCalendar(Request $request, string $calendarId): JsonResponse
    {
        $this->writes();
        $row = DB::table('hr_work_calendars')->whereIn('company_id', $this->authorizedCompanyIds($request))->find($calendarId);
        abort_unless($row, 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:80', Rule::unique('hr_work_calendars', 'code')->where('company_id', $row->company_id)->ignore($calendarId)], 'name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone'], 'weekly_working_days' => ['required', 'array', 'min:1'], 'weekly_working_days.*' => ['required', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'], 'status' => ['required', Rule::in(['active', 'inactive'])]]);
        return DB::transaction(function () use ($request, $calendarId, $data) {
            $candidate = DB::table('hr_work_calendars')->where('id', $calendarId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $calendar = DB::table('hr_work_calendars')->where('id', $calendarId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($calendar, 404);
            DB::table('hr_work_calendars')->where('id', $calendarId)->where('company_id', $calendar->company_id)->update($this->json($data, ['weekly_working_days']) + ['updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())->withProperties(['company_id' => $calendar->company_id, 'status' => $data['status'], 'changed_fields' => array_keys($data)])->log('attendance_calendar_updated');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_work_calendars')->find($calendarId)]);
        });
    }

    public function storeCalendarDay(Request $request, string $calendarId): JsonResponse
    {
        $this->writes();
        abort_unless(DB::table('hr_work_calendars')->whereIn('company_id', $this->authorizedCompanyIds($request))->where('id', $calendarId)->exists(), 404);
        $data = $request->validate(['calendar_date' => ['required', 'date'], 'day_type' => ['required', Rule::in(['working', 'holiday', 'rest_day', 'special_leave'])], 'name' => ['nullable', 'string', 'max:255'], 'paid' => ['required', 'boolean']]);
        $id = (string) Str::uuid();
        return DB::transaction(function () use ($request, $calendarId, $data, $id) {
            $candidate = DB::table('hr_work_calendars')->whereIn('company_id', $this->authorizedCompanyIds($request))->where('id', $calendarId)->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $calendar = DB::table('hr_work_calendars')->where('id', $calendarId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($calendar, 404);
            DB::table('hr_work_calendar_days')->insert($data + ['id' => $id, 'calendar_id' => $calendarId, 'created_at' => now(), 'updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())->withProperties(['company_id' => $calendar->company_id, 'status' => $calendar->status, 'day_type' => $data['day_type']])->log('attendance_calendar_day_created');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_work_calendar_days')->find($id)], 201);
        });
    }

    public function updateCalendarDay(Request $request, string $calendarId, string $dayId): JsonResponse
    {
        $this->writes();
        abort_unless(DB::table('hr_work_calendars')->whereIn('company_id', $this->authorizedCompanyIds($request))->where('id', $calendarId)->exists(), 404);
        abort_unless(DB::table('hr_work_calendar_days')->where('id', $dayId)->where('calendar_id', $calendarId)->exists(), 404);
        $data = $request->validate(['calendar_date' => ['required', 'date', Rule::unique('hr_work_calendar_days', 'calendar_date')->where('calendar_id', $calendarId)->ignore($dayId)], 'day_type' => ['required', Rule::in(['working', 'holiday', 'rest_day', 'special_leave'])], 'name' => ['nullable', 'string', 'max:255'], 'paid' => ['required', 'boolean']]);
        return DB::transaction(function () use ($request, $calendarId, $dayId, $data) {
            $candidate = DB::table('hr_work_calendars')->whereIn('company_id', $this->authorizedCompanyIds($request))->where('id', $calendarId)->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $calendar = DB::table('hr_work_calendars')->where('id', $calendarId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($calendar, 404);
            $day = DB::table('hr_work_calendar_days')->where('id', $dayId)->where('calendar_id', $calendarId)->lockForUpdate()->first();
            abort_unless($day, 404);
            DB::table('hr_work_calendar_days')->where('id', $dayId)->where('calendar_id', $calendarId)->update($data + ['updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())->withProperties(['company_id' => $calendar->company_id, 'status' => $calendar->status, 'day_type' => $data['day_type']])->log('attendance_calendar_day_updated');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_work_calendar_days')->find($dayId)]);
        });
    }

    public function shifts(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid']]);
        $companyId = $this->actorCompany($request, $data['company_id'] ?? null);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_shift_definitions')->where('company_id', $companyId)->orderBy('code')->get()]);
    }

    public function storeShift(Request $request): JsonResponse
    {
        $this->writes();
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:255'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'], 'ends_next_day' => ['required', 'boolean'], 'unpaid_break_minutes' => ['required', 'integer', 'min:0', 'max:600'], 'grace_in_minutes' => ['required', 'integer', 'min:0', 'max:180'], 'grace_out_minutes' => ['required', 'integer', 'min:0', 'max:180'], 'minimum_half_day_minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'minimum_full_day_minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'timezone' => ['required', 'timezone'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']]);
        $this->sameCompany($request, $data['company_id']);
        abort_if($data['minimum_half_day_minutes'] > $data['minimum_full_day_minutes'], 422, 'Half-day minutes cannot exceed full-day minutes.');
        $id = (string) Str::uuid();
        return DB::transaction(function () use ($request, $data, $id) {
            $this->lockActiveCompany($data['company_id']);
            DB::table('hr_shift_definitions')->insert($data + ['id' => $id, 'status' => 'active', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())->withProperties(['company_id' => $data['company_id'], 'status' => 'active'])->log('attendance_shift_created');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_shift_definitions')->find($id)], 201);
        });
    }

    public function updateShift(Request $request, string $shiftId, AttendanceResultService $results): JsonResponse
    {
        $this->writes();
        $row = DB::table('hr_shift_definitions')->whereIn('company_id', $this->authorizedCompanyIds($request))->find($shiftId);
        abort_unless($row, 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:80', Rule::unique('hr_shift_definitions', 'code')->where('company_id', $row->company_id)->ignore($shiftId)], 'name' => ['required', 'string', 'max:255'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'], 'ends_next_day' => ['required', 'boolean'], 'unpaid_break_minutes' => ['required', 'integer', 'min:0', 'max:600'], 'grace_in_minutes' => ['required', 'integer', 'min:0', 'max:180'], 'grace_out_minutes' => ['required', 'integer', 'min:0', 'max:180'], 'minimum_half_day_minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'minimum_full_day_minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'timezone' => ['required', 'timezone'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'], 'status' => ['required', Rule::in(['active', 'inactive'])]]);
        abort_if($data['minimum_half_day_minutes'] > $data['minimum_full_day_minutes'], 422, 'Half-day minutes cannot exceed full-day minutes.');
        $row = DB::transaction(function () use ($request, $shiftId, $data) {
            $candidate = DB::table('hr_shift_definitions')->where('id', $shiftId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $shift = DB::table('hr_shift_definitions')->where('id', $shiftId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($shift, 404);
            DB::table('hr_shift_definitions')->where('id', $shiftId)->where('company_id', $shift->company_id)->update($data + ['updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())->withProperties(['company_id' => $shift->company_id, 'status' => $data['status'], 'changed_fields' => array_keys($data)])->log('attendance_shift_updated');
            return $shift;
        });
        $to = CarbonImmutable::today($data['timezone']);
        $from = $to->subDays(30)->max(CarbonImmutable::parse($data['effective_from'], $data['timezone']));
        $recalculated = 0;
        $rosters = DB::table('hr_roster_assignments')->where('company_id', $row->company_id)->where('shift_id', $shiftId)->whereNotNull('approved_at')->whereDate('effective_from', '<=', $to)->where(fn($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $from))->get();
        foreach ($rosters as $roster) {
            for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                if ($date->lt(CarbonImmutable::parse($roster->effective_from)) || ($roster->effective_until && $date->gte(CarbonImmutable::parse($roster->effective_until))))
                    continue;
                try {
                    $results->calculate($row->company_id, $roster->staff_id, $date->toDateString(), $request->user()->id);
                    $recalculated++;
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    // Locked periods and dates without complete effective rules remain unchanged.
                }
            }
        }
        return response()->json(['status' => 'success', 'data' => DB::table('hr_shift_definitions')->find($shiftId), 'meta' => ['recalculated_result_count' => $recalculated]]);
    }

    public function policies(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['draft', 'pending_approval', 'approved'])]]);
        $companyId = $this->actorCompany($request, $data['company_id'] ?? null);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_policies')->where('company_id', $companyId)->when($data['status'] ?? null, fn($q, $v) => $q->where('status', $v))->latest('created_at')->get()]);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $this->writes();
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:80'], 'name' => ['required', 'string', 'max:255'], 'rules' => ['required', 'array'], 'rules.maximum_payable_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']]);
        $this->sameCompany($request, $data['company_id']);
        $id = (string) Str::uuid();
        return DB::transaction(function () use ($request, $data, $id) {
            $this->lockActiveCompany($data['company_id']);
            DB::table('hr_attendance_policies')->insert($this->json($data, ['rules']) + ['id' => $id, 'status' => 'pending_approval', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())
                ->withProperties(['policy_id' => $id, 'company_id' => $data['company_id'], 'fields' => array_keys($data)])
                ->log('attendance_policy_created');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_policies')->find($id)], 201);
        });
    }

    public function updatePolicy(Request $request, string $policyId): JsonResponse
    {
        $this->writes();
        $row = DB::table('hr_attendance_policies')->whereIn('company_id', $this->authorizedCompanyIds($request))->find($policyId);
        abort_unless($row, 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:80', Rule::unique('hr_attendance_policies', 'code')->where('company_id', $row->company_id)->ignore($policyId)], 'name' => ['required', 'string', 'max:255'], 'rules' => ['required', 'array'], 'rules.maximum_payable_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from']]);
        return DB::transaction(function () use ($request, $policyId, $data) {
            $candidate = DB::table('hr_attendance_policies')->where('id', $policyId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $locked = DB::table('hr_attendance_policies')->where('id', $policyId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($locked, 404);
            abort_unless(in_array($locked->status, ['draft', 'pending_approval'], true), 409, 'Approved attendance policies cannot be changed.');
            DB::table('hr_attendance_policies')->where('id', $policyId)->where('company_id', $locked->company_id)->update($this->json($data, ['rules']) + ['updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())
                ->withProperties(['policy_id' => $policyId, 'changed_fields' => array_keys($data)])
                ->log('attendance_policy_updated');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_policies')->find($policyId)]);
        });
    }

    public function approvePolicy(Request $request, string $policyId): JsonResponse
    {
        $this->writes();
        return DB::transaction(function () use ($request, $policyId) {
            $candidate = DB::table('hr_attendance_policies')->where('id', $policyId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $row = DB::table('hr_attendance_policies')->where('id', $policyId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->created_by === $request->user()->id, 409, 'Policy creator cannot approve the same policy.');
            if ($row->status === 'approved' && (string) $row->approved_by === (string) $request->user()->id) {
                return response()->json(['status' => 'success', 'data' => $row]);
            }
            abort_unless($row->status === 'pending_approval', 409, 'Only a pending policy may be approved.');
            DB::table('hr_attendance_policies')->where('id', $policyId)->where('company_id', $row->company_id)->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())
                ->withProperties(['company_id' => $row->company_id, 'status' => 'approved', 'effective_from' => $row->effective_from])
                ->log('attendance_policy_approved');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_policies')->find($policyId)]);
        });
    }

    public function rosters(Request $request, StaffAccessService $access): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'staff_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['pending_approval', 'approved'])]]);
        $companyId = $this->actorCompany($request, $data['company_id']);
        $staffIds = $access->scope(Staff::query()->where('company_id', $companyId), $request->user())->when($data['staff_id'] ?? null, fn($q, $id) => $q->whereKey($id))->select('id');
        $query = DB::table('hr_roster_assignments')->where('company_id', $companyId)->whereIn('staff_id', $staffIds)->when(($data['status'] ?? null) === 'approved', fn($q) => $q->whereNotNull('approved_at'))->when(($data['status'] ?? null) === 'pending_approval', fn($q) => $q->whereNull('approved_at'))->latest('created_at');
        return response()->json(['status' => 'success', 'data' => $query->paginate($request->integer('per_page', 50))]);
    }

    public function storeRoster(Request $request): JsonResponse
    {
        $this->writes();
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'staff_id' => ['required', 'uuid'], 'calendar_id' => ['required', 'uuid'], 'shift_id' => ['required', 'uuid'], 'policy_id' => ['required', 'uuid'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:500']]);
        $this->sameCompany($request, $data['company_id']);
        return DB::transaction(function () use ($request, $data) {
            $this->lockActiveCompany($data['company_id']);
            foreach (['staff' => 'staff_id', 'hr_work_calendars' => 'calendar_id', 'hr_shift_definitions' => 'shift_id', 'hr_attendance_policies' => 'policy_id'] as $table => $field)
                abort_unless(DB::table($table)->where('id', $data[$field])->where('company_id', $data['company_id'])->exists(), 422, 'Roster references must belong to one legal entity.');
            $overlap = DB::table('hr_roster_assignments')->where('staff_id', $data['staff_id'])->whereDate('effective_from', '<', $data['effective_until'] ?? '9999-12-31')->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $data['effective_from']))->exists();
            abort_if($overlap, 409, 'An overlapping roster already exists.');
            $id = (string) Str::uuid();
            DB::table('hr_roster_assignments')->insert($data + ['id' => $id, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_roster_assignments')->find($id)], 201);
        });
    }

    public function updateRoster(Request $request, string $rosterId): JsonResponse
    {
        $this->writes();
        return DB::transaction(function () use ($request, $rosterId) {
            $candidate = DB::table('hr_roster_assignments')->where('id', $rosterId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $row = DB::table('hr_roster_assignments')->where('id', $rosterId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $data = $request->validate(['staff_id' => ['required', 'uuid'], 'calendar_id' => ['required', 'uuid'], 'shift_id' => ['required', 'uuid'], 'policy_id' => ['required', 'uuid'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:500']]);
            foreach (['staff' => 'staff_id', 'hr_work_calendars' => 'calendar_id', 'hr_shift_definitions' => 'shift_id', 'hr_attendance_policies' => 'policy_id'] as $table => $field)
                abort_unless(DB::table($table)->where('id', $data[$field])->where('company_id', $row->company_id)->exists(), 422, 'Roster references must belong to one legal entity.');
            $overlap = DB::table('hr_roster_assignments')->where('staff_id', $data['staff_id'])->where('id', '!=', $rosterId)->whereDate('effective_from', '<', $data['effective_until'] ?? '9999-12-31')->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $data['effective_from']))->exists();
            abort_if($overlap, 409, 'An overlapping roster already exists.');
            DB::table('hr_roster_assignments')->where('id', $rosterId)->where('company_id', $row->company_id)->update($data + ['approved_by' => null, 'approved_at' => null, 'created_by' => $request->user()->id, 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_roster_assignments')->find($rosterId)]);
        });
    }

    /**
     * §5.6 QH2-02: "bulk roster assignment" — assigns one shared calendar/shift/policy/effective
     * window to many staff in one atomic call, reusing storeRoster's exact per-staff legal-entity
     * and overlap checks. A staff row that already has an overlapping roster is skipped and
     * reported rather than aborting the whole batch, matching the partial-row-error convention
     * already used for QS5-02 target import.
     */
    public function storeRosterBulk(Request $request): JsonResponse
    {
        $this->writes();
        $data = $request->validate(['company_id' => ['required', 'uuid'], 'staff_ids' => ['required', 'array', 'min:1', 'max:200'], 'staff_ids.*' => ['required', 'uuid', 'distinct'], 'calendar_id' => ['required', 'uuid'], 'shift_id' => ['required', 'uuid'], 'policy_id' => ['required', 'uuid'], 'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after:effective_from'], 'reason' => ['required', 'string', 'max:500']]);
        $this->sameCompany($request, $data['company_id']);
        return DB::transaction(function () use ($request, $data) {
            $this->lockActiveCompany($data['company_id']);
            foreach (['hr_work_calendars' => 'calendar_id', 'hr_shift_definitions' => 'shift_id', 'hr_attendance_policies' => 'policy_id'] as $table => $field)
                abort_unless(DB::table($table)->where('id', $data[$field])->where('company_id', $data['company_id'])->exists(), 422, 'Roster references must belong to one legal entity.');
            $created = [];
            $skipped = [];
            foreach ($data['staff_ids'] as $staffId) {
                if (!DB::table('staff')->where('id', $staffId)->where('company_id', $data['company_id'])->exists()) {
                    $skipped[] = ['staff_id' => $staffId, 'reason' => 'Staff does not belong to this legal entity.'];
                    continue;
                }
                $overlap = DB::table('hr_roster_assignments')->where('staff_id', $staffId)->whereDate('effective_from', '<', $data['effective_until'] ?? '9999-12-31')->where(fn($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $data['effective_from']))->exists();
                if ($overlap) {
                    $skipped[] = ['staff_id' => $staffId, 'reason' => 'An overlapping roster already exists.'];
                    continue;
                }
                $id = (string) Str::uuid();
                DB::table('hr_roster_assignments')->insert(['id' => $id, 'company_id' => $data['company_id'], 'staff_id' => $staffId, 'calendar_id' => $data['calendar_id'], 'shift_id' => $data['shift_id'], 'policy_id' => $data['policy_id'], 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null, 'reason' => $data['reason'], 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                $created[] = DB::table('hr_roster_assignments')->find($id);
            }
            return response()->json(['status' => 'success', 'data' => ['created' => $created, 'skipped' => $skipped]], 201);
        });
    }

    public function approveRoster(Request $request, string $rosterId): JsonResponse
    {
        $this->writes();
        return DB::transaction(function () use ($request, $rosterId) {
            $candidate = DB::table('hr_roster_assignments')->where('id', $rosterId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $row = DB::table('hr_roster_assignments')->where('id', $rosterId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->created_by === $request->user()->id, 409, 'Roster creator cannot approve the same roster.');
            if ($row->approved_at) {
                abort_unless((string) $row->approved_by === (string) $request->user()->id, 409, 'Roster is already approved.');

                return response()->json(['status' => 'success', 'data' => $row]);
            }
            $calendar = DB::table('hr_work_calendars')->where('id', $row->calendar_id)->where('company_id', $row->company_id)->lockForUpdate()->first();
            $shift = DB::table('hr_shift_definitions')->where('id', $row->shift_id)->where('company_id', $row->company_id)->lockForUpdate()->first();
            $policy = DB::table('hr_attendance_policies')->where('id', $row->policy_id)->where('company_id', $row->company_id)->lockForUpdate()->first();
            abort_unless($calendar?->status === 'active' && $shift?->status === 'active' && $policy?->status === 'approved', 422, 'Roster requires an active calendar and shift and an approved policy.');
            DB::table('hr_roster_assignments')->where('id', $rosterId)->where('company_id', $row->company_id)->update(['approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())
                ->withProperties(['company_id' => $row->company_id, 'status' => 'approved', 'effective_from' => $row->effective_from])
                ->log('attendance_roster_approved');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_roster_assignments')->find($rosterId)]);
        });
    }

    public function requestCorrection(): JsonResponse
    {
        $this->writes();
        abort(409, 'Attendance correction submission is unavailable until the correction-type-to-field mapping is approved.');
    }

    public function corrections(Request $request, StaffAccessService $access): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['pending_approval', 'approved', 'rejected'])]]);
        $companyId = $this->actorCompany($request, $data['company_id'] ?? null);
        $staffIds = $access->scope(Staff::query(), $request->user())->where('company_id', $companyId)->when($data['staff_id'] ?? null, fn($q, $id) => $q->whereKey($id))->select('id');
        $query = DB::table('hr_attendance_correction_requests as correction')
            ->leftJoin('staff as subject_staff', fn($join) => $join->on('subject_staff.id', '=', 'correction.staff_id')->on('subject_staff.company_id', '=', 'correction.company_id'))
            ->leftJoin('users as subject_user', 'subject_user.id', '=', 'subject_staff.user_id')
            ->where('correction.company_id', $companyId)
            ->whereIn('correction.staff_id', $staffIds)
            ->select(['correction.id', 'subject_staff.code as staff_code', 'subject_user.first_name as staff_first_name', 'subject_user.last_name as staff_last_name', 'correction.work_date', 'correction.correction_type', 'correction.reason', 'correction.status', 'correction.requested_by'])
            ->when($data['status'] ?? null, fn($q, $v) => $q->where('correction.status', $v))->latest('correction.created_at');
        $rows = $query->paginate($request->integer('per_page', 50));
        $rows->getCollection()->transform(function ($row) use ($request) {
            $row->is_requester = (string) $row->requested_by === (string) $request->user()->id;
            unset($row->requested_by);

            return $row;
        });

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function approveCorrection(Request $request, string $correctionId, AttendanceResultService $service, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $data = $request->validate(['decision_note' => ['required', 'string', 'max:2000']]);
        $row = DB::table('hr_attendance_correction_requests')->where('id', $correctionId)
            ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
        abort_unless($row, 404);
        $result = $service->approveCorrection($correctionId, $request->user()->id, $data['decision_note']);
        $projection->attendanceCorrection(DB::table('hr_attendance_correction_requests')->find($correctionId), $request->user()->id);
        return response()->json(['status' => 'success', 'data' => $result]);
    }

    public function rejectCorrection(Request $request, string $correctionId, AttendanceResultService $service, HrDomainRequestProjectionService $projection): JsonResponse
    {
        $data = $request->validate(['decision_note' => ['required', 'string', 'max:2000']]);
        $row = DB::table('hr_attendance_correction_requests')->where('id', $correctionId)
            ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
        abort_unless($row, 404);
        $result = $service->rejectCorrection($correctionId, (string) $row->company_id, (string) $request->user()->id, $data['decision_note']);
        $projection->attendanceCorrection($result, $request->user()->id);
        return response()->json(['status' => 'success', 'data' => [
            'id' => $result->id,
            'status' => $result->status,
            'decided_at' => $result->decided_at,
        ]]);
    }

    public function exceptions(Request $request, StaffAccessService $access): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'staff_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['open', 'resolved', 'superseded'])], 'severity' => ['nullable', Rule::in(['low', 'medium', 'high'])]]);
        $companyId = $this->actorCompany($request, $data['company_id'] ?? null);
        $staffIds = $access->scope(Staff::query(), $request->user())->where('company_id', $companyId)->when($data['staff_id'] ?? null, fn($q, $id) => $q->whereKey($id))->select('id');
        $query = DB::table('hr_attendance_exceptions as exception')->join('hr_attendance_daily_results as result', fn($join) => $join->on('result.id', '=', 'exception.daily_result_id')->on('result.company_id', '=', 'exception.company_id')->on('result.staff_id', '=', 'exception.staff_id'))->join('staff', fn($join) => $join->on('staff.id', '=', 'exception.staff_id')->on('staff.company_id', '=', 'exception.company_id'))->join('users', 'users.id', '=', 'staff.user_id')->where('exception.company_id', $companyId)->whereIn('exception.staff_id', $staffIds)->select(['exception.id', 'staff.code as staff_code', 'users.first_name as staff_first_name', 'users.last_name as staff_last_name', 'result.work_date', 'exception.exception_type', 'exception.severity', 'exception.status'])->when($data['status'] ?? null, fn($q, $v) => $q->where('exception.status', $v))->when($data['severity'] ?? null, fn($q, $v) => $q->where('exception.severity', $v))->latest('result.work_date');
        return response()->json(['status' => 'success', 'data' => $query->paginate($request->integer('per_page', 50))]);
    }

    public function resolveException(Request $request, string $exceptionId): JsonResponse
    {
        $data = $request->validate(['resolution_note' => ['required', 'string', 'max:2000']]);
        return DB::transaction(function () use ($request, $exceptionId, $data) {
            $candidate = DB::table('hr_attendance_exceptions')->where('id', $exceptionId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $row = DB::table('hr_attendance_exceptions')->where('id', $exceptionId)
                ->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status === 'resolved' && (string) $row->resolved_by === (string) $request->user()->id
                && hash_equals((string) $row->resolution_note, $data['resolution_note'])) {
                return response()->json(['status' => 'success', 'data' => [
                    'id' => $exceptionId,
                    'status' => 'resolved',
                    'resolved_at' => CarbonImmutable::parse($row->resolved_at)->toISOString(),
                ]]);
            }
            abort_unless($row->status === 'open', 409, 'Only open exceptions may be resolved.');
            $staff = DB::table('staff')->where('id', $row->staff_id)->where('company_id', $row->company_id)->lockForUpdate()->first();
            $result = DB::table('hr_attendance_daily_results')->where('id', $row->daily_result_id)->where('company_id', $row->company_id)->where('staff_id', $row->staff_id)->first();
            abort_unless($staff && $result, 422, 'Exception Staff and result must match its legal entity.');
            $resolvedAt = now();
            DB::table('hr_attendance_exceptions')->where('id', $exceptionId)->update(['status' => 'resolved', 'resolved_at' => $resolvedAt, 'resolved_by' => $request->user()->id, 'resolution_note' => $data['resolution_note'], 'updated_at' => $resolvedAt]);
            activity('hr-attendance')->causedBy($request->user())
                ->withProperties(['company_id' => $row->company_id, 'status' => 'resolved', 'exception_type' => $row->exception_type])
                ->log('attendance_exception_resolved');
            return response()->json(['status' => 'success', 'data' => [
                'id' => $exceptionId,
                'status' => 'resolved',
                'resolved_at' => $resolvedAt->toISOString(),
            ]]);
        });
    }

    public function periods(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['open', 'reviewed', 'locked'])]]);
        $companyId = $this->actorCompany($request, $data['company_id'] ?? null);
        return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_periods')->where('company_id', $companyId)->when($data['status'] ?? null, fn($q, $v) => $q->where('status', $v))->orderByDesc('period_start')->get()]);
    }

    public function storePeriod(Request $request): JsonResponse
    {
        $this->writes();
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'timezone' => ['required', 'timezone']]);
        $data['company_id'] = $this->actorCompany($request, $data['company_id'] ?? null);
        return DB::transaction(function () use ($request, $data) {
            $this->sameCompany($request, $data['company_id']);
            $this->lockActiveCompany($data['company_id']);
            $overlap = DB::table('hr_attendance_periods')->where('company_id', $data['company_id'])->whereDate('period_start', '<=', $data['period_end'])->whereDate('period_end', '>=', $data['period_start'])->exists();
            abort_if($overlap, 409, 'Attendance periods cannot overlap.');
            $id = (string) Str::uuid();
            DB::table('hr_attendance_periods')->insert($data + ['id' => $id, 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            activity('hr-attendance')->causedBy($request->user())
                ->withProperties(['period_id' => $id, 'company_id' => $data['company_id'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'timezone' => $data['timezone']])
                ->log('attendance_period_created');
            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_periods')->find($id)], 201);
        });
    }

    public function transitionPeriod(Request $request, string $periodId): JsonResponse
    {
        $this->writes();
        $data = $request->validate(['action' => ['required', Rule::in(['review', 'lock', 'reopen'])], 'reason' => ['required', 'string', 'max:2000'], 'expected_version' => ['required', 'integer', 'min:1']]);
        return DB::transaction(function () use ($request, $periodId, $data) {
            $candidate = DB::table('hr_attendance_periods')->where('id', $periodId)
                ->whereIn('company_id', $this->authorizedCompanyIds($request))->first();
            abort_unless($candidate, 404);
            $this->lockActiveCompany($candidate->company_id);
            $row = DB::table('hr_attendance_periods')->where('id', $periodId)->where('company_id', $candidate->company_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->version === $data['expected_version'], 409, 'Attendance period version is stale.');
            $next = ['open' => ['review' => 'reviewed'], 'reviewed' => ['lock' => 'locked'], 'locked' => ['reopen' => 'open']][$row->status][$data['action']] ?? null;
            abort_unless($next, 409, 'Invalid attendance period transition.');
            if ($data['action'] === 'reopen') {
                abort_unless($request->user()->can('hr.attendance.periods.reopen'), 403, 'Reopening attendance periods requires separate authority.');
            } else {
                abort_unless($request->user()->can('hr.attendance.periods.manage'), 403, 'Attendance period management authority is required.');
            }
            if ($next === 'locked') {
                abort_unless($row->reviewed_by !== $request->user()->id, 409, 'The period reviewer cannot lock the same period.');
                $open = DB::table('hr_attendance_exceptions as exception')->join('hr_attendance_daily_results as result', fn($join) => $join->on('result.id', '=', 'exception.daily_result_id')->on('result.company_id', '=', 'exception.company_id')->on('result.staff_id', '=', 'exception.staff_id'))->where('exception.company_id', $row->company_id)->where('exception.status', 'open')->where('exception.severity', 'high')->whereBetween('result.work_date', [$row->period_start, $row->period_end])->exists();
                abort_if($open, 409, 'High-severity attendance exceptions must be resolved before lock.');
            }$version = $row->version + 1;
            DB::table('hr_attendance_periods')->where('id', $periodId)->update(['status' => $next, 'version' => $version, 'reviewed_at' => $next === 'reviewed' ? now() : $row->reviewed_at, 'reviewed_by' => $next === 'reviewed' ? $request->user()->id : $row->reviewed_by, 'locked_at' => $next === 'locked' ? now() : ($next === 'open' ? null : $row->locked_at), 'locked_by' => $next === 'locked' ? $request->user()->id : ($next === 'open' ? null : $row->locked_by), 'state_reason' => $data['reason'], 'updated_at' => now()]);
            DB::table('hr_attendance_period_events')->insert(['id' => (string) Str::uuid(), 'period_id' => $periodId, 'event_type' => $data['action'], 'from_status' => $row->status, 'to_status' => $next, 'reason' => $data['reason'], 'actor_user_id' => $request->user()->id, 'occurred_at' => now(), 'version' => $version, 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['status' => 'success', 'data' => DB::table('hr_attendance_periods')->find($periodId)]);
        });
    }

    private function sameCompany(Request $request, string $companyId): void
    {
        $this->authorizedCompanyId($request, $companyId);
    }
    private function lockActiveCompany(string $companyId): void
    {
        $company = DB::table('companies')->where('id', $companyId)->where('is_active', true)
            ->whereNull('deleted_at')->lockForUpdate()->first();
        abort_unless($company, 409, 'Attendance changes require an active legal entity.');
    }
    private function actorCompany(Request $request, ?string $requestedCompanyId): string
    {
        return $this->authorizedCompanyId($request, $requestedCompanyId);
    }
    private function writes(): void
    {
        abort_unless(config('hr.features.attendance_results', false), 409, 'Attendance result writes are not enabled.');
    }
    private function json(array $data, array $keys): array
    {
        foreach ($keys as $key)
            if (array_key_exists($key, $data))
                $data[$key] = $data[$key] === null ? null : json_encode($data[$key], JSON_THROW_ON_ERROR);
        return $data;
    }
}
