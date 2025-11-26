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
     * Display the homepage with dynamic content
     */
    public function index(): View
    {
        // Get CMS content for the homepage sections with caching (1 hour)
        $destinations = Cache::remember('home_destinations', 3600, fn() => $this->getCmsContentByTypeSlug('taxi', 6));
        $packages = Cache::remember('home_packages', 3600, fn() => $this->getCmsContentByTypeSlug('things-to-do', 6));
        $inspirations = Cache::remember('home_inspirations', 3600, fn() => $this->getCmsContentByTypeSlug('services', 3));
        $blogs = Cache::remember('home_blogs', 3600, fn() => $this->getCmsContentByTypeSlug('blogs', 3));
        $partners = Cache::remember('home_partners', 3600, fn() => $this->getCmsContentByTypeSlug('partners', 12));

        // Get testimonials for social proof with caching
        $testimonials = Cache::remember('home_testimonials', 3600, function() {
            return Testimonial::active()
                ->featured()
                ->ordered()
                ->limit(5)
                ->get();
        });

        // Get FAQs for customer support with caching
        $faqs = Cache::remember('home_faqs', 3600, function() {
            return Faq::active()
                ->featured()
                ->ordered()
                ->limit(6)
                ->get();
        });

        // Get featured vehicles for rental service (caching handled in service or short cache here)
        $featuredVehicles = Cache::remember('home_featured_vehicles', 1800, function() {
            return $this->vehicleService->getFeaturedVehicles([
                'service_type' => 'ride_now',
                'limit' => 8,
                'duration_days' => 1
            ]);
        });

        // Create a search session for the featured vehicles
        $featuredVehicleSearch = $this->vehicleService->createFeaturedVehicleSearch([
            'service_type' => 'ride_now'
        ]);

        // Get website settings for dynamic text and media
        $settings = Cache::remember('home_settings', 3600, fn() => $this->settingsService->getHomepageSettings());

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
            'featuredVehicles',
            'featuredVehicleSearch',
            'settings',
            'search'
        ));
    }

    /**
     * Get CMS content by content type slug
     */
    private function getCmsContentByTypeSlug(string $slug, int $limit = 10)
    {
        $contentType = CmsContentType::where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$contentType) {
            return collect();
        }

        return CmsContent::where('cms_content_type_id', $contentType->id)
            ->where('status', 'published')
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('is_featured', 'desc')
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
