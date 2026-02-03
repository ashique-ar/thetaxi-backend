<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{
    /**
     * ULTRA-SIMPLIFIED homepage - minimize all database calls
     */
    public function index(): View
    {
        // CRITICAL: Cache everything with LONG expiry to avoid repeated database hits
        $homeData = $this->getCompleteHomeData();
        // $homeData = Cache::remember('homepage_complete_data', 86400, function() {
        //     return $this->getCompleteHomeData();
        // });


        return view('home', $homeData);
    }

    /**
     * Get ALL homepage data in the MINIMUM number of database queries
     */
    private function getCompleteHomeData(): array
    {
        try {
            // Query 1: Get ALL CMS content in one shot
            $allCmsContent = DB::table('cms_contents')
                ->join('cms_content_types', 'cms_contents.cms_content_type_id', '=', 'cms_content_types.id')
                ->select([
                    'cms_contents.id',
                    'cms_contents.title',
                    'cms_contents.slug',
                    'cms_contents.excerpt',
                    'cms_contents.thumbnail',
                    'cms_contents.price',
                    'cms_contents.price_currency',
                    'cms_contents.duration',
                    'cms_contents.rating',
                    'cms_contents.reviews_count',
                    'cms_contents.location',
                    'cms_contents.category',
                    'cms_contents.published_at',
                    'cms_contents.is_featured',
                    'cms_contents.pickup_location',
                    'cms_contents.dropoff_location',
                    'cms_contents.service_type',
                    'cms_contents.min_days',
                    'cms_content_types.slug as content_type'
                ])
                ->where('cms_content_types.is_active', true)
                ->where('cms_contents.status', 'published')
                ->where('cms_contents.is_active', true)
                ->where('cms_contents.is_featured', true)
                // ->whereNotNull('cms_contents.published_at')
                ->orderByDesc('cms_contents.is_featured')
                // ->orderByDesc('cms_contents.published_at')
                ->get()
                ->groupBy('content_type');


            // Query 2: Get testimonials
            // $testimonials = DB::table('testimonials')
            //     ->select(['id', 'client_name', 'client_title', 'client_image', 'comment', 'rating'])
            //     ->where('is_active', true)
            //     ->where('is_featured', true)
            //     ->orderByDesc('created_at')
            //     ->limit(5)
            //     ->get();
            $testimonials = collect();
            // Query 3: Get FAQs  
            $faqs = DB::table('faqs')
                ->select(['id', 'question', 'answer'])
                ->where('is_active', true)
                ->where('is_featured', true)
                ->orderBy('sort_order', 'asc')
                ->limit(6)
                ->get();

            // Filter CMS content by type
            return [
                'destinations' => collect($allCmsContent->get('taxi', []))->take(6),
                'packages' => collect($allCmsContent->get('things-to-do', []))->take(6),
                'inspirations' => collect($allCmsContent->get('services', []))->take(3),
                'blogs' => collect($allCmsContent->get('blogs', []))->take(3),
                'partners' => collect($allCmsContent->get('partners', []))->take(12),
                'testimonials' => $testimonials,
                'faqs' => $faqs,
                'search' => (object) [
                    'service_type' => 'airport_transfers',
                    'from_date' => null,
                    'to_date' => null,
                    'from_time' => null,
                    'to_time' => null,
                    'pickup_location' => null,
                    'dropoff_location' => null,
                    'duration_days' => 1,
                    'passengers' => 1,
                ],
            ];
        } catch (\Exception $e) {
            \Log::error("Homepage data loading failed", ['error' => $e->getMessage()]);
            return $this->getFallbackData();
        }
    }



    /**
     * Fallback data if database queries fail
     */
    private function getFallbackData(): array
    {
        return [
            'destinations' => collect(),
            'packages' => collect(),
            'inspirations' => collect(),
            'blogs' => collect(),
            'partners' => collect(),
            'testimonials' => collect(),
            'faqs' => collect(),
            // No settings here - ViewComposer handles all settings
            'search' => (object) ['service_type' => 'airport_transfers', 'passengers' => 1],
        ];
    }
}
