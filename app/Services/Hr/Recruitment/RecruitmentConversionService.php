<?php

namespace App\Services\Hr\Recruitment;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecruitmentConversionService
{
    public function complete(?string$applicationId,Staff$staff,string$actor):void
    {
        if(!$applicationId)return;abort_unless(config('hr.features.employee_self_service',false),409,'HR lifecycle writes are not enabled.');$application=DB::table('hr_candidate_applications')->where('id',$applicationId)->lockForUpdate()->first();abort_unless($application,404,'Recruitment application not found.');abort_unless($application->company_id===$staff->company_id,422,'Application and Staff legal entities must match.');abort_unless($application->status==='offer_accepted'&&!$application->converted_staff_id,409,'Only an unconverted accepted offer may create Staff.');$offer=DB::table('hr_candidate_offers')->where('application_id',$applicationId)->where('status','accepted')->latest('version')->first();abort_unless($offer,409,'An accepted approved offer is required.');DB::table('hr_candidate_applications')->where('id',$applicationId)->update(['stage'=>'hired','status'=>'converted','converted_staff_id'=>$staff->id,'converted_at'=>now(),'updated_at'=>now()]);DB::table('hr_candidate_application_events')->insert(['id'=>(string)Str::uuid(),'application_id'=>$applicationId,'event_type'=>'converted_to_staff','from_stage'=>$application->stage,'to_stage'=>'hired','reason'=>'Canonical Staff created from accepted offer.','snapshot'=>json_encode(['staff_id'=>$staff->id,'offer_id'=>$offer->id],JSON_THROW_ON_ERROR),'actor_user_id'=>$actor,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }
}
