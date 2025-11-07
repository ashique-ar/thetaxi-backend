<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class ContactController extends Controller
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Display the contact page with dynamic content
     */
    public function index(): View
    {
        // Get contact page specific content
        $contactContent = $this->getCmsContentByTypeSlug('contact-page', 1);
        
        // Get website settings for dynamic content
        $settings = $this->settingsService->getContactPageSettings();

        return view('contact', compact('contactContent', 'settings'));
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