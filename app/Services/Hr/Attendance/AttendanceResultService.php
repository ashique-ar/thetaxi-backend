<?php

namespace App\Services\Hr\Attendance;

use App\Models\Hr\Attendance\AttendanceDailyResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceResultService
{
    public function calculate(string $companyId, string $staffId, string $workDate, ?string $actorUserId = null): AttendanceDailyResult
    {
        
        $date = CarbonImmutable::parse($workDate)->startOfDay();

        return DB::transaction(function () use ($companyId, $staffId, $date, $actorUserId) {
            $company = DB::table('companies')->where('id', $companyId)->where('is_active', true)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($company, 409, 'Attendance result writes require an active legal entity.');
            $period = DB::table('hr_attendance_periods')->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date)->lockForUpdate()->first();
            abort_if($period && $period->status === 'locked', 409, 'The attendance period is locked.');
            $staff = DB::table('staff')->where('id', $staffId)->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->lockForUpdate()->first();
            abort_unless($staff, 422, 'Staff does not belong to this legal entity.');
            $roster = DB::table('hr_roster_assignments')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->where('staff_id', $staffId)->whereNotNull('approved_at')
                ->whereDate('effective_from', '<=', $date)->where(fn($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $date))->latest('effective_from')->first();
            abort_unless($roster, 422, 'No approved roster applies to this Staff work date.');
            $calendar = DB::table('hr_work_calendars')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->find($roster->calendar_id);
            $shift = DB::table('hr_shift_definitions')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->find($roster->shift_id);
            $policy = DB::table('hr_attendance_policies')->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'company_id', $companyId))->find($roster->policy_id);
            abort_unless($calendar && $shift && $policy && $calendar->status === 'active' && $shift->status === 'active' && $policy->status === 'approved', 422, 'The roster configuration is not fully approved and active.');
            $timezone = $shift->timezone;
            $dayOverride = DB::table('hr_work_calendar_days')->where('calendar_id', $calendar->id)->whereDate('calendar_date', $date)->first();
            $weeklyDays = json_decode($calendar->weekly_working_days, true, 512, JSON_THROW_ON_ERROR);
            $isWorking = $dayOverride ? $dayOverride->day_type === 'working' : in_array(strtolower($date->format('l')), array_map('strtolower', $weeklyDays), true);
            $scheduledStart = CarbonImmutable::parse($date->toDateString() . ' ' . $shift->start_time, $timezone);
            $scheduledEnd = CarbonImmutable::parse($date->toDateString() . ' ' . $shift->end_time, $timezone)->addDay($shift->ends_next_day ? 1 : 0);
            $windowStart = $scheduledStart->subHours(6)->utc();
            $windowEnd = $scheduledEnd->addHours(6)->utc();
            $events = DB::table('hr_attendance_raw_events as raw')->leftJoin('hr_attendance_quarantine_items as quarantine', 'quarantine.raw_event_id', '=', 'raw.id')
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'raw.company_id', $companyId))->where(fn($query) => $query
                    ->where(fn($query) => $query->where('raw.staff_id', $staffId)->where('raw.mapping_status', 'mapped'))
                    ->orWhere(fn($query) => $query->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'quarantine.company_id', $companyId))->where('quarantine.status', 'resolved')->where('quarantine.resolved_staff_id', $staffId)))
                ->whereBetween('raw.occurred_at', [$windowStart, $windowEnd])->whereIn('raw.event_kind', ['punch', 'access_control'])->where(fn($query) => $query->whereNull('raw.verification_result')->orWhereIn('raw.verification_result', ['success', 'verified', 'accepted']))
                ->select(['raw.id', 'raw.occurred_at', 'raw.direction', 'raw.payload_checksum'])->orderBy('raw.occurred_at')->get();
            $in = $events->first(fn($event) => in_array($event->direction, ['in', 'unknown', null], true));
            $out = $events->reverse()->first(fn($event) => in_array($event->direction, ['out', 'unknown', null], true));
            $firstIn = $in ? CarbonImmutable::parse($in->occurred_at) : null;
            $lastOut = $out ? CarbonImmutable::parse($out->occurred_at) : null;
            if ($firstIn && $lastOut && $lastOut->lessThanOrEqualTo($firstIn))
                $lastOut = null;
            $worked = $firstIn && $lastOut ? max(0, (int) floor($firstIn->diffInSeconds($lastOut) / 60) - (int) $shift->unpaid_break_minutes) : 0;
            $late = $firstIn ? max(0, (int) floor($scheduledStart->addMinutes((int) $shift->grace_in_minutes)->diffInSeconds($firstIn, false) / 60)) : 0;
            $early = $lastOut ? max(0, (int) floor($lastOut->diffInSeconds($scheduledEnd->subMinutes((int) $shift->grace_out_minutes), false) / 60)) : 0;
            $status = !$isWorking ? 'non_working' : (!$firstIn && !$lastOut ? 'absent' : (!$firstIn || !$lastOut ? 'incomplete' : ($worked >= (int) $shift->minimum_full_day_minutes ? 'present' : ($worked >= (int) $shift->minimum_half_day_minutes ? 'half_day' : 'insufficient_hours'))));
            $rules = json_decode($policy->rules, true, 512, JSON_THROW_ON_ERROR);
            $payable = $isWorking ? min($worked, (int) ($rules['maximum_payable_minutes'] ?? $shift->minimum_full_day_minutes)) : 0;
            $leave = DB::table('hr_leave_request_days as day')
                ->join('hr_leave_requests as request', 'request.id', '=', 'day.leave_request_id')
                ->join('hr_leave_types as type', fn ($join) => $join->on('type.id', '=', 'request.leave_type_id')->where(fn ($sameCompany) => \App\Services\CompanyIdScope::sameCompany($sameCompany, 'type.company_id', 'request.company_id')))
                ->join('hr_leave_policies as leave_policy', fn ($join) => $join->on('leave_policy.id', '=', 'request.policy_id')->where(fn ($sameCompany) => \App\Services\CompanyIdScope::sameCompany($sameCompany, 'leave_policy.company_id', 'request.company_id')))
                ->where(fn ($_companyIdScope) => \App\Services\CompanyIdScope::constrain($_companyIdScope, 'request.company_id', $companyId))->where('request.staff_id', $staffId)
                ->where('request.status', 'approved')->whereDate('day.leave_date', $date)
                ->select(['request.id', 'day.minutes', 'day.day_kind', 'type.code as leave_type_code', 'type.paid'])->first();
            if ($leave) {
                $leaveMinutes = min((int) $leave->minutes, (int) $shift->minimum_full_day_minutes);
                $payable = min((int) $shift->minimum_full_day_minutes, $payable + ($leave->paid ? $leaveMinutes : 0));
                $status = $leaveMinutes >= $shift->minimum_full_day_minutes ? ($leave->paid ? 'paid_leave' : 'unpaid_leave') : ($leave->paid ? 'partial_paid_leave' : 'partial_unpaid_leave');
                $late = 0;
                $early = 0;
            }
            // Configuration values belong to the calculation input. Using only
            // record IDs made an edited shift look unchanged and returned the
            // previous result version.
            $input = ['company_id' => $companyId, 'staff_id' => $staffId, 'date' => $date->toDateString(), 'roster' => (array) $roster, 'calendar' => (array) $calendar, 'shift' => (array) $shift, 'policy' => (array) $policy, 'day_override' => $dayOverride ? (array) $dayOverride : null, 'leave' => $leave, 'events' => $events->map(fn($event) => [$event->id, $event->payload_checksum])->values()->all()];
            $inputChecksum = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $latest = AttendanceDailyResult::query()->where('staff_id', $staffId)->whereDate('work_date', $date)->latest('result_version')->lockForUpdate()->first();
            abort_if($latest && !\App\Services\CompanyIdScope::sameCompanyId((string) ((string) $latest->company_id), (string) ((string) $companyId)), 409, 'The latest attendance result does not belong to the selected legal entity.');
            if ($latest && $latest->source_kind === 'calculated' && hash_equals($latest->input_checksum, $inputChecksum))
                return $latest;
            if ($latest && $latest->source_kind === 'manual_correction'
                && hash_equals((string) ($latest->rule_snapshot['approved_correction']['source_input_checksum'] ?? ''), $inputChecksum))
                return $latest;
            $resultData = ['day_status' => $status, 'scheduled_start_at' => $scheduledStart->utc()->toIso8601String(), 'scheduled_end_at' => $scheduledEnd->utc()->toIso8601String(), 'first_in_at' => $firstIn?->toIso8601String(), 'last_out_at' => $lastOut?->toIso8601String(), 'worked_minutes' => $worked, 'late_minutes' => $late, 'early_leave_minutes' => $early, 'payable_minutes' => $payable];
            $result = AttendanceDailyResult::create(['company_id' => $companyId, 'staff_id' => $staffId, 'work_date' => $date, 'result_version' => ($latest?->result_version ?? 0) + 1, 'supersedes_id' => $latest?->id, 'roster_assignment_id' => $roster->id, 'period_id' => $period?->id] + $resultData + ['source_kind' => 'calculated', 'calculated_at' => now(), 'calculated_by' => $actorUserId, 'input_checksum' => $inputChecksum, 'result_checksum' => hash('sha256', json_encode($resultData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'rule_snapshot' => ['calendar' => $calendar, 'shift' => $shift, 'policy' => $policy, 'day_override' => $dayOverride, 'approved_leave' => $leave]]);
            if ($latest) {
                DB::table('hr_attendance_exceptions')->where('daily_result_id', $latest->id)->where('status', 'open')->update(['status' => 'superseded', 'resolved_at' => now(), 'resolved_by' => $actorUserId, 'resolution_note' => 'Superseded by recalculated attendance result ' . $result->id, 'updated_at' => now()]);
            }
            foreach ($events as $event)
                DB::table('hr_attendance_daily_result_sources')->insert(['id' => (string) Str::uuid(), 'daily_result_id' => $result->id, 'raw_event_id' => $event->id, 'role' => $in && $event->id === $in->id ? 'first_in' : ($out && $event->id === $out->id ? 'last_out' : 'supporting'), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($this->exceptions($status, $late, $early, $resultData) as $exception)
                DB::table('hr_attendance_exceptions')->insert(['id' => (string) Str::uuid(), 'company_id' => $companyId, 'staff_id' => $staffId, 'daily_result_id' => $result->id] + $exception + ['status' => 'open', 'evidence' => json_encode($resultData, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            return $result;
        });
    }

    public function approveCorrection(string $requestId, string $actorUserId, string $decisionNote): AttendanceDailyResult
    {
        return DB::transaction(function () use ($requestId, $actorUserId, $decisionNote) {
            $candidate = DB::table('hr_attendance_correction_requests')->where('id', $requestId)->first();
            abort_unless($candidate, 404);
            $correction = DB::table('hr_attendance_correction_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($correction, 404);
            abort_if((string) $correction->requested_by === $actorUserId, 409, 'The correction requester cannot decide the same correction.');
            if ($correction->status === 'approved' && (string) $correction->decided_by === $actorUserId && hash_equals((string) $correction->decision_note, $decisionNote)) {
                return AttendanceDailyResult::query()->whereKey($correction->result_id)->firstOrFail();
            }
            abort_unless($correction->status === 'pending_approval', 409, 'Only pending corrections may be approved.');
            abort_unless($correction->mapping_policy_id && $correction->mapping_snapshot, 409, 'This legacy correction has no approved mapping evidence and cannot be applied.');
            $mappingSnapshot = json_decode($correction->mapping_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $policy = DB::table('hr_attendance_policies')->where('id', $correction->mapping_policy_id)->where('status', 'approved')->lockForUpdate()->first();
            abort_unless($policy && (string) $policy->approved_at === (string) ($mappingSnapshot['policy_approved_at'] ?? ''), 409, 'The approved mapping policy evidence is no longer valid.');
            $policyRules = is_array($policy->rules) ? $policy->rules : json_decode($policy->rules, true, 512, JSON_THROW_ON_ERROR);
            $policyRulesChecksum = hash('sha256', json_encode($policyRules, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            abort_unless(hash_equals((string) ($mappingSnapshot['policy_rules_checksum'] ?? ''), $policyRulesChecksum), 409, 'The approved mapping policy rules changed after this request was submitted.');
            $mapping = collect($policyRules['correction_mappings'] ?? [])->first(fn ($item) => ($item['type'] ?? null) === $correction->correction_type && ($item['field'] ?? null) === ($mappingSnapshot['field'] ?? null));
            abort_unless($mapping && $policy->id === $mappingSnapshot['policy_id'], 409, 'The request no longer matches its approved correction mapping.');
            abort_unless(in_array($mapping['field'], ['day_status', 'first_in_at', 'last_out_at', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'payable_minutes'], true), 409, 'The approved correction mapping contains an unsupported result field.');
            $period = DB::table('hr_attendance_periods')->whereDate('period_start', '<=', $correction->work_date)->whereDate('period_end', '>=', $correction->work_date)->lockForUpdate()->first();
            abort_if($period?->status === 'locked', 409, 'The attendance period is locked.');
            $current = AttendanceDailyResult::query()->where('id', $correction->current_result_id)->where('staff_id', $correction->staff_id)->whereDate('work_date', $correction->work_date)->lockForUpdate()->first();
            abort_unless($current, 409, 'The attendance result changed or is no longer available.');
            $latest = AttendanceDailyResult::query()->where('staff_id', $correction->staff_id)->whereDate('work_date', $correction->work_date)->orderByDesc('result_version')->lockForUpdate()->first();
            abort_unless($latest && (string) $latest->id === (string) $current->id, 409, 'A newer attendance result exists; submit a new correction against the current result.');
            $values = json_decode($correction->requested_values, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(array_keys($values) === [$mapping['field']], 409, 'Stored correction values do not match the approved mapping.');
            $field = $mapping['field'];
            $value = $values[$field];
            if (in_array($field, ['first_in_at', 'last_out_at'], true)) {
                $value = $value === null ? null : CarbonImmutable::parse($value)->utc();
            }
            $resultData = [
                'day_status' => $current->day_status,
                'scheduled_start_at' => $current->scheduled_start_at,
                'scheduled_end_at' => $current->scheduled_end_at,
                'first_in_at' => $current->first_in_at,
                'last_out_at' => $current->last_out_at,
                'worked_minutes' => (int) $current->worked_minutes,
                'late_minutes' => (int) $current->late_minutes,
                'early_leave_minutes' => (int) $current->early_leave_minutes,
                'payable_minutes' => (int) $current->payable_minutes,
            ];
            $resultData[$field] = $value;
            abort_if($resultData['first_in_at'] && $resultData['last_out_at'] && CarbonImmutable::parse($resultData['last_out_at'])->lessThanOrEqualTo(CarbonImmutable::parse($resultData['first_in_at'])), 422, 'Last-out time must be later than first-in time.');
            $checksum = hash('sha256', json_encode([$current->input_checksum, $correction->request_checksum, $mappingSnapshot], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $snapshot = $current->rule_snapshot;
            $sourceInputChecksum = $snapshot['approved_correction']['source_input_checksum'] ?? $current->input_checksum;
            $snapshot['approved_correction'] = ['request_id' => $correction->id, 'request_checksum' => $correction->request_checksum, 'source_input_checksum' => $sourceInputChecksum, 'mapping' => $mappingSnapshot, 'approved_by' => $actorUserId, 'decision_note' => $decisionNote];
            $result = AttendanceDailyResult::query()->create([
                'company_id' => $current->company_id, 'staff_id' => $current->staff_id, 'work_date' => $current->work_date,
                'result_version' => $current->result_version + 1, 'supersedes_id' => $current->id,
                'roster_assignment_id' => $current->roster_assignment_id, 'period_id' => $current->period_id,
            ] + $resultData + [
                'source_kind' => 'manual_correction', 'calculated_at' => now(), 'calculated_by' => $actorUserId,
                'input_checksum' => $checksum, 'result_checksum' => hash('sha256', json_encode($resultData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'rule_snapshot' => $snapshot,
            ]);
            foreach (DB::table('hr_attendance_daily_result_sources')->where('daily_result_id', $current->id)->get() as $source) {
                DB::table('hr_attendance_daily_result_sources')->insert(['id' => (string) Str::uuid(), 'daily_result_id' => $result->id, 'raw_event_id' => $source->raw_event_id, 'role' => $source->role, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('hr_attendance_exceptions')->where('daily_result_id', $current->id)->where('status', 'open')->update(['status' => 'superseded', 'resolved_at' => now(), 'resolved_by' => $actorUserId, 'resolution_note' => 'Superseded by approved attendance correction '.$correction->id, 'updated_at' => now()]);
            foreach ($this->exceptions($result->day_status, (int) $result->late_minutes, (int) $result->early_leave_minutes, $resultData) as $exception) {
                DB::table('hr_attendance_exceptions')->insert(['id' => (string) Str::uuid(), 'company_id' => $result->company_id, 'staff_id' => $result->staff_id, 'daily_result_id' => $result->id] + $exception + ['status' => 'open', 'evidence' => json_encode($resultData, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('hr_attendance_correction_requests')->where('id', $correction->id)->update(['status' => 'approved', 'decided_by' => $actorUserId, 'decided_at' => now(), 'decision_note' => $decisionNote, 'result_id' => $result->id, 'updated_at' => now()]);
            activity('hr-attendance')->causedBy(User::query()->findOrFail($actorUserId))->withProperties(['correction_id' => $correction->id, 'staff_id' => $correction->staff_id, 'result_id' => $result->id, 'supersedes_id' => $current->id, 'field' => $field, 'mapping_policy_id' => $policy->id])->log('attendance_correction_approved');
            return $result;
        });
    }

    /** Reject a pending request without changing attendance or payable facts. */
    public function rejectCorrection(string $requestId, string $actorUserId, string $decisionNote): object
    {
        return DB::transaction(function () use ($requestId, $actorUserId, $decisionNote) {
            $correction = DB::table('hr_attendance_correction_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($correction, 404);
            abort_if($correction->requested_by === $actorUserId, 409, 'The correction requester cannot decide the same correction.');
            if ($correction->status === 'rejected'
                && $correction->decided_by === $actorUserId
                && hash_equals((string) $correction->decision_note, $decisionNote)) {
                return $correction;
            }
            abort_unless($correction->status === 'pending_approval', 409, 'Only pending corrections may be rejected.');
            DB::table('hr_attendance_correction_requests')->where('id', $correction->id)->update([
                'status' => 'rejected',
                'decided_by' => $actorUserId,
                'decided_at' => now(),
                'decision_note' => $decisionNote,
                'updated_at' => now(),
            ]);
            activity('hr-attendance')->causedBy(User::query()->findOrFail($actorUserId))
                ->withProperties(['correction_id' => $correction->id, 'status' => 'rejected'])
                ->log('attendance_correction_rejected');
            return DB::table('hr_attendance_correction_requests')->find($correction->id);
        });
    }

    private function exceptions(string $status, int $late, int $early, array $evidence): array
    {
        $items = [];
        if (in_array($status, ['absent', 'incomplete', 'insufficient_hours'], true))
            $items[] = ['exception_type' => $status, 'severity' => $status === 'absent' ? 'high' : 'medium'];
        if ($late > 0)
            $items[] = ['exception_type' => 'late_arrival', 'severity' => 'low'];
        if ($early > 0)
            $items[] = ['exception_type' => 'early_departure', 'severity' => 'low'];
        return $items;
    }
}
