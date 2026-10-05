<?php

namespace App\Services\Hr\Workforce;

use App\Models\Hr\PayrollInputFact;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkforceWorkflowService
{
    public function submitWorkRequest(array $data, string $actor): object
    {
        $this->enabled();
        $checksum = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($existing = $this->existingWorkRequest($data['idempotency_key'], $checksum, $actor)) return $existing;

        $starts = CarbonImmutable::parse($data['starts_at']);
        $ends = CarbonImmutable::parse($data['ends_at']);
        abort_unless($ends->gt($starts), 422, 'Work request end must be after start.');
        $minutes = $starts->diffInMinutes($ends);

        try {
            return DB::transaction(function () use ($data, $actor, $checksum, $minutes, $starts, $ends) {
                $staff = DB::table('staff')->where('id', $data['staff_id'])->lockForUpdate()->first();
                abort_unless($staff && $staff->company_id === $data['company_id'] && $staff->employment_ended_at === null && $staff->deleted_at === null, 422, 'Staff is no longer active in this legal entity.');
                if ($existing = DB::table('hr_work_requests')->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first()) {
                    return $this->matchingWorkRequest($existing, $checksum, $actor);
                }

                $policy = DB::table('hr_work_request_policies')->where('id', $data['policy_id'])->where('company_id', $data['company_id'])->where('request_kind', $data['request_kind'])->where('status', 'approved')->whereDate('effective_from', '<=', $starts)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $ends))->first();
                abort_unless($policy, 422, 'No approved work-request policy covers this interval.');
                $rules = json_decode($policy->rules, true, 512, JSON_THROW_ON_ERROR);
                abort_if($minutes > (int) ($rules['maximum_request_minutes'] ?? 10080), 422, 'Requested duration exceeds the policy cap.');
                $overlap = DB::table('hr_work_requests')->where('company_id', $data['company_id'])->where('staff_id', $data['staff_id'])->whereIn('status', ['pending_approval', 'approved'])->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->exists();
                abort_if($overlap, 409, 'An active work request overlaps this interval.');

                $id = (string) Str::uuid();
                $snapshot = ['policy_id' => $policy->id, 'policy_version' => $policy->version, 'rules' => $rules, 'requested' => $data];
                DB::table('hr_work_requests')->insert([
                    'id' => $id, 'company_id' => $data['company_id'], 'staff_id' => $data['staff_id'], 'policy_id' => $policy->id,
                    'request_kind' => $data['request_kind'], 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
                    'requested_minutes' => $minutes, 'rate_category' => $data['rate_category'] ?? ($rules['default_rate_category'] ?? null),
                    'settlement_kind' => $data['settlement_kind'] ?? ($rules['default_settlement_kind'] ?? null), 'status' => 'pending_approval',
                    'reason' => $data['reason'], 'request_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'request_checksum' => $checksum, 'idempotency_key' => $data['idempotency_key'], 'requested_by' => $actor,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->event($id, 'submitted', null, 'pending_approval', $data['reason'], $snapshot, $actor);
                return DB::table('hr_work_requests')->find($id);
            });
        } catch (QueryException $e) {
            if (! in_array($e->errorInfo[0] ?? null, ['23000', '23505'], true)) throw $e;
            if ($existing = $this->existingWorkRequest($data['idempotency_key'], $checksum, $actor)) return $existing;
            throw $e;
        }
    }

    public function decideWorkRequest(string$id,string$action,string$note,string$actor):object
    {
        $this->enabled();
        return DB::transaction(function () use ($id, $action, $note, $actor) {
            $row = DB::table('hr_work_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->assertWorkRequestCompany($row);
            abort_unless(in_array($action, ['approve', 'reject'], true), 422);
            if ($this->exactDecisionReplay($row, $action, $note, $actor)) return $row;
            abort_if($row->requested_by === $actor, 409, 'The requester cannot decide the same work request.');
            abort_unless($row->status === 'pending_approval', 409, 'Only pending work requests may be decided.');
            $locked = DB::table('hr_attendance_periods')->where('company_id', $row->company_id)->where('status', 'locked')
                ->whereDate('period_start', '<=', CarbonImmutable::parse($row->ends_at))
                ->whereDate('period_end', '>=', CarbonImmutable::parse($row->starts_at))->exists();
            abort_if($locked && $action === 'approve', 409, 'Reopen the overlapping locked attendance period before approving this work request.');
            $next = $action === 'approve' ? 'approved' : 'rejected';
            $snapshot = json_decode($row->request_snapshot, true, 512, JSON_THROW_ON_ERROR);
            if ($next === 'approved' && $row->request_kind === 'overtime') {
                $attendance = $this->attendanceMinutes($row->company_id, $row->staff_id,
                    CarbonImmutable::parse($row->starts_at)->toDateString(), CarbonImmutable::parse($row->ends_at)->toDateString());
                $snapshot['attendance_reconciliation'] = [
                    'worked_minutes' => $attendance, 'requested_overtime_minutes' => $row->requested_minutes,
                    'not_assumed_equal' => true,
                ];
                if ($row->settlement_kind === 'pay') $this->fact($row, 'approved_overtime', $row->requested_minutes, $snapshot, $actor);
                if ($row->settlement_kind === 'time_off') $this->creditTimeOff($row, $snapshot, $actor);
            }
            $this->event($id, $action, $row->status, $next, $note, $snapshot, $actor);
            DB::table('hr_work_requests')->where('id', $id)->update([
                'status' => $next, 'request_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'decided_by' => $actor, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now(),
            ]);
            return DB::table('hr_work_requests')->find($id);
        });
    }

    public function saveTimesheet(array$data,string$actor):object
    {
        $this->enabled();return DB::transaction(function()use($data,$actor){$staff=DB::table('staff')->where('id',$data['staff_id'])->lockForUpdate()->first();abort_unless($staff&&(string)$staff->company_id===(string)$data['company_id'],422,'Staff and timesheet legal entities must match.');$sheet=DB::table('hr_timesheets')->where('staff_id',$data['staff_id'])->where('period_start',$data['period_start'])->where('period_end',$data['period_end'])->lockForUpdate()->first();if(!$sheet){$id=(string)Str::uuid();DB::table('hr_timesheets')->insert(['id'=>$id,'company_id'=>$data['company_id'],'staff_id'=>$data['staff_id'],'period_start'=>$data['period_start'],'period_end'=>$data['period_end'],'status'=>'draft','version'=>1,'created_at'=>now(),'updated_at'=>now()]);$sheet=DB::table('hr_timesheets')->find($id);}abort_unless((string)$sheet->company_id===(string)$data['company_id'],409,'The existing timesheet belongs to another legal entity.');abort_unless(in_array($sheet->status,['draft','returned'],true),409,'Only draft or returned timesheets may be edited.');DB::table('hr_timesheet_entries')->where('timesheet_id',$sheet->id)->delete();$daily=[];foreach($data['entries']as$entry){abort_unless($entry['work_date']>=$sheet->period_start&&$entry['work_date']<=$sheet->period_end,422,'Timesheet entry is outside its period.');if(!empty($entry['booking_id']))abort_unless(DB::table('sales_booking_attributions')->where('booking_id',$entry['booking_id'])->where('company_id',$data['company_id'])->exists(),422,'Timesheet booking must have a frozen attribution to the same legal entity.');if(($entry['entry_mode']??'manual')==='timer'){abort_unless(!empty($entry['started_at'])&&!empty($entry['ended_at']),422,'Timer entries require start and end.');$started=CarbonImmutable::parse($entry['started_at']);$ended=CarbonImmutable::parse($entry['ended_at']);abort_unless($ended->gt($started),422,'Timer end must be after start.');$entry['minutes']=$started->diffInMinutes($ended);}$daily[$entry['work_date']]=($daily[$entry['work_date']]??0)+(int)$entry['minutes'];abort_if($daily[$entry['work_date']]>1440,422,'Timesheet entries exceed 24 hours for a work date.');abort_if((int)$entry['minutes']<=0,422,'Timesheet minutes must be positive.');$payload=array_intersect_key($entry,array_flip(['work_date','started_at','ended_at','minutes','entry_mode','cost_centre_code','project_code','booking_id','job_reference','activity_code','billable','notes']));DB::table('hr_timesheet_entries')->insert($payload+['id'=>(string)Str::uuid(),'timesheet_id'=>$sheet->id,'entry_checksum'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'created_at'=>now(),'updated_at'=>now()]);}DB::table('hr_timesheets')->where('id',$sheet->id)->update(['updated_at'=>now()]);return DB::table('hr_timesheets')->find($sheet->id);});
    }

    public function transitionTimesheet(string$id,string$action,string$reason,string$actor):object
    {
        $this->enabled();return DB::transaction(function()use($id,$action,$reason,$actor){$candidate=DB::table('hr_timesheets')->where('id',$id)->first();abort_unless($candidate,404);$staff=DB::table('staff')->where('id',$candidate->staff_id)->lockForUpdate()->first();$sheet=DB::table('hr_timesheets')->where('id',$id)->lockForUpdate()->first();abort_unless($sheet,404);abort_unless($staff&&(string)$staff->company_id===(string)$sheet->company_id,409,'Staff and timesheet legal entities must match.');$map=['draft'=>['submit'=>'submitted'],'returned'=>['submit'=>'submitted'],'submitted'=>['approve'=>'approved','return'=>'returned'],'approved'=>['lock'=>'locked'],'locked'=>['reopen'=>'returned']];$next=$map[$sheet->status][$action]??null;abort_unless($next,409,'Invalid timesheet transition.');if(in_array($action,['approve','lock'],true))abort_if($sheet->submitted_by===$actor,409,'The submitter cannot approve or lock the same timesheet.');if($action==='lock')abort_unless(DB::table('hr_attendance_periods')->where('company_id',$sheet->company_id)->where('status','locked')->whereDate('period_start','<=',$sheet->period_start)->whereDate('period_end','>=',$sheet->period_end)->exists(),409,'A covering attendance period must be locked before the timesheet can be locked.');$entries=DB::table('hr_timesheet_entries')->where('timesheet_id',$id)->orderBy('work_date')->orderBy('id')->get();abort_if($action==='submit'&&$entries->isEmpty(),422,'An empty timesheet cannot be submitted.');$checksum=hash('sha256',json_encode($entries,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$reconciliation=null;if($action==='submit'){$attendance=$this->attendanceMinutes($sheet->company_id,$sheet->staff_id,$sheet->period_start,$sheet->period_end);$reconciliation=['timesheet_minutes'=>(int)$entries->sum('minutes'),'attendance_worked_minutes'=>$attendance,'variance_minutes'=>(int)$entries->sum('minutes')-(int)$attendance,'not_assumed_equal'=>true];}$version=$sheet->version+1;DB::table('hr_timesheets')->where('id',$id)->update(['status'=>$next,'version'=>$version,'content_checksum'=>$checksum,'reconciliation_snapshot'=>$reconciliation?json_encode($reconciliation,JSON_THROW_ON_ERROR):$sheet->reconciliation_snapshot,'submitted_by'=>$action==='submit'?$actor:$sheet->submitted_by,'submitted_at'=>$action==='submit'?now():$sheet->submitted_at,'decided_by'=>in_array($action,['approve','return','lock','reopen'],true)?$actor:$sheet->decided_by,'decided_at'=>in_array($action,['approve','return','lock','reopen'],true)?now():$sheet->decided_at,'decision_note'=>$reason,'updated_at'=>now()]);DB::table('hr_timesheet_events')->insert(['id'=>(string)Str::uuid(),'timesheet_id'=>$id,'event_type'=>$action,'from_status'=>$sheet->status,'to_status'=>$next,'version'=>$version,'reason'=>$reason,'content_checksum'=>$checksum,'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);return DB::table('hr_timesheets')->find($id);});
    }

    private function attendanceMinutes(string $companyId, string $staffId, string $from, string $to): int
    {
        $latest = DB::table('hr_attendance_daily_results')
            ->select('company_id', 'staff_id', 'work_date')
            ->selectRaw('MAX(result_version) as result_version')
            ->where('company_id', $companyId)->where('staff_id', $staffId)->whereBetween('work_date', [$from, $to])
            ->groupBy('company_id', 'staff_id', 'work_date');

        return (int) DB::table('hr_attendance_daily_results as result')->joinSub($latest, 'latest', fn ($join) => $join
            ->on('latest.company_id', '=', 'result.company_id')->on('latest.staff_id', '=', 'result.staff_id')
            ->on('latest.work_date', '=', 'result.work_date')->on('latest.result_version', '=', 'result.result_version'))
            ->sum('result.worked_minutes');
    }
    private function creditTimeOff(object$row,array$snapshot,string$actor):void{$this->assertWorkRequestCompany($row);$type=$snapshot['rules']['time_off_leave_type_id']??null;abort_unless($type,422,'Time-off settlement requires a configured leave type.');abort_unless(DB::table('hr_leave_types')->where('id',$type)->where('company_id',$row->company_id)->exists(),422,'Time-off leave type belongs to another legal entity.');$account=DB::table('hr_leave_balance_accounts')->where('staff_id',$row->staff_id)->where('leave_type_id',$type)->lockForUpdate()->first();abort_unless($account,422,'Time-off balance account is not configured.');abort_unless($account->company_id===$row->company_id,409,'Time-off balance account belongs to another legal entity.');$payload=['work_request_id'=>$row->id,'minutes'=>$row->requested_minutes,'rules'=>$snapshot];DB::table('hr_leave_balance_entries')->insert(['id'=>(string)Str::uuid(),'account_id'=>$account->id,'leave_request_id'=>null,'entry_type'=>'time_off_credit','minutes'=>$row->requested_minutes,'effective_date'=>CarbonImmutable::parse($row->ends_at)->toDateString(),'source_type'=>'work_request','source_id'=>$row->id,'reason'=>'Approved overtime converted to time off','rule_snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'entry_checksum'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'posted_by'=>$actor,'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function fact(object$row,string$kind,int$minutes,array$snapshot,string$actor):void{$this->assertWorkRequestCompany($row);abort_unless(DB::table('hr_work_requests')->where('id',$row->id)->where('company_id',$row->company_id)->where('staff_id',$row->staff_id)->exists(),422,'Payroll fact source does not match its work request.');$payload=['row'=>$row,'kind'=>$kind,'minutes'=>$minutes,'snapshot'=>$snapshot];PayrollInputFact::create(['company_id'=>$row->company_id,'staff_id'=>$row->staff_id,'fact_kind'=>$kind,'effective_date'=>CarbonImmutable::parse($row->ends_at)->toDateString(),'quantity_minutes'=>$minutes,'rate_category'=>$row->rate_category,'source_type'=>'work_request','source_id'=>$row->id,'status'=>'staged','source_snapshot'=>$snapshot,'fact_checksum'=>hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'created_by'=>$actor]);}
    private function assertWorkRequestCompany(object$row):void{$staff=DB::table('staff')->where('id',$row->staff_id)->lockForUpdate()->first();abort_unless($staff&&$staff->company_id===$row->company_id,422,'Work request Staff does not belong to its legal entity.');$policy=DB::table('hr_work_request_policies')->where('id',$row->policy_id)->lockForUpdate()->first();abort_unless($policy&&$policy->company_id===$row->company_id&&$policy->request_kind===$row->request_kind,422,'Work request policy does not match its legal entity and request kind.');}
    private function existingWorkRequest(string $key, string $checksum, string $actor): ?object
    {
        $existing = DB::table('hr_work_requests')->where('idempotency_key', $key)->first();
        return $existing ? $this->matchingWorkRequest($existing, $checksum, $actor) : null;
    }

    private function matchingWorkRequest(object $existing, string $checksum, string $actor): object
    {
        abort_unless($existing->requested_by === $actor && hash_equals((string) $existing->request_checksum, $checksum), 409, 'Work-request idempotency key was reused with different evidence or actor.');
        return $existing;
    }

    private function exactDecisionReplay(object $row, string $action, string $note, string $actor): bool
    {
        $event = DB::table('hr_work_request_events')->where('work_request_id', $row->id)->where('event_type', $action)->first();
        if (! $event) return false;
        $status = $action === 'approve' ? 'approved' : 'rejected';
        abort_unless($row->status === $status && $row->decided_by === $actor && $row->decision_note === $note
            && $event->to_status === $status && $event->actor_user_id === $actor && $event->reason === $note,
            409, 'Work-request retry does not match the original action, note and actor.');
        if ($status === 'approved' && $row->request_kind === 'overtime' && $row->settlement_kind === 'pay') {
            $fact = DB::table('hr_payroll_input_facts')->where('company_id', $row->company_id)->where('staff_id', $row->staff_id)
                ->where('source_type', 'work_request')->where('source_id', $row->id)->where('fact_kind', 'approved_overtime')->first();
            abort_unless($fact && (int) $fact->quantity_minutes === (int) $row->requested_minutes
                && $fact->effective_date === CarbonImmutable::parse($row->ends_at)->toDateString()
                && $fact->rate_category === $row->rate_category && $fact->status === 'staged',
                409, 'The approved overtime payroll fact no longer matches its work request.');
        }
        if ($status === 'approved' && $row->request_kind === 'overtime' && $row->settlement_kind === 'time_off') {
            $snapshot = json_decode($row->request_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $leaveTypeId = $snapshot['rules']['time_off_leave_type_id'] ?? null;
            abort_unless($leaveTypeId, 409, 'The approved time-off leave type is missing.');
            $credit = DB::table('hr_leave_balance_entries as entry')
                ->join('hr_leave_balance_accounts as account', 'account.id', '=', 'entry.account_id')
                ->join('hr_leave_types as type', 'type.id', '=', 'account.leave_type_id')
                ->where('entry.source_type', 'work_request')->where('entry.source_id', $row->id)
                ->where('entry.entry_type', 'time_off_credit')->where('account.company_id', $row->company_id)
                ->where('account.staff_id', $row->staff_id)->where('account.leave_type_id', $leaveTypeId)
                ->where('type.company_id', $row->company_id)
                ->first(['entry.minutes', 'entry.effective_date', 'entry.rule_snapshot', 'entry.entry_checksum']);
            $payload = ['work_request_id' => $row->id, 'minutes' => (int) $row->requested_minutes, 'rules' => $snapshot];
            $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            abort_unless($credit && (int) $credit->minutes === (int) $row->requested_minutes
                && $credit->effective_date === CarbonImmutable::parse($row->ends_at)->toDateString()
                && json_decode($credit->rule_snapshot, true, 512, JSON_THROW_ON_ERROR) == $snapshot
                && hash_equals($checksum, (string) $credit->entry_checksum),
                409, 'The approved time-off credit no longer matches its work request.');
        }
        return true;
    }

    private function event(string$id,string$type,?string$from,string$to,string$reason,array$snapshot,string$actor):void{DB::table('hr_work_request_events')->insert(['id'=>(string)Str::uuid(),'work_request_id'=>$id,'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'reason'=>$reason,'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function enabled():void{abort_unless(config('hr.features.leave_overtime',false),409,'Leave, overtime, and timesheet writes are not enabled.');}
}
