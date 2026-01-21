<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Services\PerformanceOptimizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    protected PerformanceOptimizationService $perf;

    public function __construct(PerformanceOptimizationService $perf)
    {
        $this->perf = $perf;
    }

    public function index(Request $request)
    {
        // Return cached sitemap if available
        $xml = Cache::remember('sitemap', 86400, function () {
            return $this->perf->generateSitemap();
        });

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
