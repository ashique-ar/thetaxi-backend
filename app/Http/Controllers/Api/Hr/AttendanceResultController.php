<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\Attendance\AttendanceDailyResult;
use App\Models\Staff;
use App\Services\Hr\Attendance\AttendanceResultService;
use App\Services\StaffAccessService;
use App\Services\Hr\Ess\HrDomainRequestProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttendanceResultController extends Controller
{
    public function index(Request $request, StaffAccessService $access): JsonResponse
    {
        $data=$request->validate(['staff_id'=>['nullable','uuid'],'from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'status'=>['nullable','string','max:40']]);
        $staffIds=$access->scope(Staff::query(),$request->user())->when($data['staff_id']??null,fn($q,$id)=>$q->whereKey($id))->select('id');
        $latest=DB::table('hr_attendance_daily_results')->selectRaw('staff_id, work_date, max(result_version) as result_version')->whereIn('staff_id',$staffIds)->groupBy('staff_id','work_date');
        $query=DB::table('hr_attendance_daily_results as result')->joinSub($latest,'latest',fn($join)=>$join->on('latest.staff_id','=','result.staff_id')->on('latest.work_date','=','result.work_date')->on('latest.result_version','=','result.result_version'))
            ->select(['result.id','result.staff_id','result.work_date','result.result_version','result.day_status','result.scheduled_start_at','result.scheduled_end_at','result.first_in_at','result.last_out_at','result.worked_minutes','result.late_minutes','result.early_leave_minutes','result.payable_minutes','result.source_kind','result.calculated_at','result.result_checksum'])
            ->when($data['from']??null,fn($q,$v)=>$q->whereDate('result.work_date','>=',$v))->when($data['to']??null,fn($q,$v)=>$q->whereDate('result.work_date','<=',$v))->when($data['status']??null,fn($q,$v)=>$q->where('result.day_status',$v))->latest('result.work_date');
        return response()->json(['status'=>'success','data'=>$query->paginate($request->integer('per_page',50))]);
    }

    public function calculate(Request $request, AttendanceResultService $service): JsonResponse
    {
        $data=$request->validate(['company_id'=>['required','uuid'],'staff_id'=>['required','uuid','exists:staff,id'],'work_date'=>['required','date']]); $this->sameCompany($request,$data['company_id']);
        abort_unless(Staff::query()->whereKey($data['staff_id'])->where('company_id',$data['company_id'])->exists(),422,'Staff and calculation legal entities must match.');
        return response()->json(['status'=>'success','data'=>$service->calculate($data['company_id'],$data['staff_id'],$data['work_date'],$request->user()->id)]);
    }

    public function storeCalendar(Request $request): JsonResponse
    {
        $this->writes(); $data=$request->validate(['company_id'=>['required','uuid'],'code'=>['required','string','max:80'],'name'=>['required','string','max:255'],'timezone'=>['required','timezone'],'weekly_working_days'=>['required','array','min:1'],'weekly_working_days.*'=>['required',Rule::in(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'])],'effective_from'=>['required','date'],'effective_until'=>['nullable','date','after:effective_from']]); $this->sameCompany($request,$data['company_id']);
        $id=(string)Str::uuid(); DB::table('hr_work_calendars')->insert($this->json($data,['weekly_working_days'])+['id'=>$id,'status'=>'active','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]); return response()->json(['status'=>'success','data'=>DB::table('hr_work_calendars')->find($id)],201);
    }

    public function storeCalendarDay(Request $request,string $calendarId): JsonResponse
    {
        $this->writes(); $calendar=DB::table('hr_work_calendars')->find($calendarId); abort_unless($calendar,404); $this->sameCompany($request,$calendar->company_id); $data=$request->validate(['calendar_date'=>['required','date'],'day_type'=>['required',Rule::in(['working','holiday','rest_day','special_leave'])],'name'=>['nullable','string','max:255'],'paid'=>['required','boolean']]);
        $id=(string)Str::uuid(); DB::table('hr_work_calendar_days')->insert($data+['id'=>$id,'calendar_id'=>$calendarId,'created_at'=>now(),'updated_at'=>now()]); return response()->json(['status'=>'success','data'=>DB::table('hr_work_calendar_days')->find($id)],201);
    }

    public function storeShift(Request $request): JsonResponse
    {
        $this->writes(); $data=$request->validate(['company_id'=>['required','uuid'],'code'=>['required','string','max:80'],'name'=>['required','string','max:255'],'start_time'=>['required','date_format:H:i'],'end_time'=>['required','date_format:H:i'],'ends_next_day'=>['required','boolean'],'unpaid_break_minutes'=>['required','integer','min:0','max:600'],'grace_in_minutes'=>['required','integer','min:0','max:180'],'grace_out_minutes'=>['required','integer','min:0','max:180'],'minimum_half_day_minutes'=>['required','integer','min:1','max:1440'],'minimum_full_day_minutes'=>['required','integer','min:1','max:1440'],'timezone'=>['required','timezone'],'effective_from'=>['required','date'],'effective_until'=>['nullable','date','after:effective_from']]); $this->sameCompany($request,$data['company_id']); abort_if($data['minimum_half_day_minutes']>$data['minimum_full_day_minutes'],422,'Half-day minutes cannot exceed full-day minutes.');
        $id=(string)Str::uuid(); DB::table('hr_shift_definitions')->insert($data+['id'=>$id,'status'=>'active','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]); return response()->json(['status'=>'success','data'=>DB::table('hr_shift_definitions')->find($id)],201);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $this->writes(); $data=$request->validate(['company_id'=>['required','uuid'],'code'=>['required','string','max:80'],'name'=>['required','string','max:255'],'rules'=>['required','array'],'rules.maximum_payable_minutes'=>['nullable','integer','min:1','max:1440'],'effective_from'=>['required','date'],'effective_until'=>['nullable','date','after:effective_from']]); $this->sameCompany($request,$data['company_id']);
        $id=(string)Str::uuid(); DB::table('hr_attendance_policies')->insert($this->json($data,['rules'])+['id'=>$id,'status'=>'pending_approval','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]); return response()->json(['status'=>'success','data'=>DB::table('hr_attendance_policies')->find($id)],201);
    }

    public function approvePolicy(Request $request,string $policyId): JsonResponse
    {
        $this->writes(); return DB::transaction(function()use($request,$policyId){$row=DB::table('hr_attendance_policies')->where('id',$policyId)->lockForUpdate()->first();abort_unless($row,404);$this->sameCompany($request,$row->company_id);abort_if($row->created_by===$request->user()->id,409,'Policy creator cannot approve the same policy.');abort_unless($row->status==='pending_approval',409,'Only a pending policy may be approved.');DB::table('hr_attendance_policies')->where('id',$policyId)->update(['status'=>'approved','approved_by'=>$request->user()->id,'approved_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_attendance_policies')->find($policyId)]);});
    }

    public function storeRoster(Request $request): JsonResponse
    {
        $this->writes(); $data=$request->validate(['company_id'=>['required','uuid'],'staff_id'=>['required','uuid'],'calendar_id'=>['required','uuid'],'shift_id'=>['required','uuid'],'policy_id'=>['required','uuid'],'effective_from'=>['required','date'],'effective_until'=>['nullable','date','after:effective_from'],'reason'=>['required','string','max:500']]);$this->sameCompany($request,$data['company_id']);
        return DB::transaction(function()use($request,$data){DB::table('companies')->where('id',$data['company_id'])->lockForUpdate()->first();foreach(['staff'=>'staff_id','hr_work_calendars'=>'calendar_id','hr_shift_definitions'=>'shift_id','hr_attendance_policies'=>'policy_id']as$table=>$field)abort_unless(DB::table($table)->where('id',$data[$field])->where('company_id',$data['company_id'])->exists(),422,'Roster references must belong to one legal entity.');$overlap=DB::table('hr_roster_assignments')->where('staff_id',$data['staff_id'])->whereDate('effective_from','<',$data['effective_until']??'9999-12-31')->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>',$data['effective_from']))->exists();abort_if($overlap,409,'An overlapping roster already exists.');$id=(string)Str::uuid();DB::table('hr_roster_assignments')->insert($data+['id'=>$id,'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_roster_assignments')->find($id)],201);});
    }

    public function approveRoster(Request $request,string $rosterId): JsonResponse
    {
        $this->writes();return DB::transaction(function()use($request,$rosterId){$row=DB::table('hr_roster_assignments')->where('id',$rosterId)->lockForUpdate()->first();abort_unless($row,404);$this->sameCompany($request,$row->company_id);abort_if($row->created_by===$request->user()->id,409,'Roster creator cannot approve the same roster.');abort_if($row->approved_at,409,'Roster is already approved.');DB::table('hr_roster_assignments')->where('id',$rosterId)->update(['approved_by'=>$request->user()->id,'approved_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_roster_assignments')->find($rosterId)]);});
    }

    public function requestCorrection(Request $request,StaffAccessService $access,HrDomainRequestProjectionService $projection): JsonResponse
    {
        $this->writes();$data=$request->validate(['staff_id'=>['required','uuid'],'work_date'=>['required','date'],'correction_type'=>['required',Rule::in(['missing_punch','wrong_direction','wrong_status','worked_minutes','payable_minutes'])],'requested_values'=>['required','array'],'reason'=>['required','string','max:2000'],'evidence_references'=>['nullable','array'],'idempotency_key'=>['required','string','max:160']]);$staff=Staff::query()->findOrFail($data['staff_id']);$access->authorize($request->user(),$staff,'view');
        $checksum=hash('sha256',json_encode(['staff_id'=>$data['staff_id'],'work_date'=>$data['work_date'],'correction_type'=>$data['correction_type'],'requested_values'=>$data['requested_values'],'reason'=>$data['reason'],'evidence_references'=>$data['evidence_references']??null],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));if($existing=DB::table('hr_attendance_correction_requests')->where('idempotency_key',$data['idempotency_key'])->first()){abort_unless(hash_equals($existing->request_checksum,$checksum),409,'Correction idempotency key was reused with different evidence.');$projection->attendanceCorrection($existing,$request->user()->id);return response()->json(['status'=>'success','data'=>$existing]);}$current=AttendanceDailyResult::query()->where('staff_id',$staff->id)->whereDate('work_date',$data['work_date'])->latest('result_version')->first();$id=(string)Str::uuid();DB::table('hr_attendance_correction_requests')->insert($this->json($data,['requested_values','evidence_references'])+['id'=>$id,'company_id'=>$staff->company_id,'current_result_id'=>$current?->id,'status'=>'pending_approval','request_checksum'=>$checksum,'requested_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);$row=DB::table('hr_attendance_correction_requests')->find($id);$projection->attendanceCorrection($row,$request->user()->id);return response()->json(['status'=>'success','data'=>$row],201);
    }

    public function approveCorrection(Request $request,string $correctionId,AttendanceResultService $service,HrDomainRequestProjectionService $projection): JsonResponse
    {
        $data=$request->validate(['decision_note'=>['required','string','max:2000']]);$row=DB::table('hr_attendance_correction_requests')->find($correctionId);abort_unless($row,404);$this->sameCompany($request,$row->company_id);$result=$service->approveCorrection($correctionId,$request->user()->id,$data['decision_note']);$projection->attendanceCorrection(DB::table('hr_attendance_correction_requests')->find($correctionId),$request->user()->id);return response()->json(['status'=>'success','data'=>$result]);
    }

    public function rejectCorrection(Request $request,string $correctionId,AttendanceResultService $service,HrDomainRequestProjectionService $projection): JsonResponse
    {
        $data=$request->validate(['decision_note'=>['required','string','max:2000']]);$row=DB::table('hr_attendance_correction_requests')->find($correctionId);abort_unless($row,404);$this->sameCompany($request,$row->company_id);$result=$service->rejectCorrection($correctionId,$request->user()->id,$data['decision_note']);$projection->attendanceCorrection($result,$request->user()->id);return response()->json(['status'=>'success','data'=>$result]);
    }

    public function exceptions(Request $request, StaffAccessService $access): JsonResponse
    {
        $data=$request->validate(['staff_id'=>['nullable','uuid'],'status'=>['nullable',Rule::in(['open','resolved','superseded'])],'severity'=>['nullable',Rule::in(['low','medium','high'])]]);$staffIds=$access->scope(Staff::query(),$request->user())->when($data['staff_id']??null,fn($q,$id)=>$q->whereKey($id))->select('id');$query=DB::table('hr_attendance_exceptions as exception')->join('hr_attendance_daily_results as result','result.id','=','exception.daily_result_id')->whereIn('exception.staff_id',$staffIds)->select(['exception.id','exception.staff_id','result.work_date','exception.exception_type','exception.severity','exception.status','exception.evidence','exception.resolved_at','exception.resolution_note'])->when($data['status']??null,fn($q,$v)=>$q->where('exception.status',$v))->when($data['severity']??null,fn($q,$v)=>$q->where('exception.severity',$v))->latest('result.work_date');return response()->json(['status'=>'success','data'=>$query->paginate($request->integer('per_page',50))]);
    }

    public function resolveException(Request $request,string $exceptionId): JsonResponse
    {
        $data=$request->validate(['resolution_note'=>['required','string','max:2000']]);return DB::transaction(function()use($request,$exceptionId,$data){$row=DB::table('hr_attendance_exceptions')->where('id',$exceptionId)->lockForUpdate()->first();abort_unless($row,404);$this->sameCompany($request,$row->company_id);abort_unless($row->status==='open',409,'Only open exceptions may be resolved.');DB::table('hr_attendance_exceptions')->where('id',$exceptionId)->update(['status'=>'resolved','resolved_at'=>now(),'resolved_by'=>$request->user()->id,'resolution_note'=>$data['resolution_note'],'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_attendance_exceptions')->find($exceptionId)]);});
    }

    public function storePeriod(Request $request): JsonResponse
    {
        $this->writes();$data=$request->validate(['company_id'=>['required','uuid'],'period_start'=>['required','date'],'period_end'=>['required','date','after_or_equal:period_start'],'timezone'=>['required','timezone']]);$this->sameCompany($request,$data['company_id']);return DB::transaction(function()use($data){DB::table('companies')->where('id',$data['company_id'])->lockForUpdate()->first();$overlap=DB::table('hr_attendance_periods')->where('company_id',$data['company_id'])->whereDate('period_start','<=',$data['period_end'])->whereDate('period_end','>=',$data['period_start'])->exists();abort_if($overlap,409,'Attendance periods cannot overlap.');$id=(string)Str::uuid();DB::table('hr_attendance_periods')->insert($data+['id'=>$id,'status'=>'open','version'=>1,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_attendance_periods')->find($id)],201);});
    }

    public function transitionPeriod(Request $request,string $periodId): JsonResponse
    {
        $this->writes();$data=$request->validate(['action'=>['required',Rule::in(['review','lock','reopen'])],'reason'=>['required','string','max:2000'],'expected_version'=>['required','integer','min:1']]);return DB::transaction(function()use($request,$periodId,$data){$row=DB::table('hr_attendance_periods')->where('id',$periodId)->lockForUpdate()->first();abort_unless($row,404);$this->sameCompany($request,$row->company_id);abort_unless($row->version===$data['expected_version'],409,'Attendance period version is stale.');$next=['open'=>['review'=>'reviewed'],'reviewed'=>['lock'=>'locked'],'locked'=>['reopen'=>'open']][$row->status][$data['action']]??null;abort_unless($next,409,'Invalid attendance period transition.');if($data['action']==='reopen')abort_unless($request->user()->can('hr.attendance.periods.reopen'),403,'Reopening attendance periods requires separate authority.');if($next==='locked'){abort_unless($row->reviewed_by!==$request->user()->id,409,'The period reviewer cannot lock the same period.');$open=DB::table('hr_attendance_exceptions as exception')->join('hr_attendance_daily_results as result','result.id','=','exception.daily_result_id')->where('exception.company_id',$row->company_id)->where('exception.status','open')->where('exception.severity','high')->whereBetween('result.work_date',[$row->period_start,$row->period_end])->exists();abort_if($open,409,'High-severity attendance exceptions must be resolved before lock.');}$version=$row->version+1;DB::table('hr_attendance_periods')->where('id',$periodId)->update(['status'=>$next,'version'=>$version,'reviewed_at'=>$next==='reviewed'?now():$row->reviewed_at,'reviewed_by'=>$next==='reviewed'?$request->user()->id:$row->reviewed_by,'locked_at'=>$next==='locked'?now():($next==='open'?null:$row->locked_at),'locked_by'=>$next==='locked'?$request->user()->id:($next==='open'?null:$row->locked_by),'state_reason'=>$data['reason'],'updated_at'=>now()]);DB::table('hr_attendance_period_events')->insert(['id'=>(string)Str::uuid(),'period_id'=>$periodId,'event_type'=>$data['action'],'from_status'=>$row->status,'to_status'=>$next,'reason'=>$data['reason'],'actor_user_id'=>$request->user()->id,'occurred_at'=>now(),'version'=>$version,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_attendance_periods')->find($periodId)]);});
    }

    private function sameCompany(Request $request,string $companyId): void{$actorCompany=Staff::query()->where('user_id',$request->user()->id)->value('company_id');abort_unless($actorCompany&&$actorCompany===$companyId,403,'Attendance data is outside your legal entity.');}
    private function writes():void{abort_unless(config('hr.features.attendance_results',false),409,'Attendance result writes are not enabled.');}
    private function json(array $data,array $keys):array{foreach($keys as$key)if(array_key_exists($key,$data))$data[$key]=$data[$key]===null?null:json_encode($data[$key],JSON_THROW_ON_ERROR);return $data;}
}
