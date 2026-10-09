<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('uses the vehicle detail presentation for both legacy and SEO URLs', function (string $path, string $routeName, string $expectedPage) {
    $request = Request::create($path);
    $route = (new Route(['GET'], $path, fn () => null))->name($routeName);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    preg_match('/<\?php\s+(\$themeRouteName[\s\S]+?)\?>/', $layout, $matches);
    expect($matches)->toHaveCount(2);

    $page = (static function () use ($matches) {
        return eval($matches[1] . "\n" . 'return $themePageSlug;');
    })();

    expect($page)->toBe($expectedPage);
})->with([
    'legacy vehicle' => ['/vehicle/019f7e4a-be4e-70e4-9f9f-1046030f13c6', 'vehicle.details', 'vehicle-details'],
    'legacy service' => ['/vehicle/019f7e4a-be4e-70e4-9f9f-1046030f13c6/with-driver', 'vehicle.details', 'vehicle-details'],
    'SEO vehicle' => ['/vehicle/perodua-axia/019f7e4a-be4e-70e4-9f9f-1046030f13c6', 'vehicle.details.seo', 'vehicle-details'],
    'SEO service' => ['/vehicle/perodua-axia/019f7e4a-be4e-70e4-9f9f-1046030f13c6/with-driver', 'vehicle.details.seo', 'vehicle-details'],
    'home' => ['/', 'home', 'home'],
]);
