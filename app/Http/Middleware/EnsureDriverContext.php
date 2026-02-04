<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure the authenticated user has a driver context.
 * 
 * This middleware verifies that the authenticated user is registered as a driver
 * before allowing access to driver-specific API endpoints.
 * 
 * @see Requirements 2.2
 */
class EnsureDriverContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->driverContext()) {
            return response()->json([
                'status' => 'error',
                'message' => 'User is not registered as a driver',
                'error_code' => 'AUTH_NOT_DRIVER'
            ], 403);
        }

        return $next($request);
    }
}
