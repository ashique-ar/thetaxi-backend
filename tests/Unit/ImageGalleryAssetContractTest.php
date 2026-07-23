<?php

it('keeps the backend gallery owner scoped to individual image assets', function (): void {
    $root = dirname(__DIR__, 2);
    $model = file_get_contents($root . '/app/Models/ImageGallery.php');
    $resource = file_get_contents($root . '/app/Http/Resources/ImageGallery/ImageGalleryResource.php');
    $routes = file_get_contents($root . '/routes/api.php');

    expect($model)->toContain("protected \$table = 'image_galleries'")
        ->and($model)->toContain("'path'")
        ->and($resource)->toContain("'url'")
        ->and($resource)->toContain("'thumbnail_url'")
        ->and($routes)->toContain("Route::apiResource('galleries', ImageGalleryController::class)")
        ->and($routes)->not->toContain("galleries/{gallery}/images")
        ->and($routes)->not->toContain("galleries/{gallery}/share")
        ->and($routes)->not->toContain("galleries/{gallery}/download");
});
