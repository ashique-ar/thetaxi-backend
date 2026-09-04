<?php
namespace App\Services\Hr\Ess;
use Illuminate\Support\Facades\DB;
class HrDomainRequestProjectionService{
 public function leave(object$row,string$actor):void{$summary='Leave '.$row->start_date.' to '.$row->end_date;$this->sync($row->company_id,$row->staff_id,'leave','leave_request',$row->id,$row->status,$summary,$row->current_approver_staff_id,$actor,['leave_overtime'=>true]);}
 public function work(object$row,string$actor):void{$this->sync($row->company_id,$row->staff_id,$row->request_kind,'work_request',$row->id,$row->status,ucwords(str_replace('_',' ',$row->request_kind)).' request',null,$actor,['leave_overtime'=>true]);}
 public function timesheet(object$row,string$actor):void{$this->sync($row->company_id,$row->staff_id,'timesheet','timesheet',$row->id,$row->status,'Timesheet '.$row->period_start.' to '.$row->period_end,null,$actor,['leave_overtime'=>true,'attendance_results'=>config('hr.features.attendance_results',false)]);}
 public function attendanceCorrection(object$row,string$actor):void{$this->sync($row->company_id,$row->staff_id,'attendance_correction','attendance_correction',$row->id,$row->status,'Attendance correction for '.$row->work_date,null,$actor,['attendance_results'=>true]);}
 private function sync(string$company,string$staff,string$type,string$source,string$id,string$status,string$summary,?string$owner,string$actor,array$caps):void{if(!config('hr.features.employee_self_service',false))return;$index=app(HrRequestIndexService::class);$existing=DB::table('hr_request_index')->where('source_type',$source)->where('source_id',$id)->first();if(!$existing){$index->register($company,$staff,$type,$source,$id,$status,$summary,$owner,48,$actor,$caps);return;}if($existing->status!==$status||$existing->current_owner_staff_id!==$owner)$index->transition($source,$id,$status,'Request status changed to '.str_replace('_',' ',$status).'.',$actor,$owner,$status==='approved'?$source:null,$status==='approved'?$id:null);}
}
