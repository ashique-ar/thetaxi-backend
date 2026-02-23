<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to control search engine indexing via X-Robots-Tag header
 * 
 * When SEO_INDEXABLE is false (dev/staging), adds X-Robots-Tag: noindex, nofollow
 * to prevent Google and other search engines from indexing the site.
 */
class SeoIndexableMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Only add noindex header if SEO_INDEXABLE is false
        if (!config('app.seo_indexable', true)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        
        return $response;
    }
}
