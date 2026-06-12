<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Website\FAQ;
use App\Models\Website\FAQCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class FAQController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FAQ::query()->with('category');

        // Search functionality
        if ($request->has('search') && $request->search) {
            $query->search($request->search);
        }

        // Filter by category
        if ($request->has('category_id') && $request->category_id) {
            $query->where('faq_category_id', $request->category_id);
        }

        // Filter by featured
        if ($request->has('featured')) {
            $query->where('is_featured', $request->boolean('featured'));
        }

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDir = $request->get('sort_dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $perPage = $request->get('per_page', 15);
        $faqs = $query->paginate($perPage);

        return response()->json([
            'data' => $faqs->items(),
            'meta' => [
                'current_page' => $faqs->currentPage(),
                'per_page' => $faqs->perPage(),
                'total' => $faqs->total(),
                'last_page' => $faqs->lastPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'faq_category_id' => 'nullable|exists:faq_categories,id',
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'sort_order' => 'integer|min:0',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_featured'] = $validated['is_featured'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $faq = FAQ::create($validated);
        $faq->load('category');

        return response()->json([
            'message' => 'FAQ created successfully',
            'data' => $faq
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(FAQ $faq): JsonResponse
    {
        $faq->load('category');

        return response()->json([
            'data' => $faq
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FAQ $faq): JsonResponse
    {
        $validated = $request->validate([
            'faq_category_id' => 'nullable|exists:faq_categories,id',
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'sort_order' => 'integer|min:0',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $faq->update($validated);
        $faq->load('category');

        return response()->json([
            'message' => 'FAQ updated successfully',
            'data' => $faq
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FAQ $faq): JsonResponse
    {
        $faq->delete();

        return response()->json([
            'message' => 'FAQ deleted successfully'
        ]);
    }

    /**
     * Bulk operations
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'faqs' => 'required|array',
            'faqs.*.id' => 'required|exists:faqs,id',
            'faqs.*.sort_order' => 'integer|min:0',
            'faqs.*.is_active' => 'boolean',
            'faqs.*.is_featured' => 'boolean',
        ]);

        foreach ($validated['faqs'] as $faqData) {
            FAQ::where('id', $faqData['id'])->update([
                'sort_order' => $faqData['sort_order'] ?? 0,
                'is_active' => $faqData['is_active'] ?? true,
                'is_featured' => $faqData['is_featured'] ?? false,
            ]);
        }

        return response()->json([
            'message' => 'FAQs updated successfully'
        ]);
    }

    /**
     * Get FAQ categories for dropdown
     */
    public function getCategories(): JsonResponse
    {
        $categories = FAQCategory::active()->get(['id', 'name', 'slug']);

        return response()->json([
            'data' => $categories
        ]);
    }

    /**
     * Get FAQ statistics
     */
    public function stats(): JsonResponse
    {
        $total = FAQ::count();
        $active = FAQ::active()->count();
        $featured = FAQ::where('is_featured', true)->count();
        $categories = FAQCategory::count();

        return response()->json([
            'data' => [
                'total_faqs' => $total,
                'active_faqs' => $active,
                'featured_faqs' => $featured,
                'total_categories' => $categories,
            ]
        ]);
    }
}
