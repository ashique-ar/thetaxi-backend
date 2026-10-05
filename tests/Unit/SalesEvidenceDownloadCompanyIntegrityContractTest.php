<?php

it('fails closed when downloaded Sales evidence belongs to another company than its subject', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $start = strpos($controller, 'public function download(');
    $end = strpos($controller, 'private function authorizeSubject(', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)
        ->toContain('$companyId = $this->authorizeSubject($request, $row->subject_type, $row->subject_id, false, $row->uploaded_by)')
        ->toContain('hash_equals((string) $companyId, (string) $row->company_id)')
        ->toContain('abort_unless(hash_equals((string) $companyId, (string) $row->company_id), 404)')
        ->toContain("\$row->disk === 'sales_private'")
        ->toContain("preg_match(\$pathPattern, (string) \$row->path) === 1")
        ->toContain("hash_equals((string) \$row->file_checksum, \$storedChecksum)")
        ->toContain("\$disk->download(\$row->path, \$row->file_name")
        ->and(strpos($method, 'hash_equals((string) $companyId, (string) $row->company_id)'))
        ->toBeLessThan(strpos($method, "\$disk->download(\$row->path, \$row->file_name"));
});

it('stores only checksum-verified evidence on the private Sales disk before inserting metadata', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $start = strpos($controller, 'public function store(');
    $end = strpos($controller, 'public function download(', $start);
    $method = substr($controller, $start, $end - $start);

    expect($method)
        ->toContain('$file->guessExtension()')
        ->toContain("\$disk->putFileAs(dirname(\$path), \$file, basename(\$path)) !== false")
        ->toContain("hash_equals(\$checksum, \$storedChecksum)")
        ->toContain("'disk' => 'sales_private'")
        ->toContain("'path' => \$path")
        ->toContain("\$disk->delete(\$path)")
        ->and(strpos($method, "hash_equals(\$checksum, \$storedChecksum)"))
        ->toBeLessThan(strpos($method, 'DB::transaction('));
});
