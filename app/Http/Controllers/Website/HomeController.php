<?php


namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Models\Website\Testimonial;
use App\Models\Website\Faq;
use App\Services\WebsiteSettingsService;
use App\Services\VehicleService;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    protected WebsiteSettingsService $settingsService;
    protected VehicleService $vehicleService;

    public function __construct(WebsiteSettingsService $settingsService, VehicleService $vehicleService)
    {
        $this->settingsService = $settingsService;
        $this->vehicleService = $vehicleService;
    }

    /**
     * Display the homepage with dynamic content - HIGHLY OPTIMIZED
     * CRITICAL FIX: Fetch ALL CMS content in ONE QUERY, then filter in PHP instead of 5 separate database queries
     */
    public function index(): View
    {
        // ULTRA-OPTIMIZED: Cache all CMS content together to avoid multiple database round trips
        $allCmsContent = Cache::remember('home_all_cms_content', 86400, fn() => $this->getAllCmsContent());
        
        // Filter the cached content by type in PHP (no database calls)
        $destinations = $this->filterCmsByType($allCmsContent, 'taxi', 6);
        $packages = $this->filterCmsByType($allCmsContent, 'things-to-do', 6);
        $inspirations = $this->filterCmsByType($allCmsContent, 'services', 3);
        $blogs = $this->filterCmsByType($allCmsContent, 'blogs', 3);
        $partners = $this->filterCmsByType($allCmsContent, 'partners', 12);

        // Get testimonials with longer caching
        $testimonials = Cache::remember('home_testimonials', 86400, function() {
            try {
                return Testimonial::select('id', 'client_name', 'client_title', 'client_image', 'comment', 'rating', 'created_at')
                    ->where('is_active', true)
                    ->where('is_featured', true)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();
            } catch (\Exception $e) {
                return collect();
            }
        });

        // Get FAQs with longer caching
        $faqs = Cache::remember('home_faqs', 86400, function() {
            try {
                return Faq::select('id', 'question', 'answer', 'created_at')
                    ->where('is_active', true)
                    ->where('is_featured', true)
                    ->orderBy('display_order', 'asc')
                    ->orderBy('created_at', 'desc')
                    ->limit(6)
                    ->get();
            } catch (\Exception $e) {
                return collect();
            }
        });

        // Get website settings with aggressive caching
        $settings = Cache::remember('home_settings', 86400, fn() => $this->settingsService->getHomepageSettings());

        // Create empty search object for booking form component
        $search = (object) [
            'service_type' => 'airport_transfers',
            'from_date' => null,
            'to_date' => null,
            'from_time' => null,
            'to_time' => null,
            'pickup_location' => null,
            'dropoff_location' => null,
            'duration_days' => 1,
            'passengers' => 1,
        ];

        return view('home', compact(
            'destinations', 
            'packages', 
            'inspirations', 
            'blogs', 
            'partners', 
            'testimonials', 
            'faqs', 
            'settings',
            'search'
        ));
    }

    /**
     * Fetch ALL CMS content in a single database query
     * This is MUCH faster than 5 separate queries
     */
    private function getAllCmsContent()
    {
        try {
            return CmsContent::with('contentType')
                ->where('is_active', true)
                ->where('status', 'published')
                ->where('is_active', true)
                ->orderByDesc('is_featured')
                ->groupBy('contentType.slug')
                ->get(); 

                // Group by content type for faster filtering
            // return DB::table('cms_contents')
            //     ->join('cms_content_types', 'cms_contents.cms_content_type_id', '=', 'cms_content_types.id')
            //     ->select([
            //         'cms_contents.id', 
            //         'cms_contents.title', 
            //         'cms_contents.slug', 
            //         'cms_contents.excerpt',
            //         'cms_contents.body',
            //         'cms_contents.thumbnail', 
            //         'cms_contents.price', 
            //         'cms_contents.price_currency', 
            //         'cms_contents.duration', 
            //         'cms_contents.rating',
            //         'cms_contents.reviews_count', 
            //         'cms_contents.location', 
            //         'cms_contents.category', 
            //         'cms_contents.published_at', 
            //         'cms_contents.created_at', 
            //         'cms_contents.is_featured',
            //         'cms_contents.special_offer',
            //         'cms_contents.discount_percentage',
            //         'cms_content_types.slug as content_type_slug'
            //     ])
            //     ->where('cms_content_types.is_active', true)
            //     ->where('cms_contents.status', 'published')
            //     ->where('cms_contents.is_active', true)
            //     ->whereNotNull('cms_contents.published_at')
            //     ->where('cms_contents.published_at', '<=', now())
            //     ->orderByDesc('cms_contents.is_featured')
            //     ->orderByDesc('cms_contents.published_at')
            //     ->get()
            //     ->groupBy('content_type_slug'); // Group by content type for faster filtering
        } catch (\Exception $e) {
            \Log::error("Failed to load all CMS content", ['error' => $e->getMessage()]);
            return collect();
        }
    }

    /**
     * Filter CMS content by type from the cached grouped collection
     * No database calls - pure PHP filtering
     */
    private function filterCmsByType($groupedContent, string $slug, int $limit = 10)
    {
        return collect($groupedContent->get($slug, []))->take($limit);
    }
}
