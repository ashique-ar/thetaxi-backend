<?php
namespace App\Models\Hr;
use App\Models\NonSoftDeletableModel;
use Spatie\Activitylog\Support\LogOptions;
class HrStaffProfileVersion extends NonSoftDeletableModel { protected $fillable=['staff_id','version','encrypted_profile','profile_checksum','change_reason','changed_by','effective_at']; protected $hidden=['encrypted_profile']; protected $casts=['encrypted_profile'=>'encrypted:array','effective_at'=>'datetime']; public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logOnly(['version'])->logOnlyDirty()->dontLogEmptyChanges()->dontLogIfAttributesChangedOnly(['updated_at','created_at'])->useLogName('HrStaffProfileVersion'); } protected static function booted():void{static::updating(fn()=>throw new \LogicException('HR profile versions are immutable.'));static::deleting(fn()=>throw new \LogicException('HR profile versions cannot be deleted.'));} }
