<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DeprecatedApiRoute
{
    public function handle(Request $request, Closure $next, string $successor): Response
    {
        Log::info('Deprecated API route used', [
            'method' => $request->method(),
            'route' => $request->route()?->uri(),
            'successor' => $successor,
            'user_id' => $request->user()?->id,
        ]);

        $response = $next($request);
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Link', sprintf('<%s>; rel="successor-version"', $successor));
        $response->headers->set('X-Deprecated-Route', $request->route()?->uri() ?? $request->path());

        return $response;
    }
}
