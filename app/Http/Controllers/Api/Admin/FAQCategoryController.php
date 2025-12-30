<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Website\FaqCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class FAQCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FAQCategory::query()->withCount('faqs');

        // Search functionality
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%");
            });
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
        $categories = $query->paginate($perPage);

        return response()->json([
            'data' => $categories->items(),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'last_page' => $categories->lastPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:f_a_q_categories,slug',
            'description' => 'nullable|string',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);

        // Generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $category = FAQCategory::create($validated);
        $category->loadCount('faqs');

        return response()->json([
            'message' => 'FAQ Category created successfully',
            'data' => $category
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(FAQCategory $faqCategory): JsonResponse
    {
        $faqCategory->loadCount('faqs');
        
        return response()->json([
            'data' => $faqCategory
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FAQCategory $faqCategory): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:f_a_q_categories,slug,' . $faqCategory->id,
            'description' => 'nullable|string',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
        ]);

        // Generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $faqCategory->update($validated);
        $faqCategory->loadCount('faqs');

        return response()->json([
            'message' => 'FAQ Category updated successfully',
            'data' => $faqCategory
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FAQCategory $faqCategory): JsonResponse
    {
        // Check if category has FAQs
        if ($faqCategory->faqs()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category that contains FAQs. Please move or delete the FAQs first.'
            ], 422);
        }

        $faqCategory->delete();

        return response()->json([
            'message' => 'FAQ Category deleted successfully'
        ]);
    }

    /**
     * Bulk update sort orders
     */
    public function bulkUpdateSort(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:f_a_q_categories,id',
            'categories.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['categories'] as $categoryData) {
            FAQCategory::where('id', $categoryData['id'])
                ->update(['sort_order' => $categoryData['sort_order']]);
        }

        return response()->json([
            'message' => 'FAQ Categories order updated successfully'
        ]);
    }

    /**
     * Get FAQs for a specific category
     */
    public function faqs(FAQCategory $faqCategory, Request $request): JsonResponse
    {
        $query = $faqCategory->faqs();

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $query->orderBy('sort_order');
        $faqs = $query->get();

        return response()->json([
            'data' => $faqs
        ]);
    }
}
