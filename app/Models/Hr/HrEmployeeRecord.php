<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
class HrEmployeeRecord extends BaseModel { protected $fillable=['staff_id','employment_spell_id','record_type','title','encrypted_data','effective_date','expiry_date','verification_status','verified_by','confidentiality','source','employee_submitted','supersedes_id','evidence_file_id']; protected $hidden=['encrypted_data']; protected $casts=['encrypted_data'=>'encrypted:array','effective_date'=>'date','expiry_date'=>'date','employee_submitted'=>'boolean']; }
