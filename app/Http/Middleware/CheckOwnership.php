<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Booking;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check Ownership Middleware
 * 
 * Ensures that users can only access their own resources
 */
class CheckOwnership
{
    /**
     * Handle an incoming request and enforce resource ownership.
     *
     * @param  Request $request The HTTP request instance
     * @param  Closure $next The next middleware handler
     * @param  string|null $resource The route parameter name for resource ID
     * @return Response The HTTP response
     */
    public function handle(Request $request, Closure $next, string $resource = null): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated',
                'code' => 401
            ], 401);
        }

        // Skip ownership check for admin and super-admin roles
        if ($user->hasRole(['admin', 'super-admin'])) {
            return $next($request);
        }

        // Get the route parameter that represents the resource ID
        $resourceId = $request->route($resource ?? 'id');
        
        if (!$resourceId) {
            return $next($request);
        }

        // Check ownership based on resource type
        $ownershipCheck = $this->checkResourceOwnership($user, $resource, $resourceId);
        
        if (!$ownershipCheck) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. You can only access your own resources.',
                'code' => 403
            ], 403);
        }

        return $next($request);
    }

    /**
     * Check if user owns the resource
     */
    private function checkResourceOwnership($user, $resource, $resourceId): bool
    {
        switch ($resource) {
            case 'customer':
                return $user->customer && $user->customer->id === $resourceId;
            
            case 'driver':
                return $user->driver && $user->driver->id === $resourceId;
            
            case 'agent':
                return $user->agent && $user->agent->id === $resourceId;
            
            case 'booking':
                $booking = \App\Models\Booking::find($resourceId);
                return $booking && (
                    $booking->customer_id === $user->customer?->id ||
                    $booking->driver_id === $user->driver?->id ||
                    $booking->agent_id === $user->agent?->id
                );
            
            case 'notification':
                $notification = $user->notifications()->find($resourceId);
                return $notification !== null;
            
            case 'user':
                return $user->id === $resourceId;
            
            default:
                return true; // Allow access if resource type is not recognized
        }
    }
}
