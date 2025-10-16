<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate API Version Middleware
 * 
 * Ensures API version compatibility and handles version-specific logic
 */
class ValidateApiVersion
{
    /**
     * Supported API versions
     */
    private const SUPPORTED_VERSIONS = ['v1', '1.0', '1.1', '1.2'];
    
    /**
     * Default API version
     */
    private const DEFAULT_VERSION = 'v1';
    
    /**
     * Deprecated API versions
     */
    private const DEPRECATED_VERSIONS = [];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $version = $this->getApiVersion($request);
        
        // Check if version is supported
        if (!in_array($version, self::SUPPORTED_VERSIONS)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unsupported API version',
                'code' => 400,
                'supported_versions' => self::SUPPORTED_VERSIONS,
                'requested_version' => $version
            ], 400);
        }
        
        // Check if version is deprecated
        if (in_array($version, self::DEPRECATED_VERSIONS)) {
            // Add deprecation warning header
            $response = $next($request);
            $response->header('X-API-Deprecated', 'true');
            $response->header('X-API-Deprecation-Message', 'This API version is deprecated. Please upgrade to the latest version.');
            return $response;
        }
        
        // Add version to request for controllers to use
        $request->merge(['api_version' => $version]);
        
        $response = $next($request);
        
        // Add version header to response
        $response->header('X-API-Version', $version);
        
        return $response;
    }

    /**
     * Get API version from request
     */
    private function getApiVersion(Request $request): string
    {
        // Priority order: Header > URL parameter > Accept header > Default
        
        // 1. Check X-API-Version header
        if ($request->hasHeader('X-API-Version')) {
            return $request->header('X-API-Version');
        }
        
        // 2. Check version URL parameter
        if ($request->has('version')) {
            return $request->get('version');
        }
        
        // 3. Check Accept header (application/json; version=1.0)
        $acceptHeader = $request->header('Accept', '');
        if (preg_match('/version=([0-9\.v]+)/', $acceptHeader, $matches)) {
            return $matches[1];
        }
        
        // 4. Check URL path for version (/api/v1/...)
        $path = $request->path();
        if (preg_match('/^api\/(v[0-9]+|[0-9]+\.[0-9]+)\//', $path, $matches)) {
            return $matches[1];
        }
        
        // 5. Return default version
        return self::DEFAULT_VERSION;
    }
}
