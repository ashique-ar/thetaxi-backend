<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class DebugController extends Controller
{
    public function timeoutDiagnostic(Request $request)
    {
        DB::enableQueryLog();
        
        $startTime = microtime(true);
        
        // Test 1: Database connection
        $test1Start = microtime(true);
        $cmsCount = \App\Models\Website\CmsContent::count();
        $test1Time = microtime(true) - $test1Start;
        
        // Test 2: Settings service
        $test2Start = microtime(true);
        $settingsService = new \App\Services\WebsiteSettingsService();
        $settings = $settingsService->getHomepageSettings();
        $test2Time = microtime(true) - $test2Start;
        
        // Test 3: CMS queries
        $test3Start = microtime(true);
        $destinations = $this->getCmsContentByTypeSlug('taxi', 6);
        $test3Time = microtime(true) - $test3Start;
        
        // Test 4: s3_asset calls
        $test4Start = microtime(true);
        $favicon = s3_asset($settings['favicon'] ?? 'assets/img/favicon.ico');
        $test4Time = microtime(true) - $test4Start;
        
        $totalTime = microtime(true) - $startTime;
        $queries = DB::getQueryLog();
        
        return response()->json([
            'total_time_ms' => round($totalTime * 1000, 2),
            'test1_cms_count_ms' => round($test1Time * 1000, 2),
            'test2_settings_ms' => round($test2Time * 1000, 2),
            'test3_cms_queries_ms' => round($test3Time * 1000, 2),
            'test4_s3_asset_ms' => round($test4Time * 1000, 2),
            'cms_count' => $cmsCount,
            'settings_loaded' => !empty($settings),
            'favicon' => $favicon,
            'query_count' => count($queries),
            'queries' => array_map(function($q) {
                return [
                    'time' => $q['time'],
                    'query' => substr($q['query'], 0, 100) . '...'
                ];
            }, array_slice($queries, 0, 10))
        ]);
    }
    
    private function getCmsContentByTypeSlug(string $slug, int $limit = 10)
    {
        try {
            return DB::table('cms_contents')
                ->join('cms_content_types', 'cms_contents.cms_content_type_id', '=', 'cms_content_types.id')
                ->select([
                    'cms_contents.id', 
                    'cms_contents.title', 
                    'cms_contents.slug', 
                    'cms_contents.excerpt',
                    'cms_contents.thumbnail', 
                    'cms_contents.price', 
                    'cms_contents.price_currency'
                ])
                ->where('cms_content_types.slug', $slug)
                ->where('cms_content_types.is_active', true)
                ->where('cms_contents.status', 'published')
                ->where('cms_contents.is_active', true)
                ->whereNotNull('cms_contents.published_at')
                ->where('cms_contents.published_at', '<=', now())
                ->orderByDesc('cms_contents.is_featured')
                ->orderByDesc('cms_contents.published_at')
                ->limit($limit)
                ->get();
        } catch (\Exception $e) {
            return collect();
        }
    }
}
