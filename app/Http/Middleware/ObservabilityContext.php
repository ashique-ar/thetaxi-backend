<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ObservabilityContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = Str::limit($request->header('X-Request-ID', (string) Str::uuid()), 100, '');
        $request->headers->set('X-Request-ID', $requestId);
        $request->attributes->set('observability_started_at', microtime(true));
        Log::withContext([
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => $request->route()?->uri(),
            'application_version' => config('app.version'),
            'project' => config('app.project_slug'),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
