<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
class HrEmployeeNumberSequence extends BaseModel { protected $fillable=['company_id','template','prefix','suffix','start_value','next_value','padding','status','version','updated_user_id']; protected $casts=['start_value'=>'integer','next_value'=>'integer','padding'=>'integer','version'=>'integer']; }
