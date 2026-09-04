<?php
namespace App\Models\Hr;
use App\Models\BaseModel;
class HrStaffProfileVersion extends BaseModel { protected $fillable=['staff_id','version','encrypted_profile','profile_checksum','change_reason','changed_by','effective_at']; protected $hidden=['encrypted_profile']; protected $casts=['encrypted_profile'=>'encrypted:array','effective_at'=>'datetime']; protected static function booted():void{static::updating(fn()=>throw new \LogicException('HR profile versions are immutable.'));static::deleting(fn()=>throw new \LogicException('HR profile versions cannot be deleted.'));} }
