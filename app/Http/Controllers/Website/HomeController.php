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
     */
    public function index(): View
    {
        // ULTRA-OPTIMIZED: Fast cache-first approach with fallbacks
        
        // Get CMS content for the homepage sections with aggressive caching (12 hours for static content)
        $destinations = Cache::remember('home_destinations', 43200, fn() => $this->getCmsContentByTypeSlug('taxi', 6));
        $packages = Cache::remember('home_packages', 43200, fn() => $this->getCmsContentByTypeSlug('things-to-do', 6));
        $inspirations = Cache::remember('home_inspirations', 43200, fn() => $this->getCmsContentByTypeSlug('services', 3));
        $blogs = Cache::remember('home_blogs', 43200, fn() => $this->getCmsContentByTypeSlug('blogs', 3));
        $partners = Cache::remember('home_partners', 43200, fn() => $this->getCmsContentByTypeSlug('partners', 12));

        // Get testimonials with longer caching
        $testimonials = Cache::remember('home_testimonials', 43200, function() {
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
        $faqs = Cache::remember('home_faqs', 43200, function() {
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
        $settings = Cache::remember('home_settings', 43200, fn() => $this->settingsService->getHomepageSettings());

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
     * Get CMS content by content type slug - ULTRA OPTIMIZED with pre-computed card data
     */
    private function getCmsContentByTypeSlug(string $slug, int $limit = 10)
    {
        try {
            // Use a single optimized query instead of two separate queries
            $items = DB::table('cms_contents')
                ->join('cms_content_types', 'cms_contents.cms_content_type_id', '=', 'cms_content_types.id')
                ->select([
                    'cms_contents.id', 
                    'cms_contents.title', 
                    'cms_contents.slug', 
                    'cms_contents.excerpt',
                    'cms_contents.body',
                    'cms_contents.thumbnail', 
                    'cms_contents.price', 
                    'cms_contents.price_currency', 
                    'cms_contents.duration', 
                    'cms_contents.rating',
                    'cms_contents.reviews_count', 
                    'cms_contents.location', 
                    'cms_contents.category', 
                    'cms_contents.published_at', 
                    'cms_contents.created_at', 
                    'cms_contents.is_featured',
                    'cms_contents.special_offer',
                    'cms_contents.discount_percentage'
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

            // PRE-COMPUTE all expensive operations in PHP instead of blade template
            // This prevents multiple function calls per card render
            return $items->map(function($item) use ($slug) {
                $item->imageUrl = $item->thumbnail ? s3_asset($item->thumbnail) : asset('assets/img/home3/blog-img1.jpg');
                $item->detailLink = route('cms.show', ['contentType' => $slug, 'content' => $item->slug]);
                $item->categoryLink = $item->category 
                    ? route('cms.index', ['contentType' => $slug]) . '?category=' . urlencode($item->category)
                    : '#';
                $item->formattedPrice = $item->price ? number_format($item->price, 2) : null;
                $item->displayExcerpt = $item->excerpt ?? \Str::limit(strip_tags($item->body ?? ''), 100);
                $item->displayDate = $item->published_at 
                    ? $item->published_at->format('d F, Y')
                    : ($item->created_at ? $item->created_at->format('d F, Y') : 'Date not available');
                return $item;
            });
        } catch (\Exception $e) {
            \Log::error("CMS content loading failed for slug: {$slug}", ['error' => $e->getMessage()]);
            return collect();
        }
    }
}
