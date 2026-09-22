<?php

namespace App\Services;

use App\Models\Website\ShortenedUrl;
use App\Models\Website\ShortUrlClick;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;

class ClickAnalyticsService
{
    /**
     * Track a click on a shortened URL
     *
     * @param ShortenedUrl $shortenedUrl
     * @param Request $request
     * @return ShortUrlClick
     */
    public static function trackClick(ShortenedUrl $shortenedUrl, Request $request): ShortUrlClick
    {
        $startTime = microtime(true);
        
        $agent = new Agent();
        $agent->setUserAgent($request->userAgent());

        // Extract UTM parameters
        $utmParams = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $param) {
            if ($request->has($param)) {
                $utmParams[$param] = $request->get($param);
            }
        }

        // Determine device type
        $deviceType = 'desktop';
        if ($agent->isMobile()) {
            $deviceType = 'mobile';
        } elseif ($agent->isTablet()) {
            $deviceType = 'tablet';
        }

        // Get location data (you might want to integrate with a GeoIP service)
        $country = self::getCountryFromIP($request->ip());
        $city = self::getCityFromIP($request->ip());

        $clickData = [
            'shortened_url_id' => $shortenedUrl->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('referer'),
            'country' => $country,
            'city' => $city,
            'device_type' => $deviceType,
            'browser' => $agent->browser(),
            'platform' => $agent->platform(),
            'clicked_at' => now(),
            'utm_parameters' => !empty($utmParams) ? $utmParams : null,
            'additional_data' => [
                'is_robot' => $agent->isRobot(),
                'languages' => $request->getLanguages(),
                'device' => $agent->device(),
                'browser_version' => $agent->version($agent->browser()),
                'platform_version' => $agent->version($agent->platform()),
            ],
        ];

        $click = ShortUrlClick::create($clickData);

        // Calculate response time
        $endTime = microtime(true);
        $responseTimeMs = round(($endTime - $startTime) * 1000);
        $click->update(['response_time_ms' => $responseTimeMs]);


        return $click;
    }

    /**
     * Get country from IP address
     * This is a placeholder - integrate with a real GeoIP service like MaxMind or IPinfo
     *
     * @param string $ip
     * @return string|null
     */
    private static function getCountryFromIP(string $ip): ?string
    {
        // Placeholder implementation
        // In production, integrate with services like:
        // - MaxMind GeoIP2
        // - IPinfo.io
        // - ipapi.com
        
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return 'Local';
        }

        // You can add a simple GeoIP lookup here
        // For now, return null to avoid external dependencies
        return null;
    }

    /**
     * Get city from IP address
     * This is a placeholder - integrate with a real GeoIP service
     *
     * @param string $ip
     * @return string|null
     */
    private static function getCityFromIP(string $ip): ?string
    {
        // Placeholder implementation
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return 'Local';
        }

        return null;
    }

    /**
     * Get analytics summary for a date range
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @param array $filters
     * @return array
     */
    public static function getAnalyticsSummary(?string $startDate = null, ?string $endDate = null, array $filters = []): array
    {
        $query = ShortUrlClick::query();

        if ($startDate) {
            $query->where('clicked_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('clicked_at', '<=', $endDate);
        }

        // Apply filters
        foreach ($filters as $field => $value) {
            if (in_array($field, ['device_type', 'browser', 'platform', 'country'])) {
                $query->where($field, $value);
            }
        }

        $clicks = $query->get();

        return [
            'total_clicks' => $clicks->count(),
            'unique_ips' => $clicks->unique('ip_address')->count(),
            'countries' => $clicks->whereNotNull('country')->groupBy('country')->map->count(),
            'devices' => $clicks->whereNotNull('device_type')->groupBy('device_type')->map->count(),
            'browsers' => $clicks->whereNotNull('browser')->groupBy('browser')->map->count(),
            'platforms' => $clicks->whereNotNull('platform')->groupBy('platform')->map->count(),
            'hourly_distribution' => $clicks->groupBy(function ($click) {
                return $click->clicked_at->format('H');
            })->map->count(),
            'daily_distribution' => $clicks->groupBy(function ($click) {
                return $click->clicked_at->format('Y-m-d');
            })->map->count(),
        ];
    }
}