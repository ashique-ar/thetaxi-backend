<?php

it('resolves omitted dashboard company filters before Sales profile scope checks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));

    foreach ([
        'public function show(',
        'public function collectionAging(',
        'public function commissionStatus(',
        'public function kpiFacts(',
        'public function pipelineFacts(',
        'public function scheduleFacts(',
        'public function activePortfolio(',
        'public function staff(',
    ] as $marker) {
        $start = strpos($controller, $marker);
        expect($start)->not->toBeFalse();
        $end = strpos($controller, "\n    public function ", $start + 1);
        $method = substr($controller, $start, $end === false ? null : $end - $start);

        expect($method)->toContain('resolveCompanyId(');
    }
});

it('returns a conflict when the dashboard cannot resolve an active default company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesDashboardController.php'));
    $start = strpos($controller, 'private function resolveCompanyId(');
    $end = strpos($controller, "\n    }", $start);
    $resolver = substr($controller, $start, $end - $start);

    expect($resolver)->toContain('activeDefaultCompany()', 'abort_unless($companyId, 409');
});
