<?php

it('locks and rechecks queued notifications before processing their payload', function () {
    $source = file_get_contents(app_path('Console/Commands/ProcessHrNotifications.php'));
    $lock = strpos($source, '->lockForUpdate()');
    $queuedCheck = strpos($source, "->where('status', 'queued')", strpos($source, 'DB::transaction(function () use ($candidate, $notifications)'));
    $decrypt = strpos($source, 'decrypt($outbox->encrypted_rendered_payload)');

    expect(str_contains($source, 'DB::transaction(function () use ($candidate, $notifications)'))->toBeTrue()
        ->and(is_int($queuedCheck) && $queuedCheck < $lock)->toBeTrue()
        ->and(is_int($decrypt) && $decrypt > $lock)->toBeTrue();
});
