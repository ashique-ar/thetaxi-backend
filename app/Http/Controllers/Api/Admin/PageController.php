<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\PerformanceOptimizationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class PageController extends Controller
{
    protected PerformanceOptimizationService $performanceService;

    public function __construct(PerformanceOptimizationService $performanceService)
    {
        $this->performanceService = $performanceService;
    }

    /**
     * Display a listing of pages
     */
    public function index(Request $request): JsonResponse
    {
        $query = Page::query();

        // Apply filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->visibility);
        }

        if ($request->filled('template')) {
            $query->where('template', $request->template);
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        }

        if ($request->filled('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        if ($request->filled('is_in_menu')) {
            $query->where('is_in_menu', $request->boolean('is_in_menu'));
        }

        // Include relationships
        $query->with(['parent:id,title,slug', 'creator:id,name', 'updater:id,name']);

        // Sorting
        $sortBy = $request->get('sort_by', 'updated_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        
        $allowedSorts = ['title', 'slug', 'status', 'published_at', 'created_at', 'updated_at', 'sort_order', 'page_views'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $pages = $query->paginate($perPage);

        return response()->json([
            'data' => $pages->items(),
            'meta' => [
                'current_page' => $pages->currentPage(),
                'last_page' => $pages->lastPage(),
                'per_page' => $pages->perPage(),
                'total' => $pages->total(),
                'from' => $pages->firstItem(),
                'to' => $pages->lastItem(),
            ]
        ]);
    }

    /**
     * Store a newly created page
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:pages,slug',
            'content' => 'required|string',
            'excerpt' => 'nullable|string|max:500',
            'featured_image' => 'nullable|url',
            'template' => 'nullable|string|in:default,full-width,landing,blog',
            'parent_id' => 'nullable|uuid|exists:pages,id',
            'status' => 'required|in:draft,published,private,archived',
            'visibility' => 'required|in:public,private,password',
            'password' => 'nullable|string|min:6|required_if:visibility,password',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:255',
            'custom_fields' => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0',
            'is_homepage' => 'boolean',
            'is_in_menu' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $data = $validator->validated();
            $data['created_user_id'] = Auth::id();
            $data['updated_user_id'] = Auth::id();

            // Handle homepage setting
            if (!empty($data['is_homepage'])) {
                Page::where('is_homepage', true)->update(['is_homepage' => false]);
            }

            // Set published_at if status is published and not set
            if ($data['status'] === 'published' && !isset($data['published_at'])) {
                $data['published_at'] = now();
            }

            $page = Page::create($data);

            // Clear related caches
            $this->clearPageCaches($page);

            DB::commit();

            return response()->json([
                'message' => 'Page created successfully',
                'data' => $page->load(['parent:id,title,slug', 'creator:id,name'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create page',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified page
     */
    public function show(Page $page): JsonResponse
    {
        $page->load([
            'parent:id,title,slug',
            'children:id,title,slug,sort_order',
            'creator:id,name',
            'updater:id,name'
        ]);

        return response()->json([
            'data' => array_merge($page->toArray(), [
                'stats' => $page->getStats(),
                'seo_meta' => $page->getSeoMeta(),
                'breadcrumb' => $page->getBreadcrumb()
            ])
        ]);
    }

    /**
     * Update the specified page
     */
    public function update(Request $request, Page $page): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:pages,slug,' . $page->id,
            'content' => 'sometimes|required|string',
            'excerpt' => 'nullable|string|max:500',
            'featured_image' => 'nullable|url',
            'template' => 'nullable|string|in:default,full-width,landing,blog',
            'parent_id' => 'nullable|uuid|exists:pages,id',
            'status' => 'sometimes|required|in:draft,published,private,archived',
            'visibility' => 'sometimes|required|in:public,private,password',
            'password' => 'nullable|string|min:6|required_if:visibility,password',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'seo_keywords' => 'nullable|string|max:255',
            'custom_fields' => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0',
            'is_homepage' => 'boolean',
            'is_in_menu' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $data = $validator->validated();
            $data['updated_user_id'] = Auth::id();

            // Handle homepage setting
            if (!empty($data['is_homepage']) && !$page->is_homepage) {
                Page::where('is_homepage', true)->update(['is_homepage' => false]);
            }

            // Set published_at if status changed to published
            if (isset($data['status']) && $data['status'] === 'published' && !$page->published_at) {
                $data['published_at'] = now();
            }

            $page->update($data);

            // Clear related caches
            $this->clearPageCaches($page);

            DB::commit();

            return response()->json([
                'message' => 'Page updated successfully',
                'data' => $page->fresh(['parent:id,title,slug', 'creator:id,name', 'updater:id,name'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update page',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified page
     */
    public function destroy(Page $page): JsonResponse
    {
        if ($page->is_homepage) {
            return response()->json([
                'message' => 'Cannot delete the homepage'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Move children to parent's level or make them orphans
            if ($page->children()->count() > 0) {
                $page->children()->update(['parent_id' => $page->parent_id]);
            }

            $slug = $page->slug;
            $page->delete();

            // Clear related caches
            $this->clearPageCaches($page);

            DB::commit();

            return response()->json([
                'message' => 'Page deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete page',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update pages
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'updates' => 'required|array',
            'updates.*.id' => 'required|uuid|exists:pages,id',
            'updates.*.data' => 'required|array',
            'updates.*.data.status' => 'sometimes|in:draft,published,private,archived',
            'updates.*.data.is_active' => 'sometimes|boolean',
            'updates.*.data.is_featured' => 'sometimes|boolean',
            'updates.*.data.is_in_menu' => 'sometimes|boolean',
            'updates.*.data.sort_order' => 'sometimes|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $updates = $validator->validated()['updates'];
            $updated = [];

            foreach ($updates as $update) {
                $page = Page::find($update['id']);
                if ($page) {
                    $data = $update['data'];
                    $data['updated_user_id'] = Auth::id();
                    
                    $page->update($data);
                    $updated[] = $page->fresh();
                    
                    // Clear caches for this page
                    $this->clearPageCaches($page);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Pages updated successfully',
                'data' => $updated
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update pages',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get page statistics
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => Page::count(),
            'published' => Page::where('status', 'published')->count(),
            'draft' => Page::where('status', 'draft')->count(),
            'private' => Page::where('status', 'private')->count(),
            'archived' => Page::where('status', 'archived')->count(),
            'featured' => Page::where('is_featured', true)->count(),
            'in_menu' => Page::where('is_in_menu', true)->count(),
            'total_views' => Page::sum('page_views'),
            'recent_pages' => Page::orderBy('created_at', 'desc')->take(5)->get(['id', 'title', 'slug', 'status', 'created_at']),
            'popular_pages' => Page::orderBy('page_views', 'desc')->take(5)->get(['id', 'title', 'slug', 'page_views'])
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Clear page-related caches
     */
    protected function clearPageCaches(Page $page): void
    {
        $cacheKeys = [
            "page_content_{$page->slug}",
            'navigation_header',
            'navigation_footer',
            'sitemap'
        ];

        $this->performanceService->clearCache($cacheKeys);
    }
}