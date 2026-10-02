<?php

it('bounds existing Sales evidence selection to the authorized subject', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesEvidenceController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/sales/components/sales-evidence-upload/sales-evidence-upload.component.html'));

    expect($controller)
        ->toContain('public function options(Request $request)')
        ->toContain("'selected_id' => ['nullable', 'uuid']")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("->where('subject_type', \$data['subject_type'])->where('subject_id', \$data['subject_id'])")
        ->toContain('paginate((int) ($data[\'per_page\'] ?? 25))')
        ->and($routes)->toContain("Route::get('evidence-file-options', [SalesEvidenceController::class, 'options'])")
        ->and($component)->toContain('endpoint="/sales/evidence-file-options"')
        ->toContain('placeholder="Search existing evidence by file name or type"');
});
