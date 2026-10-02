<?php
namespace App\Services\Hr;use Illuminate\Support\Facades\DB;
/**
 * HR reporting: dataset *build*. Given a queued run (with its frozen
 * filter/as-of snapshot) and its saved view, produces the row data for the
 * report kind (analytics snapshots, workforce plans, or data-quality
 * issues) as plain arrays. Pure read/shape logic only — it does not queue
 * runs (see {@see HrReportRunService}) or render/store output files (see
 * {@see HrReportArtifactService}).
 */
class HrReportDatasetService{
public function __construct(private readonly HrDataQualityService$quality){}
public function rows(object$run,object$view):array{$filters=json_decode($run->filter_snapshot,true)?:[];return match($view->report_kind){'analytics_snapshots'=>$this->analytics($run->company_id,$filters,$run->as_of_date),'workforce_plans'=>$this->plans($run->company_id,$filters,$run->as_of_date),'data_quality'=>$this->quality($run->company_id,$filters),default=>[]};}
private function analytics(string$company,array$f,string$asOf):array{$q=DB::table('hr_analytics_snapshots as s')->join('hr_analytics_definition_versions as d','d.id','=','s.definition_version_id')->where('s.company_id',$company)->whereDate('s.as_of_date','<=',$asOf);if(!empty($f['metric_code']))$q->where('d.metric_code',$f['metric_code']);if(!empty($f['as_of_from']))$q->whereDate('s.as_of_date','>=',$f['as_of_from']);if(!empty($f['as_of_to']))$q->whereDate('s.as_of_date','<=',$f['as_of_to']);return$q->select(['d.metric_code','d.name as metric_name','s.as_of_date','s.aggregate_payload as aggregate','s.suppression_snapshot as suppression','s.snapshot_checksum'])->orderBy('d.metric_code')->orderBy('s.as_of_date')->get()->map(fn($x)=>(array)$x)->all();}
private function plans(string$company,array$f,string$asOf):array{$q=DB::table('hr_workforce_plan_versions')->where('company_id',$company)->whereDate('horizon_start','<=',$asOf);if(!empty($f['status']))$q->where('status',$f['status']);return$q->select(['code as plan_code','name as plan_name',DB::raw("CONCAT(horizon_start, ' - ', horizon_end) as horizon"),'status as plan_status','plan_checksum as snapshot_checksum'])->orderBy('horizon_start')->get()->map(fn($x)=>(array)$x)->all();}
private function quality(string$company,array$f):array{$rows=$this->quality->summarize($company)['issues'];if(!empty($f['issue_type']))$rows=array_values(array_filter($rows,fn($x)=>$x['code']===$f['issue_type']||$x['domain']===$f['issue_type']));return array_map(fn($x)=>['issue_code'=>$x['code'],'issue_domain'=>$x['domain'],'issue_severity'=>$x['severity'],'issue_count'=>$x['available']?$x['count']:'unavailable'], $rows);}}
