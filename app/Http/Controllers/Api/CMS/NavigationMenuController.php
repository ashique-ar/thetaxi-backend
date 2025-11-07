<?php

namespace App\Http\Controllers\Api\CMS;

use App\Http\Controllers\Controller;
use App\Models\NavigationMenu;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class NavigationMenuController extends Controller
{
    /**
     * Display a listing of navigation menus
     */
    public function index(Request $request): JsonResponse
    {
        $query = NavigationMenu::query();

        // Filter by parent_id
        if ($request->has('parent_id')) {
            if ($request->parent_id === 'null' || $request->parent_id === null) {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $request->parent_id);
            }
        }

        // Filter by location (header/footer)
        if ($request->has('location')) {
            if ($request->location === 'header') {
                $query->where('show_in_header', true);
            } elseif ($request->location === 'footer') {
                $query->where('show_in_footer', true);
            }
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhere('url', 'ilike', "%{$search}%");
            });
        }

        // Include children if requested
        if ($request->boolean('with_children')) {
            $query->with(['children' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }]);
        }

        // Include parent if requested
        if ($request->boolean('with_parent')) {
            $query->with('parent');
        }

        $navigationMenus = $query->orderBy('sort_order')
            ->orderBy('title')
            ->paginate($request->get('per_page', 15));

        return response()->json($navigationMenus);
    }

    /**
     * Store a newly created navigation menu
     */
    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'parent_id' => 'nullable|exists:navigation_menus,id',
            'title' => 'required|string|max:255',
            'url' => 'nullable|string|max:500',
            'route_name' => 'nullable|string|max:255',
            'route_params' => 'nullable|array',
            'target' => ['nullable', 'string', Rule::in(['_self', '_blank'])],
            'icon' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'show_in_header' => 'boolean',
            'show_in_footer' => 'boolean',
            'permissions' => 'nullable|array'
        ]);

        // Set default sort order if not provided
        if (!isset($validatedData['sort_order'])) {
            $maxOrder = NavigationMenu::where('parent_id', $validatedData['parent_id'] ?? null)->max('sort_order');
            $validatedData['sort_order'] = ($maxOrder ?? 0) + 1;
        }

        $navigationMenu = NavigationMenu::create($validatedData);

        return response()->json([
            'message' => 'Navigation menu created successfully',
            'data' => $navigationMenu->load('parent', 'children')
        ], 201);
    }

    /**
     * Display the specified navigation menu
     */
    public function show(NavigationMenu $navigationMenu): JsonResponse
    {
        $navigationMenu->load(['parent', 'children' => function ($q) {
            $q->where('is_active', true)->orderBy('sort_order');
        }]);

        return response()->json(['data' => $navigationMenu]);
    }

    /**
     * Update the specified navigation menu
     */
    public function update(Request $request, NavigationMenu $navigationMenu): JsonResponse
    {
        $validatedData = $request->validate([
            'parent_id' => 'nullable|exists:navigation_menus,id',
            'title' => 'required|string|max:255',
            'url' => 'nullable|string|max:500',
            'route_name' => 'nullable|string|max:255',
            'route_params' => 'nullable|array',
            'target' => ['nullable', 'string', Rule::in(['_self', '_blank'])],
            'icon' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'show_in_header' => 'boolean',
            'show_in_footer' => 'boolean',
            'permissions' => 'nullable|array'
        ]);

        // Prevent circular reference
        if ($validatedData['parent_id'] === $navigationMenu->id) {
            return response()->json([
                'message' => 'Cannot set menu item as its own parent'
            ], 422);
        }

        $navigationMenu->update($validatedData);

        return response()->json([
            'message' => 'Navigation menu updated successfully',
            'data' => $navigationMenu->load('parent', 'children')
        ]);
    }

    /**
     * Remove the specified navigation menu
     */
    public function destroy(NavigationMenu $navigationMenu): JsonResponse
    {
        // Check if menu has children
        if ($navigationMenu->hasChildren()) {
            return response()->json([
                'message' => 'Cannot delete navigation menu with children. Please delete or reassign children first.'
            ], 422);
        }

        $navigationMenu->delete();

        return response()->json([
            'message' => 'Navigation menu deleted successfully'
        ]);
    }

    /**
     * Bulk update sort orders
     */
    public function updateSortOrder(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:navigation_menus,id',
            'items.*.sort_order' => 'required|integer|min:0',
            'items.*.parent_id' => 'nullable|exists:navigation_menus,id'
        ]);

        foreach ($validatedData['items'] as $item) {
            NavigationMenu::where('id', $item['id'])->update([
                'sort_order' => $item['sort_order'],
                'parent_id' => $item['parent_id'] ?? null
            ]);
        }

        return response()->json([
            'message' => 'Sort order updated successfully'
        ]);
    }

    /**
     * Get navigation tree structure
     */
    public function tree(Request $request): JsonResponse
    {
        $location = $request->get('location'); // header, footer, or all

        $query = NavigationMenu::with(['children' => function ($q) {
            $q->where('is_active', true)->orderBy('sort_order');
        }])->where('is_active', true)->whereNull('parent_id');

        if ($location === 'header') {
            $query->where('show_in_header', true);
        } elseif ($location === 'footer') {
            $query->where('show_in_footer', true);
        }

        $tree = $query->orderBy('sort_order')->get();

        return response()->json(['data' => $tree]);
    }

    /**
     * Duplicate a navigation menu item
     */
    public function duplicate(NavigationMenu $navigationMenu): JsonResponse
    {
        $newMenu = $navigationMenu->replicate();
        $newMenu->title = $navigationMenu->title . ' (Copy)';
        $newMenu->save();

        return response()->json([
            'message' => 'Navigation menu duplicated successfully',
            'data' => $newMenu
        ], 201);
    }
}
