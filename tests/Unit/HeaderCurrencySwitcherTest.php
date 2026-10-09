<?php

use App\Services\CurrencyService;

it('renders configured currency codes and names with the selected choice marked', function () {
    foreach ([
        App\Http\ViewComposers\CurrencyViewComposer::class,
        App\Http\ViewComposers\SettingsViewComposer::class,
        App\Http\ViewComposers\ServicesViewComposer::class,
    ] as $composer) {
        $mock = Mockery::mock($composer);
        $mock->shouldReceive('compose')->andReturnNull();
        app()->instance($composer, $mock);
    }
    $currencies = Mockery::mock(CurrencyService::class);
    $currencies->shouldReceive('getSelectedCurrency')->once()->andReturn('USD');
    $currencies->shouldReceive('getAvailableCurrenciesForDisplay')->once()->andReturn([
        ['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar'],
        ['code' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee'],
        ['code' => 'AED', 'symbol' => 'د.إ', 'name' => 'UAE Dirham'],
    ]);
    app()->instance(CurrencyService::class, $currencies);
    $html = view('components.header-currency-switcher')->render();
    expect($html)->toContain('Currency', '<strong>USD</strong>', 'USD ($)', 'INR (₹)', 'AED (د.إ)', 'UAE Dirham');
    preg_match_all('/data-currency="([^"]+)" aria-current="([^"]+)"/', $html, $matches);
    expect($matches[1])->toBe(['USD', 'INR', 'AED'])
        ->and($matches[2])->toBe(['true', 'false', 'false']);
});

it('uses one shared control per header and keeps the modern-theme control outside mobile navigation', function () {
    foreach ([
        'partials/header.blade.php',
        'partials/themes/theme-02/header.blade.php',
        'partials/themes/theme-03/header.blade.php',
        'partials/themes/theme-04/header.blade.php',
    ] as $path) {
        $html = file_get_contents(resource_path('views/' . $path));
        $include = "@include('components.header-currency-switcher')";
        expect(substr_count($html, $include))->toBe(1);
        if (str_contains($path, 'theme-03') || str_contains($path, 'theme-04')) {
            expect(strpos($html, $include))->toBeGreaterThan(strpos($html, '</nav>'));
        }
    }
});
