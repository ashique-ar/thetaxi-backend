<?php

use App\Http\Middleware\EnsureInternalContext;
use App\Services\UserContextService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

uses(Tests\TestCase::class);

it('rejects corporate portal context from internal policy management', function () {
    $user = new App\Models\User;
    $contexts = Mockery::mock(UserContextService::class);
    $contexts->shouldReceive('resolveActiveContextFromRequest')
        ->once()
        ->andReturn(['context_type' => 'corporate', 'id' => 'corporate-context']);
    $request = Request::create('/api/admin/corporates/company-a/distance-pricing-policy');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureInternalContext($contexts))->handle(
        $request,
        fn () => response()->json(['unexpected' => true]),
    );

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['message'])->toContain('internal administration');
});

it('allows a resolved internal context to continue', function () {
    $user = new App\Models\User;
    $contexts = Mockery::mock(UserContextService::class);
    $contexts->shouldReceive('resolveActiveContextFromRequest')
        ->once()
        ->andReturn(['context_type' => 'internal', 'id' => 'internal-context']);
    $request = Request::create('/api/admin/corporates/company-a/distance-pricing-policy');
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureInternalContext($contexts))->handle(
        $request,
        fn () => new Response('allowed', 200),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('allowed');
});
