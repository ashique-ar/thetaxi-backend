<?php

it('requires a real driver assignment and prevents review-state bypass through CRUD', function (): void {
    $root = dirname(__DIR__, 2);
    $createRequest = file_get_contents($root . '/app/Http/Requests/Driver/DriverLog/CreateDriverLogRequest.php');
    $updateRequest = file_get_contents($root . '/app/Http/Requests/Driver/DriverLog/UpdateDriverLogRequest.php');
    $controller = file_get_contents($root . '/app/Http/Controllers/Api/Driver/DriverLogController.php');
    $migration = file_get_contents($root . '/database/migrations/2026_07_22_000009_add_assignment_audit_to_driver_logs.php');
    $resource = file_get_contents($root . '/app/Http/Resources/Driver/DriverLogResource.php');

    expect($createRequest)->toContain("'driver_id' => ['required', 'exists:drivers,id']")
        ->and($createRequest)->toContain("'status' => ['prohibited']")
        ->and($updateRequest)->toContain("'status' => ['prohibited']")
        ->and($controller)->toContain("\$data['status'] = 'draft'")
        ->and($controller)->toContain("'driver_id' => 'required|exists:drivers,id'")
        ->and($controller)->toContain("abort_unless(in_array(\$driverLog->status, ['draft', 'rejected'], true)")
        ->and($migration)->toContain("foreignUuid('assigned_by')")
        ->and($migration)->toContain("timestamp('assigned_at')")
        ->and($controller)->toContain("'assigned_by' => \$request->user()?->id")
        ->and($controller)->toContain("'assigned_at' => now()")
        ->and($resource)->toContain("'assigned_by' => \$this->assigned_by")
        ->and($resource)->toContain("'assigned_at' => \$this->assigned_at");
});
