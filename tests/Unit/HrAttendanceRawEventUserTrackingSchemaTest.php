<?php

it('keeps immutable raw attendance evidence compatible with BaseModel user tracking', function () {
    $model = file_get_contents(app_path('Models/Hr/Attendance/AttendanceRawEvent.php'));
    $migration = file_get_contents(database_path('migrations/2026_09_01_140000_add_raw_event_creator_tracking.php'));

    expect($model)->toContain('extends BaseModel')
        ->and($migration)->toContain("Schema::hasColumn('hr_attendance_raw_events', 'created_user_id')")
        ->toContain("foreignUuid('created_user_id')")
        ->toContain("constrained('users')")
        ->toContain('nullOnDelete()');
});
