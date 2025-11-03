<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CmsController extends Controller
{
    /**
     * Display a listing of content for a specific content type
     */
    public function index(string $contentTypeSlug): View
    {

        Log::info("CMS Index: type={$contentTypeSlug}");
        $contentType = CmsContentType::where('slug', $contentTypeSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $contents = CmsContent::published()
            ->byType($contentTypeSlug)
            ->with(['contentType'])
            ->orderBy('is_featured', 'desc')
            ->orderBy('published_at', 'desc')
            ->paginate(12);

        return view('cms.index', compact('contentType', 'contents'));
    }

    /**
     * Display the specified content
     */
    public function show(string $contentTypeSlug, string $contentSlug): View
    {
        Log::info("CMS Show: type={$contentTypeSlug}, slug={$contentSlug}");
        $contentType = CmsContentType::where('slug', $contentTypeSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $content = CmsContent::published()
            ->byType($contentTypeSlug)
            ->where('slug', $contentSlug)
            ->with(['contentType'])
            ->firstOrFail();

        // Increment view count
        $content->incrementViews();

        // Get related content (same content type, excluding current)
        $relatedContent = CmsContent::published()
            ->byType($contentTypeSlug)
            ->where('id', '!=', $content->id)
            ->orderBy('is_featured', 'desc')
            ->orderBy('published_at', 'desc')
            ->limit(6)
            ->get();

        return view('cms.show', compact('contentType', 'content', 'relatedContent'));
    }

    /**
     * Display featured content across all content types
     */
    public function featured(): View
    {
        $featuredContent = CmsContent::published()
            ->featured()
            ->with(['contentType'])
            ->orderBy('published_at', 'desc')
            ->paginate(12);

        return view('cms.featured', compact('featuredContent'));
    }

    /**
     * Search content across all types
     */
    public function search(Request $request): View
    {
        $query = $request->get('q', '');
        $contentTypeSlug = $request->get('type', '');

        $contents = CmsContent::published()
            ->with(['contentType'])
            ->when($query, function ($q) use ($query) {
                $q->where(function ($subQuery) use ($query) {
                    $subQuery->where('title', 'like', "%{$query}%")
                             ->orWhere('excerpt', 'like', "%{$query}%")
                             ->orWhere('body', 'like', "%{$query}%");
                });
            })
            ->when($contentTypeSlug, function ($q) use ($contentTypeSlug) {
                $q->byType($contentTypeSlug);
            })
            ->orderBy('published_at', 'desc')
            ->paginate(12);

        $contentTypes = CmsContentType::where('is_active', true)
            ->orderBy('title')
            ->get();

        return view('cms.search', compact('contents', 'contentTypes', 'query', 'contentTypeSlug'));
    }
}