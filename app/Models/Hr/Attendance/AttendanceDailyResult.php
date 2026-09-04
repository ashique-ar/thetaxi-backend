<?php

namespace App\Models\Hr\Attendance;

use App\Models\BaseModel;
use LogicException;

class AttendanceDailyResult extends BaseModel
{
    protected $table = 'hr_attendance_daily_results';
    protected $fillable = ['company_id', 'staff_id', 'work_date', 'result_version', 'supersedes_id', 'roster_assignment_id', 'period_id', 'day_status', 'scheduled_start_at', 'scheduled_end_at', 'first_in_at', 'last_out_at', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'payable_minutes', 'source_kind', 'calculated_at', 'calculated_by', 'input_checksum', 'result_checksum', 'rule_snapshot'];
    protected $casts = ['work_date' => 'date', 'scheduled_start_at' => 'immutable_datetime', 'scheduled_end_at' => 'immutable_datetime', 'first_in_at' => 'immutable_datetime', 'last_out_at' => 'immutable_datetime', 'calculated_at' => 'immutable_datetime', 'rule_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn() => throw new LogicException('Attendance result versions are immutable.'));
        static::deleting(fn() => throw new LogicException('Attendance result versions cannot be deleted.'));
    }
}
