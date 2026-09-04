<?php

namespace App\Services\Hr\Attendance;

use App\Models\Hr\Attendance\AttendanceConnector;
use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Hr\Attendance\AttendanceRawEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

class AttendanceIngestionService
{
    public function ingest(array $headers,string $rawBody,array $events,?string $sourceIp):array
    {
        abort_unless(config('hr.features.attendance_ingestion',false),409,'HR attendance ingestion is not enabled.');
        $connector=AttendanceConnector::query()->where('connector_key',$headers['connector_key'])->where('status','active')->firstOrFail();
        $this->verify($connector,$headers,$rawBody,$sourceIp);
        $checksum=hash('sha256',$rawBody);
        if($existing=DB::table('hr_attendance_ingestion_requests')->where('request_id',$headers['request_id'])->first()){
            abort_unless(hash_equals($existing->payload_checksum,$checksum),409,'Ingestion request ID was reused with a different payload.');
            return ['request_id'=>$existing->request_id,'status'=>$existing->status,'idempotent_replay'=>true];
        }
        abort_if(DB::table('hr_attendance_ingestion_requests')->where('nonce',$headers['nonce'])->exists(),409,'Attendance ingestion nonce was already used.');
        return DB::transaction(function()use($connector,$headers,$events,$sourceIp,$checksum){
            $requestId=(string)Str::uuid();DB::table('hr_attendance_ingestion_requests')->insert(['id'=>$requestId,'connector_id'=>$connector->id,'request_id'=>$headers['request_id'],'nonce'=>$headers['nonce'],'signed_at'=>CarbonImmutable::parse($headers['signed_at']),'received_at'=>now(),'payload_checksum'=>$checksum,'event_count'=>count($events),'status'=>'processing','source_ip'=>$sourceIp,'created_at'=>now(),'updated_at'=>now()]);
            $counts=['created'=>0,'duplicate'=>0,'quarantined'=>0];
            foreach($events as$event)$this->ingestEvent($connector,$requestId,$event,$counts);
            DB::table('hr_attendance_ingestion_requests')->where('id',$requestId)->update(['status'=>'accepted','updated_at'=>now()]);
            $connector->update(['last_heartbeat_at'=>now()]);
            return ['request_id'=>$headers['request_id'],'status'=>'accepted','counts'=>$counts,'idempotent_replay'=>false];
        });
    }

    private function ingestEvent(AttendanceConnector $connector,string $requestId,array $event,array &$counts):void
    {
        $device=AttendanceDevice::query()->where('connector_id',$connector->id)->where('serial_number',$event['device_serial'])->first();
        $payloadChecksum=hash('sha256',json_encode($event,JSON_UNESCAPED_SLASHES));
        $existing=AttendanceRawEvent::query()->where('connector_id',$connector->id)->where('provider_event_id',$event['provider_event_id'])->first();
        if($existing){abort_unless(hash_equals($existing->payload_checksum,$payloadChecksum),409,'Provider event ID was reused with different evidence.');$counts['duplicate']++;return;}
        $occurred=CarbonImmutable::parse($event['occurred_at']);
        $sourceOffset=(int)($occurred->setTimezone($event['source_timezone'])->utcOffset()/60);
        abort_unless($sourceOffset===(int)$event['source_utc_offset_minutes'],422,'Attendance event timezone and UTC offset evidence do not agree.');
        $occurredDate=$occurred->setTimezone($event['source_timezone'])->toDateString();
        $mappingQuery=DB::table('hr_attendance_person_mappings')->where('company_id',$connector->company_id)->where('provider_person_id',$event['provider_person_id'])->where('enrollment_status','verified')->whereDate('effective_from','<=',$occurredDate)->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>',$occurredDate));
        if($device)$mappingQuery->where(fn($q)=>$q->where('device_id',$device->id)->orWhereNull('device_id'));else$mappingQuery->whereNull('device_id');
        $mappings=$mappingQuery->get();$mapping=$mappings->count()===1?$mappings->first():null;
        $reason=!$device?'device_unknown':($mappings->isEmpty()?'person_unmapped':($mappings->count()>1?'person_mapping_ambiguous':null));
        $resolvedMapping=$reason?null:$mapping;
        $raw=AttendanceRawEvent::create(['company_id'=>$connector->company_id,'connector_id'=>$connector->id,'device_id'=>$device?->id,'ingestion_request_id'=>$requestId,'staff_id'=>$resolvedMapping?->staff_id,'person_mapping_id'=>$resolvedMapping?->id,'provider_event_id'=>$event['provider_event_id'],'provider_person_id'=>$event['provider_person_id'],'employee_number'=>$event['employee_number']??null,'occurred_at'=>$occurred,'source_timezone'=>$event['source_timezone'],'source_utc_offset_minutes'=>$event['source_utc_offset_minutes'],'event_kind'=>$event['event_kind'],'direction'=>$event['direction']??null,'authentication_method'=>$event['authentication_method']??null,'verification_result'=>$event['verification_result']??null,'encrypted_raw_payload'=>$event,'payload_checksum'=>$payloadChecksum,'mapping_status'=>$reason?'quarantined':'mapped','received_at'=>now()]);
        if($reason){DB::table('hr_attendance_quarantine_items')->insert(['id'=>(string)Str::uuid(),'company_id'=>$connector->company_id,'raw_event_id'=>$raw->id,'reason_code'=>$reason,'details'=>'Raw event requires reviewed mapping resolution.','status'=>'open','created_at'=>now(),'updated_at'=>now()]);$counts['quarantined']++;}else$counts['created']++;
        if($device)$device->update(['last_event_at'=>now()]);
    }

    private function verify(AttendanceConnector $connector,array $headers,string $rawBody,?string $sourceIp):void
    {
        $signedAt=CarbonImmutable::parse($headers['signed_at']);abort_if(abs(now()->diffInSeconds($signedAt,false))>300,401,'Attendance ingestion signature expired.');
        $expected=hash_hmac('sha256',$headers['signed_at'].'.'.$headers['nonce'].'.'.$rawBody,$connector->signing_secret);abort_unless(hash_equals($expected,strtolower($headers['signature'])),401,'Attendance ingestion signature is invalid.');
        if($connector->allowed_ip_cidrs){$allowed=collect(explode(',',$connector->allowed_ip_cidrs))->map(fn($ip)=>trim($ip))->filter()->all();abort_unless($sourceIp&&IpUtils::checkIp($sourceIp,$allowed),403,'Attendance connector source IP is not allowed.');}
    }
}
