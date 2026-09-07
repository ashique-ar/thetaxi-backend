<?php

use Illuminate\Http\Request;

it('allows the portal to preflight broadcasting authentication', function () {
    $origin = 'https://portal.thetaxi.lk';

    $response = $this->call(
        'OPTIONS',
        '/broadcasting/auth',
        [],
        [],
        [],
        [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]
    );

    $response
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', $origin)
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('returns json instead of redirecting unauthenticated broadcasting requests', function () {
    $response = $this->post(
        '/broadcasting/auth',
        [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-App.Models.User.unknown',
        ],
        [
            'Origin' => 'https://portal.thetaxi.lk',
        ]
    );

    $response
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('authenticates broadcasting with the portal passport guard', function () {
    $route = app('router')->getRoutes()->match(
        Request::create('/broadcasting/auth', 'POST')
    );

    expect($route->gatherMiddleware())
        ->toContain('auth:api')
        ->not->toContain('auth:sanctum');
});
