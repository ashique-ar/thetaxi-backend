<?php

it('keeps statement export files immutable and replays only matching concurrent keys', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionStatementExportService.php'));

    expect($service)
        ->toContain('$exportId = (string) Str::uuid();', '$exportId.\'.\'.$format', "'id' => \$exportId", 'catch (Throwable $exception)')
        ->toContain('Storage::disk($disk)->delete($exportPath)', 'if (! $duplicate) throw $exception;')
        ->toContain("! DB::table('sales_commission_statement_exports')->where('path', \$exportPath)->exists()")
        ->toContain('$duplicate->statement_id === $statement->id && $duplicate->format === $format');
});

it('uses a readable Staff code in statement exports and omits raw record identifiers', function () {
    $view = file_get_contents(resource_path('views/sales/commission-statement.blade.php'));
    $service = file_get_contents(app_path('Services/Sales/CommissionStatementExportService.php'));
    $statement = file_get_contents(app_path('Models/Sales/SalesCommissionStatement.php'));

    expect($view)
        ->toContain('Staff code:', '$statement->staff?->code')
        ->not->toContain('$statement->staff_id', 'source_id', 'calculation_snapshot')
        ->and($service)->toContain("with(['lines', 'staff'])")
        ->and($statement)->toContain('function staff(): BelongsTo');
});

it('checks Staff and profile access before streaming a statement export', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionStatementController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $downloadStart = strpos($controller, 'public function downloadExport');
    $downloadEnd = strpos($controller, 'private function statementInput', $downloadStart);
    $download = substr($controller, $downloadStart, $downloadEnd - $downloadStart);

    expect($download)
        ->toContain('$this->applyScope($statement, $request)', '$statement = $statement->firstOrFail()',
            '$expectedPath =', 'preg_match($expectedPath, (string) $export->path)',
            '$disk->exists($export->path)', '$disk->get($export->path)',
            "hash_equals((string) \$export->file_checksum, hash('sha256', \$content))",
            'response()->streamDownload')
        ->and(strpos($download, '$this->applyScope($statement, $request)'))
        ->toBeLessThan(strpos($download, '$disk->exists($export->path)'))
        ->toBeLessThan(strpos($download, 'hash_equals((string) $export->file_checksum'))
        ->toBeLessThan(strpos($download, "'last_downloaded_by' => \$request->user()->id"))
        ->and(strpos($download, "hash_equals((string) \$export->file_checksum"))
        ->toBeLessThan(strpos($download, 'response()->streamDownload'))
        ->and($routes)->toContain("permission:sales.commission-statements.export");
});
