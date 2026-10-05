<?php

namespace App\Models\Hr\Leave;

use App\Models\NonSoftDeletableModel;
use LogicException;
use Spatie\Activitylog\Support\LogOptions;

class LeaveBalanceEntry extends NonSoftDeletableModel
{
    protected $table = 'hr_leave_balance_entries';
    protected $fillable = [
        'account_id', 'leave_request_id', 'entry_type', 'minutes', 'effective_date', 'expires_on',
        'source_type', 'source_id', 'reason', 'rule_snapshot', 'entry_checksum', 'posted_by', 'posted_at',
    ];
    protected $casts = ['effective_date' => 'date', 'expires_on' => 'date', 'posted_at' => 'immutable_datetime', 'rule_snapshot' => 'array'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['entry_type'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName('LeaveBalanceEntry');
    }

    protected static function booted():void{static::updating(fn()=>throw new LogicException('Leave balance entries are immutable.'));static::deleting(fn()=>throw new LogicException('Leave balance entries cannot be deleted.'));}
}
