<?php
namespace App\Services\Hr\Ess;
use Illuminate\Support\Facades\DB;
class HrDomainRequestProjectionService{
 public function leave(object$row,string$actor):void{$summary='Leave '.$row->start_date.' to '.$row->end_date;$this->sync($row->staff_id,'leave','leave_request',$row->id,$row->status,$summary,$row->current_approver_staff_id,$actor,['leave_overtime'=>true]);}
 public function work(object$row,string$actor):void{$this->sync($row->staff_id,$row->request_kind,'work_request',$row->id,$row->status,ucwords(str_replace('_',' ',$row->request_kind)).' request',$this->primaryManager($row->staff_id),$actor,['leave_overtime'=>true]);}
 public function timesheet(object$row,string$actor):void{$this->sync($row->staff_id,'timesheet','timesheet',$row->id,$row->status,'Timesheet '.$row->period_start.' to '.$row->period_end,$this->primaryManager($row->staff_id),$actor,['leave_overtime'=>true,'attendance_results'=>true]);}
 public function attendanceCorrection(object$row,string$actor):void{$this->sync($row->staff_id,'attendance_correction','attendance_correction',$row->id,$row->status,'Attendance correction for '.$row->work_date,$this->primaryManager($row->staff_id),$actor,['attendance_results'=>true]);}
 public function reroutePrimaryManagerApprovals(string $staff, string $actor, bool $commit): int
 {
     $manager = $this->primaryManager($staff);
     $requests = DB::table('hr_request_index')->where('requester_staff_id', $staff)
         ->whereIn('source_type', ['work_request', 'timesheet', 'attendance_correction'])
         ->whereIn('status', ['pending', 'pending_approval', 'submitted', 'returned'])
         ->whereNotNull('current_owner_staff_id')
         ->get(['source_type', 'source_id', 'status', 'current_owner_staff_id']);
     $changed = 0;
     foreach ($requests as $request) {
         if ($request->current_owner_staff_id === $manager) continue;
         $wasPrimaryLine = DB::table('hr_reporting_lines')->where('member_staff_id', $staff)
             ->where('manager_staff_id', $request->current_owner_staff_id)->where('line_type', 'primary')
             ->whereNotNull('effective_until')->whereDate('effective_until', '<=', now())->exists();
         $wasPrimaryAssignment = DB::table('hr_employment_assignments')->where('staff_id', $staff)
             ->where('manager_staff_id', $request->current_owner_staff_id)->where('assignment_type', 'primary')
             ->whereNotNull('effective_until')->whereDate('effective_until', '<=', now())->exists();
         if (! $wasPrimaryLine && ! $wasPrimaryAssignment) continue;
         if (! $commit) { $changed++; continue; }
         $updated = DB::transaction(function () use ($request, $manager, $actor): bool {
             $current = DB::table('hr_request_index')->where('source_type', $request->source_type)
                 ->where('source_id', $request->source_id)->lockForUpdate()->first();
             if (! $current || $current->current_owner_staff_id !== $request->current_owner_staff_id
                 || ! in_array($current->status, ['pending', 'pending_approval', 'submitted', 'returned'], true)) return false;
             app(HrRequestIndexService::class)->transition($request->source_type, $request->source_id, $current->status,
                 'Approval reassigned after the primary HR reporting line changed.', $actor, $manager);
             return true;
         });
         if ($updated) $changed++;
     }

     return $changed;
 }
 private function primaryManager(string$staff):?string{$manager=DB::table('hr_reporting_lines as line')->join('staff','staff.id','=','line.manager_staff_id')->where('line.member_staff_id',$staff)->where('line.line_type','primary')->where('line.status','active')->whereDate('line.effective_from','<=',now())->where(fn($q)=>$q->whereNull('line.effective_until')->orWhereDate('line.effective_until','>',now()))->whereNull('staff.employment_ended_at')->whereNull('staff.deleted_at')->value('line.manager_staff_id');if($manager)return (string)$manager;return DB::table('hr_employment_assignments as assignment')->join('staff','staff.id','=','assignment.manager_staff_id')->where('assignment.staff_id',$staff)->where('assignment.assignment_type','primary')->whereDate('assignment.effective_from','<=',now())->where(fn($q)=>$q->whereNull('assignment.effective_until')->orWhereDate('assignment.effective_until','>',now()))->whereNull('staff.employment_ended_at')->whereNull('staff.deleted_at')->orderByDesc('assignment.effective_from')->value('assignment.manager_staff_id');}
 private function sync(string$staff,string$type,string$source,string$id,string$status,string$summary,?string$owner,string$actor,array$caps):void{$index=app(HrRequestIndexService::class);$existing=DB::table('hr_request_index')->where('source_type',$source)->where('source_id',$id)->first();if(!$existing){$index->register($staff,$type,$source,$id,$status,$summary,$owner,48,$actor,$caps);return;}if($existing->status!==$status||$existing->current_owner_staff_id!==$owner)$index->transition($source,$id,$status,'Request status changed to '.str_replace('_',' ',$status).'.',$actor,$owner,$status==='approved'?$source:null,$status==='approved'?$id:null);}
}
