<?php

use App\Services\Hr\Attendance\AttendanceEventEvidence;

it('retains only attendance diagnostic facts from a provider event', function () {
    $event = [
        'provider_event_id' => 'event-17',
        'device_serial' => 'terminal-1',
        'provider_person_id' => 'person-4',
        'occurred_at' => '2026-09-24T08:00:00+05:30',
        'source_timezone' => 'Asia/Colombo',
        'source_utc_offset_minutes' => 330,
        'event_kind' => 'punch',
        'direction' => 'in',
        'face_image' => 'private-image',
        'fingerprint_template' => 'private-template',
        'provider_secret' => 'private-secret',
    ];

    expect(AttendanceEventEvidence::minimalPayload($event))
        ->toEqual([
            'provider_event_id' => 'event-17',
            'device_serial' => 'terminal-1',
            'provider_person_id' => 'person-4',
            'occurred_at' => '2026-09-24T08:00:00+05:30',
            'source_timezone' => 'Asia/Colombo',
            'source_utc_offset_minutes' => 330,
            'event_kind' => 'punch',
            'direction' => 'in',
        ]);
});

it('uses minimized evidence for both push and direct attendance writes', function () {
    $push = file_get_contents(app_path('Services/Hr/Attendance/AttendanceIngestionService.php'));
    $direct = file_get_contents(app_path('Services/Hr/Attendance/DirectAttendanceSyncService.php'));

    expect($push)->toContain("'encrypted_raw_payload'=>AttendanceEventEvidence::minimalPayload(\$event)")
        ->and($direct)->toContain("'encrypted_raw_payload'=>AttendanceEventEvidence::minimalPayload(\$event)");
});
