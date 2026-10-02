<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
class HrRehireCase extends BaseModel { protected $fillable=['staff_id','company_id','prior_spell_id','status','proposed_rehire_date','duplicate_match_snapshot','eligibility_snapshot','prior_service_decisions','access_reactivation_plan','benefit_statutory_review','prepared_by','approved_by','approved_at','new_spell_id','idempotency_key','request_payload_checksum']; protected $casts=['proposed_rehire_date'=>'date','duplicate_match_snapshot'=>'array','eligibility_snapshot'=>'array','prior_service_decisions'=>'array','access_reactivation_plan'=>'array','benefit_statutory_review'=>'array','approved_at'=>'datetime']; }
