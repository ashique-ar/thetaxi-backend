<?php
namespace App\Models\Hr;
use App\Models\NonSoftDeletableModel;
use Spatie\Activitylog\Support\LogOptions;
class HrEmployeeTimelineEvent extends NonSoftDeletableModel { protected $fillable=['staff_id','employment_spell_id','domain','event_type','source_type','source_id','title','safe_summary','confidentiality','effective_at','recorded_at','idempotency_key']; protected $casts=['safe_summary'=>'array','effective_at'=>'datetime','recorded_at'=>'datetime']; public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logOnly(['domain','event_type','confidentiality'])->logOnlyDirty()->dontLogEmptyChanges()->dontLogIfAttributesChangedOnly(['updated_at','created_at'])->useLogName('HrEmployeeTimelineEvent'); } protected static function booted():void{static::updating(fn()=>throw new \LogicException('HR timeline events are immutable.'));static::deleting(fn()=>throw new \LogicException('HR timeline events cannot be deleted.'));} }
