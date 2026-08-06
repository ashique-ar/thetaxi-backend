<?php

it('persists review audit fields and locks finalized log sheets', function (): void {
    $root = dirname(__DIR__, 2);
    $migration = file_get_contents($root . '/database/migrations/2026_07_22_000008_add_verification_audit_to_driver_logs.php');
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/Driver/DriverLogController.php');
    $resource = file_get_contents($root . '/app/Http/Resources/Driver/DriverLogResource.php');

    expect($migration)->toContain("text('verification_notes')")
        ->and($migration)->toContain("foreignUuid('verified_by')")
        ->and($migration)->toContain("timestamp('verified_at')")
        ->and($controller)->toContain("abort_unless(\$lockedLog->status === 'pending', 409, 'Only pending log sheets can be reviewed.')")
        ->and($controller)->toContain('DriverLog::query()->lockForUpdate()->findOrFail($driverLog->id)')
        ->and(substr_count($controller, "['draft', 'rejected']"))->toBeGreaterThanOrEqual(4)
        ->and($controller)->toContain("'verification_notes' => 'required|string|min:10|max:1000'")
        ->and($controller)->toContain("'verified_by' => \$request->user()?->id")
        ->and($controller)->toContain("'verified_at' => now()")
        ->and($resource)->toContain("'verification_notes' => \$this->verification_notes")
        ->and($resource)->toContain("'verified_by' => \$this->verified_by")
        ->and($resource)->toContain("'verified_at' => \$this->verified_at");
});
