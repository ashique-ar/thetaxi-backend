<?php

use Illuminate\Http\Request;

it('uses the vehicle detail presentation for both legacy and SEO URLs', function (string $path, string $expectedPage) {
    $request = Request::create($path);
    $route = app('router')->getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    preg_match('/<\?php\s+(\$themeRouteName[\s\S]+?)\?>/', $layout, $matches);
    expect($matches)->toHaveCount(2);

    $page = (static function () use ($matches) {
        return eval($matches[1] . '\nreturn $themePageSlug;');
    })();

    expect($page)->toBe($expectedPage);
})->with([
    'legacy vehicle' => ['/vehicle/019f7e4a-be4e-70e4-9f9f-1046030f13c6', 'vehicle-details'],
    'legacy service' => ['/vehicle/019f7e4a-be4e-70e4-9f9f-1046030f13c6/with-driver', 'vehicle-details'],
    'SEO vehicle' => ['/vehicle/perodua-axia/019f7e4a-be4e-70e4-9f9f-1046030f13c6', 'vehicle-details'],
    'SEO service' => ['/vehicle/perodua-axia/019f7e4a-be4e-70e4-9f9f-1046030f13c6/with-driver', 'vehicle-details'],
    'home' => ['/', 'home'],
]);
