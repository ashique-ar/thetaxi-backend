<?php

use Illuminate\Support\Facades\Route;

it('allows user editors to load the permission catalog shown in the user edit tab', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($candidate) =>
        $candidate->uri() === 'api/permissions/registry' && in_array('GET', $candidate->methods(), true)
    );

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(
            'permission:permissions.view|permissions.manage|roles.create|roles.edit|roles.manage|users.edit'
        );
});
