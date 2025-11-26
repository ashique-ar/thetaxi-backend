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
    public function index(string $contentTypeSlug, Request $request): View
    {
        Log::info("CMS Index: type={$contentTypeSlug}", $request->all());
        
        $contentType = CmsContentType::where('slug', $contentTypeSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $query = CmsContent::published()
            ->byType($contentTypeSlug)
            ->with(['contentType']);

        // Search functionality
        if ($request->filled('search')) {
            $searchTerm = $request->get('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                  ->orWhere('excerpt', 'like', '%' . $searchTerm . '%')
                  ->orWhere('body', 'like', '%' . $searchTerm . '%')
                  ->orWhere('author', 'like', '%' . $searchTerm . '%');
            });
        }

        // Featured filter
        if ($request->filled('featured') && $request->get('featured') === '1') {
            $query->where('is_featured', true);
        }

        // Sorting
        $sort = $request->get('sort', 'latest');
        switch ($sort) {
            case 'oldest':
                $query->orderBy('published_at', 'asc')
                      ->orderBy('created_at', 'asc');
                break;
            case 'title':
                $query->orderBy('title', 'asc');
                break;
            case 'latest':
            default:
                $query->orderBy('is_featured', 'desc')
                      ->orderBy('published_at', 'desc')
                      ->orderBy('created_at', 'desc');
                break;
        }

        $contents = $query->paginate(12)->appends($request->query());

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
        $relatedContents = CmsContent::published()
            ->byType($contentTypeSlug)
            ->where('id', '!=', $content->id)
            ->orderBy('is_featured', 'desc')
            ->orderBy('published_at', 'desc')
            ->limit(6)
            ->get();

        return view('cms.show', compact('contentType', 'content', 'relatedContents'));
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
