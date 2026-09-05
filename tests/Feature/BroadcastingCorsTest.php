<?php

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
