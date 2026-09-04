<?php

it('gates the Sales Profile roster export/download behind a deny-by-default permission and legal-entity scope', function () {
    $seeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));

    expect($seeder)
        ->toContain('sales.profiles.export')
        ->and($routes)
        ->toContain("Route::post('profiles/exports', [SalesProfileController::class, 'export'])->middleware('permission:sales.profiles.export')")
        ->toContain("Route::get('profile-exports/{export}/download', [SalesProfileController::class, 'downloadExport'])")
        ->and($controller)
        ->toContain('function export(Request $request, SalesProfileExportService $exports)')
        ->toContain('public function downloadExport(')
        ->toContain('SalesProfileExport $export')
        ->toContain('SalesProfileExportService $exports')
        ->toContain('scopeProfiles(')
        ->toContain("'sales.profiles.view-team'")
        ->toContain("'reason' => ['required'")
        ->toContain('$export->generated_by !== (string) $request->user()->id')
        ->toContain('scopeChecksum(');
});

it('requires an explicit company and freezes the evaluated row scope for every Sales Profile export', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesProfileExportService.php'));

    expect($controller)
        ->toContain("'company_id' => ['required', 'uuid', 'exists:companies,id']")
        ->toContain('scopeType(')
        ->and($service)
        ->toContain("'scope_profile_ids' => \$profileIds")
        ->toContain("'scope_checksum' => \$scopeChecksum")
        ->toContain("'request_checksum' => \$requestChecksum");
});

it('rejects an idempotency key reused across a different Sales Profile export scope instead of silently returning it', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesProfileExportService.php'));

    expect($service)
        ->toContain('hash_equals((string) $duplicate->request_checksum, $requestChecksum)')
        ->toContain("'actor_user_id' => \$actorUserId")
        ->toContain("Storage::disk('sales_private')");
});

it('purges expired private Profile exports on the scheduled governed retention path', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesProfileExportService.php'));
    $command = file_get_contents(app_path('Console/Commands/ExpireSalesProfileExports.php'));
    $schedule = file_get_contents(base_path('routes/console.php'));

    expect($service)
        ->toContain('function purgeExpired(): int')
        ->toContain("'event_type' => 'sales.profile_export.purged'")
        ->toContain("Storage::disk(\$locked->disk)->delete(\$locked->path)")
        ->and($command)
        ->toContain("'sales:expire-profile-exports'")
        ->and($schedule)
        ->toContain("Schedule::command('sales:expire-profile-exports')");
});
