<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Display the homepage with dynamic content
     */
    public function index(): View
    {
        // Get CMS content for the homepage sections
        $destinations = $this->getCmsContentByTypeSlug('destinations', 6);
        $packages = $this->getCmsContentByTypeSlug('things-to-do', 6);
        $inspirations = $this->getCmsContentByTypeSlug('independent-services', 3);
        $partners = $this->getCmsContentByTypeSlug('partners', 12);
        $testimonials = $this->getCmsContentByTypeSlug('testimonials', 5);

        // Get website settings for dynamic text and media
        $settings = $this->settingsService->getHomepageSettings();

        return view('home', compact('destinations', 'packages', 'inspirations', 'partners', 'testimonials', 'settings'));
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
