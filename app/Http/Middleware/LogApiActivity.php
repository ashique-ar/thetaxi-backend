<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log API Activity Middleware
 * 
 * Logs API requests and responses for monitoring and debugging
 */
class LogApiActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        
        // Log the incoming request
        $this->logRequest($request);
        
        $response = $next($request);
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2); // Convert to milliseconds
        
        // Log the response
        $this->logResponse($request, $response, $duration);
        
        return $response;
    }

    /**
     * Log the incoming request
     */
    private function logRequest(Request $request): void
    {
        $logData = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'body' => $this->sanitizeBody($request->all()),
            'user_id' => $request->user()?->id,
            'timestamp' => now()->toISOString()
        ];

        Log::channel('api')->info('API Request', $logData);
    }

    /**
     * Log the response
     */
    private function logResponse(Request $request, Response $response, float $duration): void
    {
        $logData = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'response_size' => strlen($response->getContent()),
            'user_id' => $request->user()?->id,
            'timestamp' => now()->toISOString()
        ];

        // Log errors separately
        if ($response->getStatusCode() >= 400) {
            Log::channel('api')->error('API Error Response', array_merge($logData, [
                'response_body' => $this->sanitizeResponseBody($response->getContent())
            ]));
        } else {
            Log::channel('api')->info('API Response', $logData);
        }
    }

    /**
     * Sanitize headers to remove sensitive information
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];
        
        foreach ($sensitiveHeaders as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = ['***REDACTED***'];
            }
        }
        
        return $headers;
    }

    /**
     * Sanitize request body to remove sensitive information
     */
    private function sanitizeBody(array $body): array
    {
        $sensitiveFields = ['password', 'password_confirmation', 'token', 'secret', 'key'];
        
        foreach ($sensitiveFields as $field) {
            if (isset($body[$field])) {
                $body[$field] = '***REDACTED***';
            }
        }
        
        return $body;
    }

    /**
     * Sanitize response body (only for error responses)
     */
    private function sanitizeResponseBody(string $content): string
    {
        // Only log first 1000 characters of response body
        if (strlen($content) > 1000) {
            return substr($content, 0, 1000) . '... (truncated)';
        }
        
        return $content;
    }
}
