<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class AboutController extends Controller
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Display the about page with dynamic content
     */
    public function index(): View
    {
        // Get about page specific content
        $aboutContent = $this->getCmsContentByTypeSlug('about-page', 1);
        
        // Get website settings for dynamic content
        $settings = $this->settingsService->getAboutPageSettings();

        return view('about', compact('aboutContent', 'settings'));
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
