<?php

namespace App\Http\Controllers;

use App\Services\UrlShortenerService;
use App\Services\ClickAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class ShortUrlController extends Controller
{
    /**
     * Redirect from short URL to original URL
     *
     * @param string $code
     * @param Request $request
     * @return RedirectResponse
     */
    public function redirect(string $code, Request $request): RedirectResponse
    {
        $originalUrl = UrlShortenerService::resolve($code);

        if (!$originalUrl) {
            // Short URL not found or expired
            return redirect()->route('home')
                ->with('error', 'The link you followed is invalid or has expired.');
        }

        // Track the click for analytics
        try {
            $shortenedUrl = \App\Models\Website\ShortenedUrl::where('short_code', $code)->first();
            if ($shortenedUrl) {
                ClickAnalyticsService::trackClick($shortenedUrl, $request);
            }
        } catch (\Exception $e) {
            // Don't let analytics tracking failure break the redirect
            \Illuminate\Support\Facades\Log::warning('Failed to track click analytics', [
                'short_code' => $code,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect($originalUrl);
    }
}