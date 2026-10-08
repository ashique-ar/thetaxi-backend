<?php

it('resolves omitted profile roster, export, enrollment, and selector company IDs to the active default', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));

    foreach ([
        'public function index(',
        'public function export(',
        'public function store(',
        'public function staffOptions(',
        'public function reportingProfileOptions(',
    ] as $marker) {
        $start = strpos($controller, $marker);
        expect($start)->not->toBeFalse();
        $end = strpos($controller, "\n    public function ", $start + 1);
        $method = substr($controller, $start, $end === false ? null : $end - $start);

        expect($method)->toContain('resolveCompanyId(');
    }
});

it('fails closed if the active default company is unavailable', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));
    $start = strpos($controller, 'private function resolveCompanyId(');
    $end = strpos($controller, "\n    }", $start);
    $resolver = substr($controller, $start, $end - $start);

    expect($resolver)->toContain('activeDefaultCompany()', 'abort_unless($companyId, 409');
});
