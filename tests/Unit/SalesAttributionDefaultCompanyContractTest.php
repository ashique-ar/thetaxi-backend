<?php

it('defaults attribution lists, dry runs, profile options, and historical batch writes to the active company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));

    foreach ([
        'public function index(',
        'public function exceptions(',
        'public function dryRun(',
        'public function applyHistoricalBatch(',
        'public function profileOptions(',
    ] as $marker) {
        $start = strpos($controller, $marker);
        expect($start)->not->toBeFalse();
        $end = strpos($controller, "\n    public function ", $start + 1);
        $method = substr($controller, $start, $end === false ? null : $end - $start);

        expect($method)->toContain('resolveCompanyId(');
    }
});

it('fails closed when attribution requests cannot resolve an active default company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $start = strpos($controller, 'private function resolveCompanyId(');
    $end = strpos($controller, "\n    }", $start);
    $resolver = substr($controller, $start, $end - $start);

    expect($resolver)->toContain('activeDefaultCompany()', 'abort_unless($companyId, 409');
});
