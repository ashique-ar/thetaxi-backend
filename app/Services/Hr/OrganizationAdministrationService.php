<?php

namespace App\Services\Hr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationAdministrationService
{
    public function createUnit(array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($data, $companyId, $actorUserId) {
            abort_unless(DB::table('companies')->where('id', $companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $payload = $this->unitPayload($data, $companyId);
            $checksum = $this->checksum(['command'=>'create_unit','payload'=>$payload,'reason'=>$data['reason']]);
            if ($replay = $this->replay($data['idempotency_key'], $checksum, $companyId, 'organization_unit')) return $replay;
            $this->assertParent($payload['parent_id'] ?? null, $companyId);

            $id = (string) Str::uuid();
            $row = $payload + [
                'id'=>$id, 'status'=>'active', 'version'=>1, 'created_user_id'=>$actorUserId,
                'created_at'=>now(), 'updated_at'=>now(),
            ];
            DB::table('hr_organization_units')->insert($this->json($row, ['custom_fields']));
            $snapshot = $this->snapshot('hr_organization_units', $id);
            $this->event($companyId, 'organization_unit', $id, 'created', 1, null, $snapshot, $data['reason'], $actorUserId, $data['idempotency_key'], $checksum);

            return $snapshot;
        });
    }

    public function updateUnit(string $unitId, array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($unitId, $data, $companyId, $actorUserId) {
            abort_unless(DB::table('companies')->where('id', $companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $row = DB::table('hr_organization_units')->where('id',$unitId)->where('company_id',$companyId)->lockForUpdate()->first();
            abort_unless($row, 404, 'Organization unit was not found in your legal entity.');
            $payload = $this->unitPayload($data, $companyId, true);
            $checksum = $this->checksum(['unit_id'=>$unitId,'expected_version'=>$data['expected_version'],'payload'=>$payload,'reason'=>$data['reason']]);
            if ($replay = $this->replay($data['idempotency_key'], $checksum, $companyId, 'organization_unit')) return $replay;
            abort_unless((int)$row->version===(int)$data['expected_version'],409,'Organization unit version is stale.');
            $this->assertParent($payload['parent_id'] ?? $row->parent_id, $companyId, $unitId);
            $this->assertUnitCoversDependents($unitId, $payload);
            $before=$this->decodeRow($row);
            $version=(int)$row->version+1;
            $changes=$this->json($payload+['version'=>$version,'updated_user_id'=>$actorUserId,'updated_at'=>now()],['custom_fields']);
            DB::table('hr_organization_units')->where('id',$unitId)->update($changes);
            $after=$this->snapshot('hr_organization_units',$unitId);
            $this->event($companyId,'organization_unit',$unitId,'updated',$version,$before,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return $after;
        });
    }

    public function createDefinition(array $data, string $companyId, string $actorUserId): array
    {
        return DB::transaction(function () use ($data,$companyId,$actorUserId) {
            abort_unless(DB::table('companies')->where('id',$companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $payload=$this->definitionPayload($data,$companyId);
            $checksum=$this->checksum(['command'=>'create_definition','payload'=>$payload,'reason'=>$data['reason']]);
            if($replay=$this->replay($data['idempotency_key'],$checksum,$companyId,'custom_field_definition'))return$replay;
            $id=(string)Str::uuid();
            DB::table('hr_custom_field_definitions')->insert($this->json($payload+['id'=>$id,'version'=>1,'active'=>true,'updated_user_id'=>$actorUserId,'created_at'=>now(),'updated_at'=>now()],['validation_rules']));
            $after=$this->snapshot('hr_custom_field_definitions',$id);
            $this->event($companyId,'custom_field_definition',$id,'created',1,null,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return$after;
        });
    }

    public function updateDefinition(string $definitionId,array $data,string $companyId,string $actorUserId):array
    {
        return DB::transaction(function()use($definitionId,$data,$companyId,$actorUserId){
            abort_unless(DB::table('companies')->where('id',$companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $row=DB::table('hr_custom_field_definitions')->where('id',$definitionId)->where('company_id',$companyId)->lockForUpdate()->first();
            abort_unless($row,404,'Custom-field definition was not found in your legal entity.');
            $payload=$this->definitionPayload($data,$companyId,true);
            $checksum=$this->checksum(['definition_id'=>$definitionId,'expected_version'=>$data['expected_version'],'payload'=>$payload,'reason'=>$data['reason']]);
            if($replay=$this->replay($data['idempotency_key'],$checksum,$companyId,'custom_field_definition'))return$replay;
            abort_unless((int)$row->version===(int)$data['expected_version'],409,'Custom-field definition version is stale.');
            abort_unless($payload['applies_to']===$row->applies_to&&$payload['field_key']===$row->field_key&&$payload['data_type']===$row->data_type,422,'Custom-field owner, key and data type are immutable; create a new definition instead.');
            $before=$this->decodeRow($row);$version=(int)$row->version+1;
            DB::table('hr_custom_field_definitions')->where('id',$definitionId)->update($this->json($payload+['version'=>$version,'updated_user_id'=>$actorUserId,'updated_at'=>now()],['validation_rules']));
            $after=$this->snapshot('hr_custom_field_definitions',$definitionId);
            $this->event($companyId,'custom_field_definition',$definitionId,'updated',$version,$before,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return$after;
        });
    }

    public function createPayrollGroup(array $data,string $companyId,string $actorUserId):array
    {
        return DB::transaction(function()use($data,$companyId,$actorUserId){
            abort_unless(DB::table('companies')->where('id',$companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $payload=$this->payrollGroupPayload($data,$companyId);
            $checksum=$this->checksum(['command'=>'create_payroll_group','payload'=>$payload,'reason'=>$data['reason']]);
            if($replay=$this->replay($data['idempotency_key'],$checksum,$companyId,'payroll_group'))return$replay;
            abort_if(DB::table('hr_payroll_groups')->where('company_id',$companyId)->where('code',$payload['code'])->exists(),409,'A payroll group with this code already exists in your legal entity.');
            $id=(string)Str::uuid();
            DB::table('hr_payroll_groups')->insert($payload+['id'=>$id,'status'=>'active','version'=>1,'created_user_id'=>$actorUserId,'created_at'=>now(),'updated_at'=>now()]);
            $after=$this->snapshot('hr_payroll_groups',$id);
            $this->event($companyId,'payroll_group',$id,'created',1,null,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return$after;
        });
    }

    public function updatePayrollGroup(string $groupId,array $data,string $companyId,string $actorUserId):array
    {
        return DB::transaction(function()use($groupId,$data,$companyId,$actorUserId){
            abort_unless(DB::table('companies')->where('id',$companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $row=DB::table('hr_payroll_groups')->where('id',$groupId)->where('company_id',$companyId)->lockForUpdate()->first();
            abort_unless($row,404,'Payroll group was not found in your legal entity.');
            $payload=$this->payrollGroupPayload($data,$companyId,true);
            $checksum=$this->checksum(['group_id'=>$groupId,'expected_version'=>$data['expected_version'],'payload'=>$payload,'reason'=>$data['reason']]);
            if($replay=$this->replay($data['idempotency_key'],$checksum,$companyId,'payroll_group'))return$replay;
            abort_unless((int)$row->version===(int)$data['expected_version'],409,'Payroll group version is stale.');
            $before=$this->decodeRow($row);$version=(int)$row->version+1;
            DB::table('hr_payroll_groups')->where('id',$groupId)->update($payload+['version'=>$version,'updated_user_id'=>$actorUserId,'updated_at'=>now()]);
            $after=$this->snapshot('hr_payroll_groups',$groupId);
            $this->event($companyId,'payroll_group',$groupId,'updated',$version,$before,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return$after;
        });
    }

    private function payrollGroupPayload(array $data,string $companyId,bool $partial=false):array
    {
        $keys=['code','name','pay_frequency','description','status','effective_from','effective_until'];
        $payload=array_intersect_key($data,array_flip($keys));
        if(!$partial)$payload=['company_id'=>$companyId]+$payload;
        return$payload;
    }

    public function createDocumentType(array $data,string $companyId,string $actorUserId):array
    {
        return DB::transaction(function()use($data,$companyId,$actorUserId){
            abort_unless(DB::table('companies')->where('id',$companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $payload=$this->documentTypePayload($data,$companyId);
            $checksum=$this->checksum(['command'=>'create_document_type','payload'=>$payload,'reason'=>$data['reason']]);
            if($replay=$this->replay($data['idempotency_key'],$checksum,$companyId,'document_type'))return$replay;
            abort_if(DB::table('hr_document_types')->where('company_id',$companyId)->where('code',$payload['code'])->exists(),409,'A document type with this code already exists in your legal entity.');
            $id=(string)Str::uuid();
            DB::table('hr_document_types')->insert($this->json($payload,['required_for_staff_types','required_for_employment_types'])+['id'=>$id,'status'=>'active','version'=>1,'created_user_id'=>$actorUserId,'created_at'=>now(),'updated_at'=>now()]);
            $after=$this->snapshot('hr_document_types',$id);
            $this->event($companyId,'document_type',$id,'created',1,null,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return$after;
        });
    }

    public function updateDocumentType(string $typeId,array $data,string $companyId,string $actorUserId):array
    {
        return DB::transaction(function()use($typeId,$data,$companyId,$actorUserId){
            abort_unless(DB::table('companies')->where('id',$companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $row=DB::table('hr_document_types')->where('id',$typeId)->where('company_id',$companyId)->lockForUpdate()->first();
            abort_unless($row,404,'Document type was not found in your legal entity.');
            $payload=$this->documentTypePayload($data,$companyId,true);
            $checksum=$this->checksum(['type_id'=>$typeId,'expected_version'=>$data['expected_version'],'payload'=>$payload,'reason'=>$data['reason']]);
            if($replay=$this->replay($data['idempotency_key'],$checksum,$companyId,'document_type'))return$replay;
            abort_unless((int)$row->version===(int)$data['expected_version'],409,'Document type version is stale.');
            $before=$this->decodeRow($row);$version=(int)$row->version+1;
            DB::table('hr_document_types')->where('id',$typeId)->update($this->json($payload,['required_for_staff_types','required_for_employment_types'])+['version'=>$version,'updated_user_id'=>$actorUserId,'updated_at'=>now()]);
            $after=$this->snapshot('hr_document_types',$typeId);
            $this->event($companyId,'document_type',$typeId,'updated',$version,$before,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return$after;
        });
    }

    private function documentTypePayload(array $data,string $companyId,bool $partial=false):array
    {
        $keys=['code','name','category','required_for_staff_types','required_for_employment_types','requires_expiry','renewal_reminder_days','status','effective_from','effective_until'];
        $payload=array_intersect_key($data,array_flip($keys));
        if(!$partial)$payload=['company_id'=>$companyId]+$payload;
        return$payload;
    }

    /**
     * §5.1: "work calendars, timezones, weekly rest days, location holidays."
     * `hr_work_calendars`/`hr_work_calendar_days` already exist and are
     * already consumed by both the Attendance daily engine and
     * `LeaveWorkflowService`'s sandwich-rule day resolution — but the only
     * existing write path (`AttendanceResultController::storeCalendar`) is
     * gated behind the separate `hr.features.attendance_results` flag, which
     * leaves a legal entity that enables `leave_overtime` without
     * `attendance_results` unable to define the holidays Leave depends on.
     * This governs calendar/day creation as a People-Core-owned register
     * (create-only, matching the existing endpoint's own create-only shape)
     * without altering the Attendance engine's read contract or its existing
     * endpoint.
     */
    public function createWorkCalendar(array $data,string $companyId,string $actorUserId):array
    {
        return DB::transaction(function()use($data,$companyId,$actorUserId){
            abort_unless(DB::table('companies')->where('id',$companyId)->lockForUpdate()->first(),404,'Legal entity was not found.');
            $payload=['company_id'=>$companyId,'code'=>$data['code'],'name'=>$data['name'],'timezone'=>$data['timezone'],'weekly_working_days'=>$data['weekly_working_days'],'effective_from'=>$data['effective_from'],'effective_until'=>$data['effective_until']??null];
            $checksum=$this->checksum(['command'=>'create_work_calendar','payload'=>$payload,'reason'=>$data['reason']]);
            if($replay=$this->replay($data['idempotency_key'],$checksum,$companyId,'work_calendar'))return$replay;
            abort_if(DB::table('hr_work_calendars')->where('company_id',$companyId)->where('code',$payload['code'])->where('effective_from',$payload['effective_from'])->exists(),409,'A work calendar with this code and effective date already exists in your legal entity.');
            $id=(string)Str::uuid();
            DB::table('hr_work_calendars')->insert($this->json($payload,['weekly_working_days'])+['id'=>$id,'status'=>'active','created_by'=>$actorUserId,'created_at'=>now(),'updated_at'=>now()]);
            $after=$this->snapshot('hr_work_calendars',$id);
            $this->event($companyId,'work_calendar',$id,'created',1,null,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return$after;
        });
    }

    public function createWorkCalendarDay(string $calendarId,array $data,string $companyId,string $actorUserId):array
    {
        return DB::transaction(function()use($calendarId,$data,$companyId,$actorUserId){
            $calendar=DB::table('hr_work_calendars')->where('id',$calendarId)->where('company_id',$companyId)->lockForUpdate()->first();
            abort_unless($calendar,404,'Work calendar was not found in your legal entity.');
            $payload=['calendar_date'=>$data['calendar_date'],'day_type'=>$data['day_type'],'name'=>$data['name']??null,'paid'=>$data['paid']];
            $checksum=$this->checksum(['command'=>'create_work_calendar_day','calendar_id'=>$calendarId,'payload'=>$payload,'reason'=>$data['reason']]);
            if($replay=$this->replay($data['idempotency_key'],$checksum,$companyId,'work_calendar_day'))return$replay;
            abort_if(DB::table('hr_work_calendar_days')->where('calendar_id',$calendarId)->whereDate('calendar_date',$payload['calendar_date'])->exists(),409,'This calendar already has a day entry for that date.');
            $id=(string)Str::uuid();
            DB::table('hr_work_calendar_days')->insert($payload+['id'=>$id,'calendar_id'=>$calendarId,'created_at'=>now(),'updated_at'=>now()]);
            $after=$this->decodeRow(DB::table('hr_work_calendar_days')->where('id',$id)->first());
            $this->event($companyId,'work_calendar_day',$id,'created',1,null,$after,$data['reason'],$actorUserId,$data['idempotency_key'],$checksum);
            return$after;
        });
    }

    private function unitPayload(array $data,string $companyId,bool $partial=false):array
    {
        $keys=['parent_id','unit_type','code','name','manager_staff_id','hr_partner_staff_id','timezone','status','effective_from','effective_until','custom_fields'];
        $payload=array_intersect_key($data,array_flip($keys));
        if(!$partial)$payload=['company_id'=>$companyId]+$payload;
        return$payload;
    }

    private function definitionPayload(array $data,string $companyId,bool $partial=false):array
    {
        $keys=['applies_to','field_key','label','data_type','validation_rules','confidentiality','required','active'];
        $payload=array_intersect_key($data,array_flip($keys));
        if(!$partial)$payload=['company_id'=>$companyId]+$payload;
        return$payload;
    }

    private function assertParent(?string $parentId,string $companyId,?string $unitId=null):void
    {
        if(!$parentId)return;
        $seen=[];$cursor=$parentId;
        while($cursor){
            abort_if($cursor===$unitId,422,'Organization hierarchy cannot contain a cycle.');
            abort_if(isset($seen[$cursor]),409,'Existing organization hierarchy contains a cycle.');
            $seen[$cursor]=true;
            $parent=DB::table('hr_organization_units')->where('id',$cursor)->where('company_id',$companyId)->first(['parent_id']);
            abort_unless($parent,422,'Parent organization unit must belong to your legal entity.');
            $cursor=$parent->parent_id;
        }
    }

    private function assertUnitCoversDependents(string $unitId,array $payload):void
    {
        foreach([['hr_organization_units','parent_id'],['hr_positions','organization_unit_id']]as[$table,$foreignKey]){
            $conflict=DB::table($table)->where($foreignKey,$unitId)->where('status','active')->where(function($query)use($payload){
                if($payload['status']==='inactive'){$query->whereRaw('1 = 1');return;}
                $query->where('effective_from','<',$payload['effective_from']);
                if(($payload['effective_until']??null)!==null)$query->orWhereNull('effective_until')->orWhere('effective_until','>',$payload['effective_until']);
            })->exists();
            abort_if($conflict,422,'The organization-unit interval or status must continue to cover its active child units and positions.');
        }
    }

    private function replay(string $key,string $checksum,string $companyId,string $aggregateType):?array
    {
        $event=DB::table('hr_organization_change_events')->where('idempotency_key',$key)->first();
        if(!$event)return null;
        abort_unless($event->company_id===$companyId,403,'Organization command replay is outside your legal entity.');
        abort_unless($event->aggregate_type===$aggregateType,409,'Idempotency key was reused for another organization command.');
        abort_unless(hash_equals($event->request_checksum,$checksum),409,'Idempotency key was reused with different organization facts.');
        return is_array($event->after_snapshot)?$event->after_snapshot:json_decode((string)$event->after_snapshot,true,512,JSON_THROW_ON_ERROR);
    }

    private function event(string $companyId,string $type,string $id,string $eventType,int $version,?array $before,array $after,string $reason,string $actor,string $key,string $checksum):void
    {
        DB::table('hr_organization_change_events')->insert(['id'=>(string)Str::uuid(),'company_id'=>$companyId,'aggregate_type'=>$type,'aggregate_id'=>$id,'event_type'=>$eventType,'aggregate_version'=>$version,'before_snapshot'=>$before?json_encode($before,JSON_THROW_ON_ERROR):null,'after_snapshot'=>json_encode($after,JSON_THROW_ON_ERROR),'reason'=>$reason,'actor_user_id'=>$actor,'idempotency_key'=>$key,'request_checksum'=>$checksum,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }

    private function snapshot(string $table,string $id):array{return$this->decodeRow(DB::table($table)->where('id',$id)->first());}
    private function decodeRow(object $row):array{$data=(array)$row;foreach(['custom_fields','validation_rules','required_for_staff_types','required_for_employment_types','weekly_working_days']as$key)if(isset($data[$key])&&is_string($data[$key]))$data[$key]=json_decode($data[$key],true,512,JSON_THROW_ON_ERROR);return$data;}
    private function json(array $data,array $keys):array{foreach($keys as$key)if(array_key_exists($key,$data))$data[$key]=$data[$key]===null?null:json_encode($data[$key],JSON_THROW_ON_ERROR);return$data;}
    private function checksum(array $payload):string{return hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
}
