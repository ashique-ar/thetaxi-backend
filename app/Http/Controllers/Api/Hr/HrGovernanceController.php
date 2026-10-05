<?php
namespace App\Http\Controllers\Api\Hr;use App\Http\Controllers\Controller;use App\Models\Staff;use App\Services\StaffAccessService;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;
class HrGovernanceController extends Controller{
public function queues(Request$r):JsonResponse{$a=$this->actor($r);$u=$r->user();$data=[];
if($u->can('hr.engagement.approve'))$data['announcements']=DB::table('hr_announcements')->where('company_id',$a->company_id)->where('status','pending_approval')->select(['id','title','priority','content_checksum'])->latest()->limit(100)->get();
if($u->can('hr.surveys.approve'))$data['surveys']=DB::table('hr_survey_versions')->where('company_id',$a->company_id)->where('status','pending_approval')->select(['id','title','survey_type','survey_checksum'])->latest()->limit(100)->get();
if($u->can('hr.recognition.approve'))$data['recognition']=DB::table('hr_recognition_nominations')->where('company_id',$a->company_id)->where('status','pending_approval')->select(['id','category','nomination_checksum'])->latest()->limit(100)->get();
if($u->can('hr.wellness.case.manage'))$data['wellness']=DB::table('hr_wellness_referrals')->where('company_id',$a->company_id)->whereIn('status',['requested','assigned','in_support'])->select(['referral_type','status'])->latest()->limit(100)->get();
if($u->can('hr.analytics.approve'))$data['analytics_definitions']=DB::table('hr_analytics_definition_versions')->where('company_id',$a->company_id)->where('status','pending_approval')->select(['id','metric_code','name','definition_checksum'])->latest()->limit(100)->get();
if($u->can('hr.workforce-planning.approve'))$data['workforce_plans']=DB::table('hr_workforce_plan_versions')->where('company_id',$a->company_id)->where('status','pending_approval')->select(['id','code','name','plan_checksum'])->latest()->limit(100)->get();
if($u->can('hr.reporting.approve'))$data['report_schedules']=DB::table('hr_report_schedules')->where('company_id',$a->company_id)->whereIn('status',['pending_approval','pending_review'])->select(['id','name','cadence','status','schedule_checksum'])->latest()->limit(100)->get();
if($u->can('hr.notifications.approve'))$data['notification_templates']=DB::table('hr_notification_template_versions')->where('company_id',$a->company_id)->where('status','pending_approval')->select(['id','code','channel','template_checksum'])->latest()->limit(100)->get();
$counts=collect($data)->map(fn($rows)=>$rows->count());return response()->json(['status'=>'success','data'=>['counts'=>$counts,'queues'=>$data,'generated_at'=>now()->toIso8601String()]]);}
private function actor(Request$r):Staff{return app(StaffAccessService::class)->currentActorStaff($r->user());}}
