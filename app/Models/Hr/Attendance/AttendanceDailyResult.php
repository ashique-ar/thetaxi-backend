<?php

namespace App\Models\Hr\Attendance;

use App\Models\BaseModel;
use LogicException;

class AttendanceDailyResult extends BaseModel
{
    protected $table = 'hr_attendance_daily_results';
    protected $guarded = [];
    protected $casts = ['work_date' => 'date', 'scheduled_start_at' => 'immutable_datetime', 'scheduled_end_at' => 'immutable_datetime', 'first_in_at' => 'immutable_datetime', 'last_out_at' => 'immutable_datetime', 'calculated_at' => 'immutable_datetime', 'rule_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Attendance result versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Attendance result versions cannot be deleted.'));
    }
}
