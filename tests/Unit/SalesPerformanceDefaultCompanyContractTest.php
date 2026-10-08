<?php

it('resolves omitted Sales performance company inputs to the active default before existing scope checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));

    foreach ([
        'public function targets(',
        'public function profileOptions(',
        'public function alertOwnerOptions(',
        'public function createTarget(',
        'public function periodLocks(',
        'public function snapshots(',
        'public function createAlertPolicy(',
        'public function portfolioStatusPolicies(',
        'public function createPortfolioStatusPolicy(',
        'private function targetCopyInput(',
        'private function targetImportInput(',
        'private function periodInput(',
        'private function periodCloseInput(',
    ] as $marker) {
        $start = strpos($controller, $marker);
        expect($start)->not->toBeFalse();
        $next = strpos($controller, "\n    public function ", $start + 1);
        $method = substr($controller, $start, $next === false ? null : $next - $start);

        expect($method)->toContain('->resolveCompanyId(');
    }
});

it('fails closed when the active default company cannot be resolved', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesPerformanceController.php'));
    $start = strpos($controller, 'private function resolveCompanyId(');
    $end = strpos($controller, 'private function writeConfirmation(', $start);
    $resolver = substr($controller, $start, $end - $start);

    expect($resolver)->toContain('activeDefaultCompany()', 'abort_unless($companyId, 409');
});
