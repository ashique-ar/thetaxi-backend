<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DriverApiDocsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredCode = (string) config('driver_api_docs.access_code');

        // Fail closed when the deployment has not configured an access code.
        abort_if($configuredCode === '', 404);

        $providedCode = (string) $request->query('code', '');
        $sessionKey = 'driver_api_docs.authorized';
        $authorized = $request->session()->get($sessionKey) === hash('sha256', $configuredCode);

        if (! $authorized && $providedCode !== '' && hash_equals($configuredCode, $providedCode)) {
            $request->session()->put($sessionKey, hash('sha256', $configuredCode));
            $authorized = true;
        }

        abort_unless($authorized, 403, 'A valid driver API documentation access code is required.');

        return $next($request);
    }
}
