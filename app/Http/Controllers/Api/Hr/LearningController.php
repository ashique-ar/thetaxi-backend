<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LearningController extends Controller
{
    public function courses(Request$request):JsonResponse{$staff=$this->actor($request);return response()->json(['status'=>'success','data'=>DB::table('hr_courses')->where('company_id',$staff->company_id)->orderBy('title')->paginate($request->integer('per_page',50))]);}
    public function storeCourse(Request$request):JsonResponse{$this->enabled();$d=$request->validate(['company_id'=>['required','uuid'],'code'=>['required','string','max:80'],'title'=>['required','string','max:255'],'provider_name'=>['nullable','string','max:255'],'prerequisites'=>['nullable','array'],'cost'=>['nullable','numeric','min:0'],'currency'=>['nullable','string','size:3'],'certification'=>['required','boolean'],'validity_days'=>['nullable','integer','min:1']]);$this->company($request,$d['company_id']);$id=(string)Str::uuid();if(isset($d['prerequisites']))$d['prerequisites']=json_encode($d['prerequisites'],JSON_THROW_ON_ERROR);DB::table('hr_courses')->insert($d+['id'=>$id,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_courses')->find($id)],201);}
    /**
     * §5.17: "Required learning by role/location, due dates, reminders,
     * certification/expiry, and refresher scheduling." The 2026-08-15
     * `hr_learning_requirements` register deliberately deferred compliance-status
     * computation as a distinct follow-up (per its own migration docblock); this
     * closes that follow-up read-only. A requirement applies only when at least
     * one of its staff-type/organization-unit dimensions is populated and every
     * populated dimension matches (an empty requirement is never an implicit
     * "required for everyone" default). The due date is the matching current
     * employment assignment's effective_from plus due_days, per the register's own
     * documented "due window from assignment" design; a Staff with no current
     * assignment cannot yet have an overdue date. Reminder *delivery* (email/SMS/
     * in-app) is a distinct, larger follow-up and remains out of scope here.
     */
    public function compliance(Request$request):JsonResponse
    {
        $actor=$this->actor($request);
        $staffId=$request->input('staff_id');
        if($staffId&&$staffId!==$actor->id){
            abort_unless($request->user()->can('hr.learning.manage'),403,'Viewing another employee\'s learning compliance requires HR learning management access.');
            $staff=Staff::query()->whereKey($staffId)->where('company_id',$actor->company_id)->firstOrFail();
        }else{
            $staff=$actor;
        }
        $today=now()->toDateString();
        $assignment=$staff->employmentAssignments->first(fn($a)=>$a->effective_from->lte(now())&&(!$a->effective_until||$a->effective_until->gt(now())));
        $requirements=DB::table('hr_learning_requirements')->where('company_id',$staff->company_id)->where('status','active')
            ->where('effective_from','<=',$today)->where(fn($r)=>$r->whereNull('effective_until')->orWhere('effective_until','>=',$today))->get();
        $completions=DB::table('hr_learning_enrollments as e')->join('hr_course_sessions as s','s.id','=','e.session_id')
            ->where('e.staff_id',$staff->id)->where('e.status','completed')
            ->select(['s.course_id','e.completed_at','e.certificate_expires_at'])->orderByDesc('e.completed_at')->get()->groupBy('course_id');

        $rows=[];
        foreach($requirements as $requirement){
            $decoded=$this->decodeJsonColumns($requirement,['applies_to_staff_types','applies_to_organization_unit_ids']);
            $forStaffTypes=$decoded['applies_to_staff_types']??null;
            $forUnits=$decoded['applies_to_organization_unit_ids']??null;
            if(empty($forStaffTypes)&&empty($forUnits))
                continue;
            if(!empty($forStaffTypes)&&!in_array($staff->staff_type,$forStaffTypes,true))
                continue;
            if(!empty($forUnits)&&(!$assignment||!in_array($assignment->organization_unit_id,$forUnits,true)))
                continue;

            $course=DB::table('hr_courses')->find($requirement->course_id);
            $dueDate=$assignment?\Carbon\CarbonImmutable::parse($assignment->effective_from)->addDays($requirement->due_days)->toDateString():null;
            $latest=($completions->get($requirement->course_id)??collect())->first();
            $validCompletion=$latest&&(!$latest->certificate_expires_at||$latest->certificate_expires_at>=$today)
                &&(!$requirement->refresher_interval_days||\Carbon\CarbonImmutable::parse($latest->completed_at)->addDays($requirement->refresher_interval_days)->toDateString()>=$today);
            $status=$validCompletion?'satisfied':(($dueDate&&$dueDate<=$today)?'overdue':'pending');

            $rows[]=['requirement_id'=>$requirement->id,'course_id'=>$requirement->course_id,'course_title'=>$course->title,'due_date'=>$dueDate,'status'=>$status,'last_completed_at'=>$latest?->completed_at,'certificate_expires_at'=>$latest?->certificate_expires_at];
        }

        return response()->json(['status'=>'success','data'=>$rows]);
    }

    public function requirements(Request$request):JsonResponse{$staff=$this->actor($request);$rows=DB::table('hr_learning_requirements as req')->join('hr_courses as course','course.id','=','req.course_id')->where('req.company_id',$staff->company_id)->select(['req.id','req.course_id','course.title as course_title','req.applies_to_staff_types','req.applies_to_organization_unit_ids','req.due_days','req.refresher_interval_days','req.status','req.effective_from','req.effective_until'])->orderBy('course.title')->paginate($request->integer('per_page',50));$rows->getCollection()->transform(fn($row)=>$this->decodeJsonColumns($row,['applies_to_staff_types','applies_to_organization_unit_ids']));return response()->json(['status'=>'success','data'=>$rows]);}
    public function storeRequirement(Request$request):JsonResponse{$this->enabled();$d=$request->validate(['course_id'=>['required','uuid'],'applies_to_staff_types'=>['nullable','array'],'applies_to_organization_unit_ids'=>['nullable','array'],'applies_to_organization_unit_ids.*'=>['uuid'],'due_days'=>['required','integer','min:1','max:3650'],'refresher_interval_days'=>['nullable','integer','min:1','max:3650'],'effective_from'=>['required','date'],'effective_until'=>['nullable','date','after:effective_from']]);$actor=$this->actor($request);$course=DB::table('hr_courses')->where('id',$d['course_id'])->where('company_id',$actor->company_id)->first();abort_unless($course,422,'The course must belong to your legal entity.');if(!empty($d['applies_to_organization_unit_ids']))abort_unless(DB::table('hr_organization_units')->where('company_id',$actor->company_id)->whereIn('id',$d['applies_to_organization_unit_ids'])->count()===count($d['applies_to_organization_unit_ids']),422,'Every organization unit must belong to your legal entity.');$id=(string)Str::uuid();foreach(['applies_to_staff_types','applies_to_organization_unit_ids']as$key)if(isset($d[$key]))$d[$key]=json_encode($d[$key],JSON_THROW_ON_ERROR);DB::table('hr_learning_requirements')->insert($d+['id'=>$id,'company_id'=>$actor->company_id,'status'=>'active','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>$this->decodeJsonColumns(DB::table('hr_learning_requirements')->find($id),['applies_to_staff_types','applies_to_organization_unit_ids'])],201);}
    private function decodeJsonColumns(object$row,array$columns):array{$data=(array)$row;foreach($columns as$column)if(isset($data[$column])&&is_string($data[$column]))$data[$column]=json_decode($data[$column],true,512,JSON_THROW_ON_ERROR);return$data;}

    public function storeSession(Request$request):JsonResponse{$this->enabled();$d=$request->validate(['course_id'=>['required','uuid'],'starts_at'=>['required','date'],'ends_at'=>['required','date','after:starts_at'],'timezone'=>['required','timezone'],'capacity'=>['required','integer','min:1'],'delivery_mode'=>['required',Rule::in(['classroom','virtual','hybrid','self_paced'])],'location_or_link'=>['nullable','string','max:500']]);$course=DB::table('hr_courses')->where('id',$d['course_id'])->first();abort_unless($course,404);$this->company($request,$course->company_id);$id=(string)Str::uuid();DB::table('hr_course_sessions')->insert($d+['id'=>$id,'status'=>'planned','created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_course_sessions')->find($id)],201);}
    public function enroll(Request$request):JsonResponse{$this->enabled();$actor=$this->actor($request);$d=$request->validate(['session_id'=>['required','uuid'],'staff_id'=>['required','uuid'],'nomination_source'=>['required',Rule::in(['self','manager','hr','development_plan'])]]);$session=DB::table('hr_course_sessions as s')->join('hr_courses as c','c.id','=','s.course_id')->where('s.id',$d['session_id'])->select('s.*','c.company_id')->first();$staff=Staff::query()->whereKey($d['staff_id'])->where('company_id',$actor->company_id)->first();abort_unless($session&&$staff&&$session->company_id===$actor->company_id,422,'Session and employee must be in the same legal entity.');if($d['nomination_source']==='self')abort_unless($staff->id===$actor->id,403);$active=DB::table('hr_learning_enrollments')->where('session_id',$session->id)->whereIn('status',['approved','completed'])->count();abort_if($active >= $session->capacity,409,'The session capacity is full.');$id=(string)Str::uuid();DB::table('hr_learning_enrollments')->insert($d+['id'=>$id,'status'=>'pending_approval','nominated_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_learning_enrollments')->find($id)],201);}
    public function approve(Request$request,string$id):JsonResponse{$this->enabled();return DB::transaction(function()use($request,$id){$row=DB::table('hr_learning_enrollments as e')->join('hr_course_sessions as s','s.id','=','e.session_id')->join('hr_courses as c','c.id','=','s.course_id')->where('e.id',$id)->select('e.*','s.capacity','c.company_id')->lockForUpdate()->first();abort_unless($row,404);$this->company($request,$row->company_id);abort_unless($row->status==='pending_approval',409);abort_if($row->nominated_by===$request->user()->id,409,'The nominator cannot approve the same enrollment.');$active=DB::table('hr_learning_enrollments')->where('session_id',$row->session_id)->whereIn('status',['approved','completed'])->lockForUpdate()->count();abort_if($active >= $row->capacity,409,'The session capacity is full.');DB::table('hr_learning_enrollments')->where('id',$id)->update(['status'=>'approved','approved_by'=>$request->user()->id,'approved_at'=>now(),'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_learning_enrollments')->find($id)]);});}
    public function complete(Request$request,string$id):JsonResponse{$this->enabled();$d=$request->validate(['assessment_score'=>['nullable','numeric','between:0,100'],'evidence'=>['nullable','array']]);return DB::transaction(function()use($request,$id,$d){$row=DB::table('hr_learning_enrollments as e')->join('hr_course_sessions as s','s.id','=','e.session_id')->join('hr_courses as c','c.id','=','s.course_id')->where('e.id',$id)->select('e.*','c.company_id','c.certification','c.validity_days')->lockForUpdate()->first();abort_unless($row,404);$this->company($request,$row->company_id);abort_unless($row->status==='approved',409);$expiry=$row->certification&&$row->validity_days?now()->addDays($row->validity_days)->toDateString():null;DB::table('hr_learning_enrollments')->where('id',$id)->update(['status'=>'completed','assessment_score'=>$d['assessment_score']??null,'completed_at'=>now(),'certificate_expires_at'=>$expiry,'evidence'=>isset($d['evidence'])?json_encode($d['evidence'],JSON_THROW_ON_ERROR):null,'updated_at'=>now()]);return response()->json(['status'=>'success','data'=>DB::table('hr_learning_enrollments')->find($id)]);});}
    private function actor(Request$r):Staff{return Staff::query()->where('user_id',$r->user()->id)->firstOrFail();}private function company(Request$r,string$id):void{abort_unless($this->actor($r)->company_id===$id,403,'Learning data is outside your legal entity.');}private function enabled():void{abort_unless(config('hr.features.learning',false),409,'HR learning writes are not enabled.');}
}
