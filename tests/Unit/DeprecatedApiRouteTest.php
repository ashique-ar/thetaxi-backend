<?php

use App\Http\Middleware\DeprecatedApiRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

it('records compatibility route usage and advertises the canonical successor', function () {
    Log::spy();
    $request = Request::create('/api/booking-flow/available-addons', 'GET');
    $response = app(DeprecatedApiRoute::class)->handle(
        $request,
        fn () => new Response('ok', 200),
        '/api/booking-flow/addons/available',
    );

    expect($response->headers->get('Deprecation'))->toBe('true')
        ->and($response->headers->get('Link'))->toContain('/api/booking-flow/addons/available')
        ->and($response->headers->get('X-Deprecated-Route'))->toBe('api/booking-flow/available-addons');

    Log::shouldHaveReceived('info')->once()->with(
        'Deprecated API route used',
        Mockery::on(fn (array $context) => $context['successor'] === '/api/booking-flow/addons/available'),
    );
});
