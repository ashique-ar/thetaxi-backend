<?php

namespace App\Models\Hr\Leave;

use App\Models\BaseModel;
use LogicException;

class LeaveBalanceEntry extends BaseModel
{
    protected $table='hr_leave_balance_entries'; protected $guarded=[]; protected $casts=['effective_date'=>'date','expires_on'=>'date','posted_at'=>'immutable_datetime','rule_snapshot'=>'array'];
    protected static function booted():void{static::updating(fn()=>throw new LogicException('Leave balance entries are immutable.'));static::deleting(fn()=>throw new LogicException('Leave balance entries cannot be deleted.'));}
}
