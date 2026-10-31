<?php

it('authorizes Sales evidence by subject and verifies private-file integrity', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $start = strpos($controller, 'public function download(');
    $end = strpos($controller, 'private function authorizeSubject(', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)
        ->toContain('$this->authorizeSubject($request, $row->subject_type, $row->subject_id, false, $row->uploaded_by)')
        ->toContain("(?:[0-9a-f-]{36}|sales)/\\d{4}/")
        ->toContain("\$row->disk === 'sales_private'")
        ->toContain("preg_match(\$pathPattern, (string) \$row->path) === 1")
        ->toContain("hash_equals((string) \$row->file_checksum, \$storedChecksum)")
        ->toContain("\$disk->download(\$row->path, \$row->file_name")
        ->not->toContain('company_id', 'CompanyIdScope', 'actorCompanyIds');
});

it('stores private Sales evidence with installation-wide paths and removes evidence tenant metadata', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $migration = file_get_contents(database_path('migrations/2026_10_30_000084_remove_evidence_file_tenant_company.php'));
    $start = strpos($controller, 'public function store(');
    $end = strpos($controller, 'public function download(', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)
        ->toContain("\$path = 'sales/'.now()->format('Y/m').'/'.\$storedName")
        ->toContain("\$disk->putFileAs(dirname(\$path), \$file, basename(\$path)) !== false")
        ->toContain('hash_equals($checksum, $storedChecksum)')
        ->toContain("'disk' => 'sales_private'")
        ->not->toContain("'company_id' =>")
        ->and($migration)
        ->toContain("\$tableName = 'domain_evidence_files'")
        ->toContain("dropColumn('company_id')")
        ->toContain('Evidence files cannot regain tenant company scope.');
});
