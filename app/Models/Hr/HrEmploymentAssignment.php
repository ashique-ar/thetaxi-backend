<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
class HrEmploymentAssignment extends BaseModel { protected $fillable=['employment_spell_id','staff_id','company_id','position_id','organization_unit_id','manager_staff_id','dotted_line_manager_staff_id','hr_partner_staff_id','cost_centre_code','location_code','payroll_group_code','default_shift_code','work_pattern_code','assignment_type','effective_from','effective_until','change_reason','snapshot','approved_by']; protected $casts=['effective_from'=>'date','effective_until'=>'date','snapshot'=>'array']; }
