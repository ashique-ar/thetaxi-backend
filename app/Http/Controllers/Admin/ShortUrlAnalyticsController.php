<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Website\ShortenedUrl;
use App\Services\ClickAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShortUrlAnalyticsController extends Controller
{
    /**
     * Display analytics dashboard
     */
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());
        
        $summary = ClickAnalyticsService::getAnalyticsSummary($startDate, $endDate);
        
        $topUrls = ShortenedUrl::withCount('clicks')
            ->whereHas('clicks', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('clicked_at', [$startDate, $endDate]);
            })
            ->orderBy('clicks_count', 'desc')
            ->limit(10)
            ->get();

        return view('admin.analytics.short-urls', compact('summary', 'topUrls', 'startDate', 'endDate'));
    }

    /**
     * Get analytics data as JSON
     */
    public function data(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $filters = $request->get('filters', []);
        
        $summary = ClickAnalyticsService::getAnalyticsSummary($startDate, $endDate, $filters);
        
        return response()->json($summary);
    }

    /**
     * Get detailed analytics for a specific short URL
     */
    public function show(ShortenedUrl $shortenedUrl): JsonResponse
    {
        $analytics = $shortenedUrl->getAnalyticsSummary();
        
        return response()->json([
            'url' => $shortenedUrl,
            'analytics' => $analytics,
        ]);
    }

    /**
     * Export analytics data
     */
    public function export(Request $request)
    {
        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());
        
        // This could be enhanced to export to CSV/Excel
        $summary = ClickAnalyticsService::getAnalyticsSummary($startDate, $endDate);
        
        return response()->json($summary);
    }
}