<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Services\Hr\Talent\PerformanceManagementService;
use App\Services\Hr\Integration\SalesKpiReviewEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PerformanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staff = $this->actor($request);
        $query = DB::table('hr_performance_reviews as r')->join('hr_review_cycles as c', 'c.id', '=', 'r.cycle_id')->leftJoin('staff as s','s.id','=','r.staff_id')->leftJoin('users as u','u.id','=','s.user_id')->where('r.company_id', $staff->company_id)->select(['r.id','r.staff_id','r.manager_staff_id','r.status','r.final_rating','r.acknowledged_at','c.name as cycle_name','c.period_start','c.period_end','s.code as staff_code','u.first_name as staff_first_name','u.last_name as staff_last_name']);
        if ($request->boolean('mine') || ! $request->user()->can('hr.performance.view-all')) $query->where(fn($q)=>$q->where('r.staff_id',$staff->id)->orWhere('r.manager_staff_id',$staff->id));
        return response()->json(['status' => 'success', 'data' => $query->latest('c.period_end')->paginate($request->integer('per_page', 50))]);
    }

    public function show(Request $request, string $id, SalesKpiReviewEvidenceService $salesEvidence): JsonResponse
    {
        $actor = $this->actor($request);
        $review = DB::table('hr_performance_reviews')->where('id', $id)->where('company_id', $actor->company_id)->first();
        abort_unless($review, 404);
        abort_unless($request->user()->can('hr.performance.view-all') || $review->staff_id===$actor->id || $review->manager_staff_id===$actor->id,403);
        $link = $salesEvidence->forReview($id);
        return response()->json(['status' => 'success', 'data' => ['review' => $review, 'responses' => DB::table('hr_review_responses')->where('review_id', $id)->get(), 'goals' => DB::table('hr_goals')->where('review_id', $id)->get(), 'sales_kpi_evidence' => $link, 'events' => DB::table('hr_performance_review_events')->where('review_id', $id)->orderBy('occurred_at')->get()]]);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $this->enabled(); $data = $request->validate(['company_id'=>['required','uuid'],'code'=>['required','string','max:80'],'name'=>['required','string','max:255'],'period_start'=>['required','date'],'period_end'=>['required','date','after_or_equal:period_start'],'stages'=>['required','array','min:1']]); $this->company($request, $data['company_id']);
        $id=(string)Str::uuid(); DB::table('hr_review_cycles')->insert($data+['id'=>$id,'stages'=>json_encode($data['stages'],JSON_THROW_ON_ERROR),'status'=>'draft','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['status'=>'success','data'=>DB::table('hr_review_cycles')->find($id)],201);
    }

    public function approveCycle(Request $request, string $id, PerformanceManagementService $service): JsonResponse
    { $this->enabled(); $this->owns($request,'hr_review_cycles',$id); return response()->json(['status'=>'success','data'=>$service->approveCycle($id,$request->user()->id)]); }

    public function storeTemplate(Request $request): JsonResponse
    {
        $this->enabled(); $data=$request->validate(['company_id'=>['required','uuid'],'code'=>['required','string','max:80'],'version'=>['required','integer','min:1'],'applicability'=>['required','array'],'sections'=>['required','array','min:1'],'rating_scale'=>['required','array','min:1']]); $this->company($request,$data['company_id']);
        $id=(string)Str::uuid(); foreach(['applicability','sections','rating_scale'] as $key)$data[$key]=json_encode($data[$key],JSON_THROW_ON_ERROR); DB::table('hr_review_templates')->insert($data+['id'=>$id,'status'=>'pending_approval','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['status'=>'success','data'=>DB::table('hr_review_templates')->find($id)],201);
    }

    public function approveTemplate(Request $request,string $id,PerformanceManagementService $service):JsonResponse
    { $this->enabled(); $this->owns($request,'hr_review_templates',$id); return response()->json(['status'=>'success','data'=>$service->approveTemplate($id,$request->user()->id)]); }

    public function assign(Request $request,PerformanceManagementService $service):JsonResponse
    { $this->enabled(); $data=$request->validate(['company_id'=>['required','uuid'],'cycle_id'=>['required','uuid'],'template_id'=>['required','uuid'],'staff_id'=>['required','uuid'],'manager_staff_id'=>['nullable','uuid']]); $this->company($request,$data['company_id']); return response()->json(['status'=>'success','data'=>$service->assignReview($data,$request->user()->id)],201); }

    public function linkSalesKpi(Request $request,string $id,SalesKpiReviewEvidenceService $service):JsonResponse
    { $this->enabled(); $data=$request->validate(['sales_kpi_snapshot_row_id'=>['required','uuid']]); $this->owns($request,'hr_performance_reviews',$id); return response()->json(['status'=>'success','data'=>$service->link($id,$data['sales_kpi_snapshot_row_id'],$request->user()->id)],201); }

    public function respond(Request $request,string $id):JsonResponse
    {
        $this->enabled(); $actor=$this->actor($request); $data=$request->validate(['respondent_role'=>['required',Rule::in(['self','manager'])],'responses'=>['required','array','min:1'],'rating'=>['nullable','numeric','between:0,100'],'submit'=>['nullable','boolean']]);
        $review=DB::table('hr_performance_reviews')->where('id',$id)->where('company_id',$actor->company_id)->first(); abort_unless($review,404); abort_unless(($data['respondent_role']==='self'&&$review->staff_id===$actor->id)||($data['respondent_role']==='manager'&&$review->manager_staff_id===$actor->id),403,'You are not the assigned respondent.'); abort_if(in_array($review->status,['finalized','acknowledged'],true),409,'The review is frozen.');
        $key=['review_id'=>$id,'respondent_role'=>$data['respondent_role'],'respondent_staff_id'=>$actor->id]; $values=['responses'=>json_encode($data['responses'],JSON_THROW_ON_ERROR),'rating'=>$data['rating']??null,'status'=>($data['submit']??false)?'submitted':'draft','submitted_at'=>($data['submit']??false)?now():null,'updated_at'=>now()]; $existing=DB::table('hr_review_responses')->where($key)->first(); if($existing){abort_if($existing->status==='submitted',409,'A submitted response is immutable.');DB::table('hr_review_responses')->where('id',$existing->id)->update($values);}else{DB::table('hr_review_responses')->insert($key+$values+['id'=>(string)Str::uuid(),'created_at'=>now()]);}
        return response()->json(['status'=>'success','data'=>DB::table('hr_review_responses')->where('review_id',$id)->where('respondent_role',$data['respondent_role'])->where('respondent_staff_id',$actor->id)->first()]);
    }

    public function transition(Request $request,string $id,PerformanceManagementService $service):JsonResponse
    { $this->enabled(); $data=$request->validate(['to'=>['required',Rule::in(['manager_review','calibration','finalized','acknowledged'])],'final_rating'=>['nullable','numeric','between:0,100'],'calibration_reason'=>['nullable','string','max:4000']]); $this->owns($request,'hr_performance_reviews',$id); $actor=$this->actor($request); return response()->json(['status'=>'success','data'=>$service->transition($id,$data['to'],$data,$request->user()->id,$actor->id)]); }

    public function storeGoal(Request $request):JsonResponse
    {
        $this->enabled();
        $actor=$this->actor($request);
        $data=$request->validate(['staff_id'=>['required','uuid'],'review_id'=>['nullable','uuid'],'parent_goal_id'=>['nullable','uuid'],'title'=>['required','string','max:255'],'description'=>['required','string','max:4000'],'weight'=>['required','numeric','between:0.0001,100'],'measurement_kind'=>['required',Rule::in(['numeric','percentage','milestone','rating'])],'target_value'=>['nullable','numeric'],'starts_at'=>['required','date'],'due_at'=>['required','date','after_or_equal:starts_at']]);

        $goal=DB::transaction(function()use($data,$actor){
            $staff=Staff::query()->whereKey($data['staff_id'])->where('company_id',$actor->company_id)->lockForUpdate()->firstOrFail();
            if(!empty($data['review_id'])){
                abort_unless(DB::table('hr_performance_reviews')->where('id',$data['review_id'])->where('company_id',$actor->company_id)->where('staff_id',$staff->id)->exists(),422,'The selected review does not belong to this employee and legal entity.');
            }
            if(!empty($data['parent_goal_id'])){
                $parent=DB::table('hr_goals')->where('id',$data['parent_goal_id'])->where('company_id',$actor->company_id)->where('staff_id',$staff->id)->first();
                abort_unless($parent,422,'The parent goal does not belong to this employee and legal entity.');
                abort_unless(($parent->review_id??null)===($data['review_id']??null),422,'A parent goal must belong to the same review context.');
            }
            $activeGoals=DB::table('hr_goals')->where('staff_id',$staff->id)->where('review_id',$data['review_id']??null)->whereNotIn('status',['cancelled'])->select('weight')->lockForUpdate()->get();
            $weight=(float)$activeGoals->sum('weight');
            abort_if($weight+(float)$data['weight']>100.0001,422,'Active goal weights cannot exceed 100%.');
            $id=(string)Str::uuid();
            DB::table('hr_goals')->insert($data+['id'=>$id,'company_id'=>$staff->company_id,'status'=>'draft','created_at'=>now(),'updated_at'=>now()]);
            return DB::table('hr_goals')->find($id);
        });
        return response()->json(['status'=>'success','data'=>$goal],201);
    }

    public function storeAchievement(Request $request):JsonResponse
    {
        $this->enabled(); $actor=$this->actor($request); $data=$request->validate(['staff_id'=>['required','uuid'],'category'=>['required','string','max:60'],'title'=>['required','string','max:255'],'description'=>['required','string','max:4000'],'achievement_date'=>['required','date','before_or_equal:today'],'outcome_snapshot'=>['required','array'],'evidence'=>['nullable','array'],'visibility'=>['required',Rule::in(['private','manager','company'])]]); $staff=Staff::query()->whereKey($data['staff_id'])->where('company_id',$actor->company_id)->firstOrFail(); abort_unless($staff->id===$actor->id,403,'Employees can submit only their own achievement.'); $id=(string)Str::uuid(); foreach(['outcome_snapshot','evidence'] as $key)if(array_key_exists($key,$data))$data[$key]=json_encode($data[$key],JSON_THROW_ON_ERROR); DB::table('hr_achievements')->insert($data+['id'=>$id,'company_id'=>$staff->company_id,'status'=>'pending_verification','submitted_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]); $this->achievementEvent($id,'submitted',null,'pending_verification',null,$request->user()->id); return response()->json(['status'=>'success','data'=>DB::table('hr_achievements')->find($id)],201);
    }

    public function decideAchievement(Request $request,string $id):JsonResponse
    {
        $this->enabled(); $data=$request->validate(['decision'=>['required',Rule::in(['verified','rejected','revoked'])],'reason'=>['required','string','max:2000']]); return DB::transaction(function()use($request,$id,$data){$row=DB::table('hr_achievements')->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);$this->company($request,$row->company_id);abort_if($row->submitted_by===$request->user()->id,409,'The submitter cannot verify their own achievement.');$allowed=['pending_verification'=>['verified','rejected'],'verified'=>['revoked']];abort_unless(in_array($data['decision'],$allowed[$row->status]??[],true),409,'Invalid achievement decision.');DB::table('hr_achievements')->where('id',$id)->update(['status'=>$data['decision'],'verified_by'=>$request->user()->id,'verified_at'=>now(),'verification_reason'=>$data['reason'],'updated_at'=>now()]);$this->achievementEvent($id,$data['decision'],$row->status,$data['decision'],$data['reason'],$request->user()->id);return response()->json(['status'=>'success','data'=>DB::table('hr_achievements')->find($id)]);});
    }

    private function achievementEvent(string$id,string$type,?string$from,?string$to,?string$reason,string$actor):void{DB::table('hr_achievement_events')->insert(['id'=>(string)Str::uuid(),'achievement_id'=>$id,'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'reason'=>$reason,'actor_user_id'=>$actor,'occurred_at'=>now()]);}
    private function actor(Request$request):Staff{return Staff::query()->where('user_id',$request->user()->id)->firstOrFail();}
    private function company(Request$request,string$id):void{abort_unless($this->actor($request)->company_id===$id,403,'Performance data is outside your legal entity.');}
    private function owns(Request$request,string$table,string$id):void{$company=DB::table($table)->where('id',$id)->value('company_id');abort_unless($company,404);$this->company($request,$company);}
    private function enabled():void{abort_unless(config('hr.features.talent',false),409,'HR talent writes are not enabled.');}
}
