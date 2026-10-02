<?php

namespace App\Services\Hr\Ess;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HrRequestIndexService
{
    public function register(string $company, string $staff, string $type, string $sourceType, string $sourceId, string $status, string $summary, ?string $ownerStaff, ?int $slaHours, string $actor, array $capabilities=[]): ?object
    {
        if (! config('hr.features.employee_self_service', false)) return null;
        if ($existing=DB::table('hr_request_index')->where('source_type',$sourceType)->where('source_id',$sourceId)->first()) return $existing;
        $id=(string)Str::uuid();
        DB::table('hr_request_index')->insert(['id'=>$id,'company_id'=>$company,'requester_staff_id'=>$staff,'request_type'=>$type,'source_type'=>$sourceType,'source_id'=>$sourceId,'status'=>$status,'summary'=>$summary,'current_owner_staff_id'=>$ownerStaff,'due_at'=>$slaHours?now()->addHours($slaHours):null,'capability_snapshot'=>json_encode($capabilities,JSON_THROW_ON_ERROR),'submitted_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $this->event($id,'submitted',null,$status,'Request submitted.',$actor,[]);
        return DB::table('hr_request_index')->find($id);
    }
    public function transition(string $sourceType,string $sourceId,string $status,string $message,string $actor,?string $owner=null,?string $resultType=null,?string $resultId=null,array $private=[]):void
    {
        if (! config('hr.features.employee_self_service', false)) return;
        $row=DB::table('hr_request_index')->where('source_type',$sourceType)->where('source_id',$sourceId)->lockForUpdate()->first(); if(!$row)return;
        $this->event($row->id,'transition',$row->status,$status,$message,$actor,$private);
        DB::table('hr_request_index')->where('id',$row->id)->update(['status'=>$status,'current_owner_staff_id'=>$owner,'result_type'=>$resultType,'result_id'=>$resultId,'closed_at'=>in_array($status,['approved','rejected','cancelled','completed','closed'],true)?now():null,'updated_at'=>now()]);
    }
    private function event(string$id,string$type,?string$from,string$to,string$message,string$actor,array$private):void{DB::table('hr_request_events')->insert(['id'=>(string)Str::uuid(),'request_index_id'=>$id,'event_type'=>$type,'from_status'=>$from,'to_status'=>$to,'visible_to_employee_message'=>$message,'private_snapshot'=>json_encode($private,JSON_THROW_ON_ERROR),'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
}
