<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
class HrEmployeeTimelineEvent extends BaseModel { protected $fillable=['staff_id','employment_spell_id','domain','event_type','source_type','source_id','title','safe_summary','confidentiality','effective_at','recorded_at','idempotency_key']; protected $casts=['safe_summary'=>'array','effective_at'=>'datetime','recorded_at'=>'datetime']; protected static function booted():void{static::updating(fn()=>throw new \LogicException('HR timeline events are immutable.'));static::deleting(fn()=>throw new \LogicException('HR timeline events cannot be deleted.'));} }
