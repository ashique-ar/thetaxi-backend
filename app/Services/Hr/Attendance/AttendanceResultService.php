<?php

namespace App\Services\Hr\Attendance;

use App\Models\Hr\Attendance\AttendanceDailyResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceResultService
{
    public function calculate(string $companyId, string $staffId, string $workDate, ?string $actorUserId = null): AttendanceDailyResult
    {
        abort_unless(config('hr.features.attendance_results', false), 409, 'Attendance result writes are not enabled.');
        $date = CarbonImmutable::parse($workDate)->startOfDay();

        return DB::transaction(function () use ($companyId, $staffId, $date, $actorUserId) {
            $period = DB::table('hr_attendance_periods')->where('company_id', $companyId)->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date)->lockForUpdate()->first();
            abort_if($period && $period->status === 'locked', 409, 'The attendance period is locked.');
            $roster = DB::table('hr_roster_assignments')->where('company_id', $companyId)->where('staff_id', $staffId)->whereNotNull('approved_at')
                ->whereDate('effective_from', '<=', $date)->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $date))->latest('effective_from')->first();
            abort_unless($roster, 422, 'No approved roster applies to this Staff work date.');
            $calendar = DB::table('hr_work_calendars')->find($roster->calendar_id); $shift = DB::table('hr_shift_definitions')->find($roster->shift_id); $policy = DB::table('hr_attendance_policies')->find($roster->policy_id);
            abort_unless($calendar && $shift && $policy && $calendar->status === 'active' && $shift->status === 'active' && $policy->status === 'approved', 422, 'The roster configuration is not fully approved and active.');
            $timezone = $shift->timezone; $dayOverride = DB::table('hr_work_calendar_days')->where('calendar_id', $calendar->id)->whereDate('calendar_date', $date)->first();
            $weeklyDays = json_decode($calendar->weekly_working_days, true, 512, JSON_THROW_ON_ERROR); $isWorking = $dayOverride ? $dayOverride->day_type === 'working' : in_array(strtolower($date->format('l')), array_map('strtolower', $weeklyDays), true);
            $scheduledStart = CarbonImmutable::parse($date->toDateString().' '.$shift->start_time, $timezone); $scheduledEnd = CarbonImmutable::parse($date->toDateString().' '.$shift->end_time, $timezone)->addDay($shift->ends_next_day ? 1 : 0);
            $windowStart = $scheduledStart->subHours(6)->utc(); $windowEnd = $scheduledEnd->addHours(6)->utc();
            $events = DB::table('hr_attendance_raw_events as raw')->leftJoin('hr_attendance_quarantine_items as quarantine', 'quarantine.raw_event_id', '=', 'raw.id')
                ->where('raw.company_id', $companyId)->where(fn ($query) => $query->where('raw.staff_id', $staffId)->orWhere('quarantine.resolved_staff_id', $staffId))
                ->whereBetween('raw.occurred_at', [$windowStart, $windowEnd])->where('raw.event_kind', 'punch')->where(fn ($query) => $query->whereNull('raw.verification_result')->orWhereIn('raw.verification_result', ['success','verified','accepted']))
                ->select(['raw.id','raw.occurred_at','raw.direction','raw.payload_checksum'])->orderBy('raw.occurred_at')->get();
            $in = $events->first(fn ($event) => in_array($event->direction, ['in','unknown',null], true)); $out = $events->reverse()->first(fn ($event) => in_array($event->direction, ['out','unknown',null], true));
            $firstIn = $in ? CarbonImmutable::parse($in->occurred_at) : null; $lastOut = $out ? CarbonImmutable::parse($out->occurred_at) : null;
            if ($firstIn && $lastOut && $lastOut->lessThanOrEqualTo($firstIn)) $lastOut = null;
            $worked = $firstIn && $lastOut ? max(0, $firstIn->diffInMinutes($lastOut) - (int) $shift->unpaid_break_minutes) : 0;
            $late = $firstIn ? max(0, $scheduledStart->addMinutes((int) $shift->grace_in_minutes)->diffInMinutes($firstIn, false)) : 0;
            $early = $lastOut ? max(0, $lastOut->diffInMinutes($scheduledEnd->subMinutes((int) $shift->grace_out_minutes), false)) : 0;
            $status = ! $isWorking ? 'non_working' : (! $firstIn && ! $lastOut ? 'absent' : (! $firstIn || ! $lastOut ? 'incomplete' : ($worked >= (int) $shift->minimum_full_day_minutes ? 'present' : ($worked >= (int) $shift->minimum_half_day_minutes ? 'half_day' : 'insufficient_hours'))));
            $rules = json_decode($policy->rules, true, 512, JSON_THROW_ON_ERROR); $payable = $isWorking ? min($worked, (int) ($rules['maximum_payable_minutes'] ?? $shift->minimum_full_day_minutes)) : 0;
            $leave=DB::table('hr_leave_request_days as day')->join('hr_leave_requests as request','request.id','=','day.leave_request_id')->join('hr_leave_types as type','type.id','=','request.leave_type_id')->where('request.staff_id',$staffId)->where('request.status','approved')->whereDate('day.leave_date',$date)->select(['request.id','day.minutes','day.day_kind','type.code as leave_type_code','type.paid'])->first();
            if($leave){$leaveMinutes=min((int)$leave->minutes,(int)$shift->minimum_full_day_minutes);$payable=min((int)$shift->minimum_full_day_minutes,$payable+($leave->paid?$leaveMinutes:0));$status=$leaveMinutes>=$shift->minimum_full_day_minutes?($leave->paid?'paid_leave':'unpaid_leave'):($leave->paid?'partial_paid_leave':'partial_unpaid_leave');$late=0;$early=0;}
            $input = ['company_id'=>$companyId,'staff_id'=>$staffId,'date'=>$date->toDateString(),'roster'=>$roster->id,'calendar'=>$calendar->id,'shift'=>$shift->id,'policy'=>$policy->id,'leave'=>$leave,'events'=>$events->map(fn ($event) => [$event->id,$event->payload_checksum])->values()->all()];
            $inputChecksum = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $latest = AttendanceDailyResult::query()->where('staff_id', $staffId)->whereDate('work_date', $date)->latest('result_version')->lockForUpdate()->first();
            if ($latest && hash_equals($latest->input_checksum, $inputChecksum) && $latest->source_kind === 'calculated') return $latest;
            $resultData = ['day_status'=>$status,'scheduled_start_at'=>$scheduledStart->utc()->toIso8601String(),'scheduled_end_at'=>$scheduledEnd->utc()->toIso8601String(),'first_in_at'=>$firstIn?->toIso8601String(),'last_out_at'=>$lastOut?->toIso8601String(),'worked_minutes'=>$worked,'late_minutes'=>$late,'early_leave_minutes'=>$early,'payable_minutes'=>$payable];
            $result = AttendanceDailyResult::create(['company_id'=>$companyId,'staff_id'=>$staffId,'work_date'=>$date,'result_version'=>($latest?->result_version ?? 0)+1,'supersedes_id'=>$latest?->id,'roster_assignment_id'=>$roster->id,'period_id'=>$period?->id]+$resultData+['source_kind'=>'calculated','calculated_at'=>now(),'calculated_by'=>$actorUserId,'input_checksum'=>$inputChecksum,'result_checksum'=>hash('sha256',json_encode($resultData,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'rule_snapshot'=>['calendar'=>$calendar,'shift'=>$shift,'policy'=>$policy,'day_override'=>$dayOverride,'approved_leave'=>$leave]]);
            foreach ($events as $event) DB::table('hr_attendance_daily_result_sources')->insert(['id'=>(string)Str::uuid(),'daily_result_id'=>$result->id,'raw_event_id'=>$event->id,'role'=>$in && $event->id===$in->id?'first_in':($out && $event->id===$out->id?'last_out':'supporting'),'created_at'=>now(),'updated_at'=>now()]);
            foreach ($this->exceptions($status, $late, $early, $resultData) as $exception) DB::table('hr_attendance_exceptions')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,'staff_id'=>$staffId,'daily_result_id'=>$result->id]+$exception+['status'=>'open','evidence'=>json_encode($resultData,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
            return $result;
        });
    }

    public function approveCorrection(string $requestId, string $actorUserId, string $decisionNote): AttendanceDailyResult
    {
        abort_unless(config('hr.features.attendance_results', false), 409, 'Attendance result writes are not enabled.');
        return DB::transaction(function () use ($requestId, $actorUserId, $decisionNote) {
            $correction = DB::table('hr_attendance_correction_requests')->where('id', $requestId)->lockForUpdate()->first(); abort_unless($correction, 404);
            abort_if($correction->requested_by === $actorUserId, 409, 'The correction requester cannot approve the same correction.'); abort_unless($correction->status === 'pending_approval', 409, 'Only pending corrections may be approved.');
            $period = DB::table('hr_attendance_periods')->where('company_id',$correction->company_id)->whereDate('period_start','<=',$correction->work_date)->whereDate('period_end','>=',$correction->work_date)->lockForUpdate()->first(); abort_if($period && $period->status === 'locked',409,'The attendance period is locked.');
            $current = AttendanceDailyResult::query()->where('staff_id',$correction->staff_id)->whereDate('work_date',$correction->work_date)->latest('result_version')->lockForUpdate()->first(); abort_unless($current,422,'A calculated attendance result is required before correction.');
            $values = json_decode($correction->requested_values,true,512,JSON_THROW_ON_ERROR); $allowed = array_intersect_key($values,array_flip(['day_status','first_in_at','last_out_at','worked_minutes','late_minutes','early_leave_minutes','payable_minutes'])); abort_if($allowed===[],422,'The correction contains no supported result changes.');
            $resultData = array_merge($current->only(['day_status','scheduled_start_at','scheduled_end_at','first_in_at','last_out_at','worked_minutes','late_minutes','early_leave_minutes','payable_minutes']),$allowed);
            $result = AttendanceDailyResult::create(['company_id'=>$current->company_id,'staff_id'=>$current->staff_id,'work_date'=>$current->work_date,'result_version'=>$current->result_version+1,'supersedes_id'=>$current->id,'roster_assignment_id'=>$current->roster_assignment_id,'period_id'=>$period?->id]+$resultData+['source_kind'=>'approved_correction','calculated_at'=>now(),'calculated_by'=>$actorUserId,'input_checksum'=>hash('sha256',$current->input_checksum.$correction->id),'result_checksum'=>hash('sha256',json_encode($resultData,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'rule_snapshot'=>array_merge($current->rule_snapshot,['correction_request_id'=>$correction->id,'decision_note'=>$decisionNote])]);
            foreach(DB::table('hr_attendance_daily_result_sources')->where('daily_result_id',$current->id)->get() as $source) DB::table('hr_attendance_daily_result_sources')->insert(['id'=>(string)Str::uuid(),'daily_result_id'=>$result->id,'raw_event_id'=>$source->raw_event_id,'role'=>$source->role,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('hr_attendance_exceptions')->where('daily_result_id',$current->id)->where('status','open')->update(['status'=>'superseded','resolved_at'=>now(),'resolved_by'=>$actorUserId,'resolution_note'=>'Superseded by approved correction '.$correction->id,'updated_at'=>now()]);
            DB::table('hr_attendance_correction_requests')->where('id',$correction->id)->update(['status'=>'approved','decided_by'=>$actorUserId,'decided_at'=>now(),'decision_note'=>$decisionNote,'result_id'=>$result->id,'updated_at'=>now()]); return $result;
        });
    }

    private function exceptions(string $status, int $late, int $early, array $evidence): array
    {
        $items=[]; if(in_array($status,['absent','incomplete','insufficient_hours'],true))$items[]=['exception_type'=>$status,'severity'=>$status==='absent'?'high':'medium']; if($late>0)$items[]=['exception_type'=>'late_arrival','severity'=>'low']; if($early>0)$items[]=['exception_type'=>'early_departure','severity'=>'low']; return $items;
    }
}
