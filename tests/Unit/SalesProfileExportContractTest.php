<?php

it('gates the Sales Profile roster export/download behind permission and profile authorization', function () {
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

it('freezes the installation-wide Sales Profile export row scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));
    $service = file_get_contents(app_path('Services/Sales/SalesProfileExportService.php'));
    $exportMethod = substr($controller, strpos($controller, 'public function export('), strpos($controller, 'public function downloadExport(') - strpos($controller, 'public function export('));

    expect($controller)
        ->toContain('scopeType(')
        ->and($exportMethod)->not->toContain('company_id', 'resolveCompanyId')
        ->and($service)
        ->toContain("'scope_profile_ids' => \$profileIds")
        ->toContain("'scope_checksum' => \$scopeChecksum")
        ->toContain("'request_checksum' => \$requestChecksum")
        ->not->toContain('$companyFilter');
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
