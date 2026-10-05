<?php

it('guards rollback of SMS message company ownership after owned messages exist', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_04_000001_add_company_ownership_to_sms_messages.php'));

    expect($migration)
        ->toContain("foreignUuid('company_id')->nullable()")
        ->toContain("index(['company_id', 'created_at'], 'sms_messages_company_created_idx')")
        ->toContain("whereNotNull('company_id')->exists()")
        ->toContain('Cannot remove company ownership from retained SMS messages.');
});

it('guards rollback of SMS activity company ownership after owned activities exist', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_04_000002_add_company_ownership_to_booking_activities.php'));

    expect($migration)
        ->toContain("foreignUuid('company_id')->nullable()")
        ->toContain("index(['company_id', 'event_at'], 'booking_activities_company_event_idx')")
        ->toContain("whereNotNull('company_id')->exists()")
        ->toContain('Cannot remove company ownership from retained booking activities.');
});
