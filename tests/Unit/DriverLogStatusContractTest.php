<?php

it('keeps status changes behind the draft submission and review owners', function (): void {
    $root = dirname(__DIR__, 2);
    $migration = file_get_contents($root . '/database/migrations/2025_07_05_042457_create_driver_logs_table.php');
    $lifecycleMigration = file_get_contents($root . '/database/migrations/2026_07_22_000010_add_submission_lifecycle_to_driver_logs.php');
    $createRequest = file_get_contents($root . '/app/Http/Requests/Driver/DriverLog/CreateDriverLogRequest.php');
    $updateRequest = file_get_contents($root . '/app/Http/Requests/Driver/DriverLog/UpdateDriverLogRequest.php');
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/Driver/DriverLogController.php');

    expect($migration)->toContain("enum('status', ['pending','approved','rejected'])")
        ->and($createRequest)->toContain("'status' => ['prohibited']")
        ->and($updateRequest)->toContain("'status' => ['prohibited']")
        ->and($lifecycleMigration)->toContain("'draft','pending','approved','rejected'")
        ->and($controller)->toContain("\$data['status'] = 'draft'")
        ->and($controller)->toContain("'verification_status' => 'nullable|in:approved,rejected'")
        ->and($controller)->toContain("LOWER(CAST(log_code AS TEXT)) LIKE ?")
        ->and($controller)->toContain("LOWER(particulars) LIKE ?");
});
