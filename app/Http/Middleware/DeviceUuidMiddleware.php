<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to validate and extract Device UUID from request headers.
 * 
 * This middleware is used for public/guest API endpoints that require
 * device identification without authentication (e.g., meter functionality).
 * 
 * @see Requirements 3.2, 3.5
 */
class DeviceUuidMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $deviceUuid = $request->header('X-Device-UUID');

        if (empty($deviceUuid)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Device UUID required',
                'error_code' => 'DEVICE_UUID_REQUIRED'
            ], 400);
        }

        // Merge device_uuid into request for easy access in controllers
        $request->merge(['device_uuid' => $deviceUuid]);

        return $next($request);
    }
}
