<?php

namespace App\Services\Hr\Attendance;

use App\Models\User;

class AttendanceIngestionActivationGuard
{
    public function assertCanEnable(User $actor, string $type, mixed $value): void
    {
        if ($type !== 'feature_hr_attendance_ingestion_enabled'
            || ! (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false)) {
            return;
        }

        abort_unless($actor->can('hr.attendance.ingestion.manage'), 403,
            'A separate attendance ingestion activation permission is required.');
    }

    public function assertNotDeletable(string $type): void
    {
        abort_unless($type !== 'feature_hr_attendance_ingestion_enabled', 409,
            'The attendance ingestion flag cannot be deleted; set it to false instead.');
    }

    public function assertTypeCanChange(string $currentType, string $newType): void
    {
        abort_unless($currentType !== 'feature_hr_attendance_ingestion_enabled' || $newType === $currentType, 409,
            'The attendance ingestion flag type cannot be changed; set it to false instead.');
    }
}
