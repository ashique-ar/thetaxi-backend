<?php

it('owns draft submission and rejected correction resubmission without a cancel bypass', function (): void {
    $root = dirname(__DIR__, 2);
    $migration = file_get_contents($root . '/database/migrations/2026_07_22_000010_add_submission_lifecycle_to_driver_logs.php');
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/Driver/DriverLogController.php');
    $routes = file_get_contents($root . '/routes/api.php');
    $resource = file_get_contents($root . '/app/Http/Resources/Driver/DriverLogResource.php');

    expect($migration)->toContain("'draft','pending','approved','rejected'")
        ->and($migration)->toContain("foreignUuid('submitted_by')")
        ->and($migration)->toContain("text('correction_notes')")
        ->and($migration)->toContain("unsignedInteger('revision_number')")
        ->and($controller)->toContain('Only draft or rejected log sheets can be submitted.')
        ->and($controller)->toContain("'required|string|min:10|max:1000'")
        ->and($controller)->toContain("'revision_number' => \$driverLog->revision_number + 1")
        ->and($routes)->toContain('logsheets/{driverLog}/submit')
        ->and($routes)->not->toContain('logsheets/{driverLog}/cancel')
        ->and($resource)->toContain("'correction_notes' => \$this->correction_notes")
        ->and($resource)->toContain("'revision_number' => \$this->revision_number");
});
