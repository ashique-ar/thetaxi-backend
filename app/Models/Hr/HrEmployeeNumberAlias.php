<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
class HrEmployeeNumberAlias extends BaseModel { protected $fillable=['staff_id','company_id','employee_number','alias_type','is_canonical','effective_from','effective_until','reason','approved_by']; protected $casts=['is_canonical'=>'boolean','effective_from'=>'date','effective_until'=>'date']; }
