<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
class HrEmploymentSpell extends BaseModel { protected $fillable=['staff_id','company_id','employment_type_id','spell_number','joined_at','service_date','confirmation_date','rehire_date','last_working_date','terminated_at','termination_reason_code','termination_reason','status','gratuity_qualified','gratuity_service_start','gratuity_service_decision','prior_service_decisions','created_user_id']; protected $casts=['joined_at'=>'date','service_date'=>'date','confirmation_date'=>'date','rehire_date'=>'date','last_working_date'=>'date','terminated_at'=>'date','gratuity_service_start'=>'date','gratuity_qualified'=>'boolean','prior_service_decisions'=>'array']; public function assignments():HasMany{return $this->hasMany(HrEmploymentAssignment::class,'employment_spell_id');} }
