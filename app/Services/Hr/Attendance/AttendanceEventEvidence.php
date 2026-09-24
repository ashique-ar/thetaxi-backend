<?php

namespace App\Services\Hr\Attendance;

final class AttendanceEventEvidence
{
    private const RETAINED_FIELDS = [
        'provider_event_id',
        'device_serial',
        'provider_person_id',
        'employee_number',
        'occurred_at',
        'source_timezone',
        'source_utc_offset_minutes',
        'event_kind',
        'direction',
        'authentication_method',
        'verification_result',
    ];

    public static function minimalPayload(array $event): array
    {
        return array_intersect_key($event, array_flip(self::RETAINED_FIELDS));
    }
}
