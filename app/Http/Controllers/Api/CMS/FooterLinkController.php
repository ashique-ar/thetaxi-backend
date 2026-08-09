<?php

namespace App\Http\Controllers\Api\CMS;

use App\Http\Controllers\Controller;
use App\Models\FooterLink;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class FooterLinkController extends Controller
{
    /**
     * Display a listing of footer links
     */
    public function index(Request $request): JsonResponse
    {
        $query = FooterLink::query();

        // Filter by footer section
        if ($request->filled('footer_section')) {
            $query->where('footer_section', $request->footer_section);
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

        $sortable = ['title', 'url', 'footer_section', 'sort_order', 'is_active'];
        $sortBy = $request->get('sort_by');
        $sortDirection = strtolower($request->get('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortBy && in_array($sortBy, $sortable, true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('footer_section')
                ->orderBy('sort_order')
                ->orderBy('title');
        }

        $footerLinks = $query->paginate($request->get('per_page', 15));

        return response()->json($footerLinks);
    }

    /**
     * Store a newly created footer link
     */
    public function store(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'footer_group_id' => 'nullable|exists:footer_groups,id', // if you have footer groups
            'title' => 'required|string|max:255',
            'url' => 'nullable|string|max:500',
            'route_name' => 'nullable|string|max:255',
            'route_params' => 'nullable|array',
            'target' => ['nullable', 'string', Rule::in(['_self', '_blank'])],
            'icon' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'footer_section' => ['required', 'string', Rule::in(array_keys(FooterLink::FOOTER_SECTIONS))],
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'additional_attributes' => 'nullable|array'
        ]);

        // Set default sort order if not provided
        if (!isset($validatedData['sort_order'])) {
            $maxOrder = FooterLink::where('footer_section', $validatedData['footer_section'])->max('sort_order');
            $validatedData['sort_order'] = ($maxOrder ?? 0) + 1;
        }

        $footerLink = FooterLink::create($validatedData);

        return response()->json([
            'message' => 'Footer link created successfully',
            'data' => $footerLink
        ], 201);
    }

    /**
     * Display the specified footer link
     */
    public function show(FooterLink $footerLink): JsonResponse
    {
        return response()->json(['data' => $footerLink]);
    }

    /**
     * Update the specified footer link
     */
    public function update(Request $request, FooterLink $footerLink): JsonResponse
    {
        $validatedData = $request->validate([
            'footer_group_id' => 'nullable|exists:footer_groups,id',
            'title' => 'required|string|max:255',
            'url' => 'nullable|string|max:500',
            'route_name' => 'nullable|string|max:255',
            'route_params' => 'nullable|array',
            'target' => ['nullable', 'string', Rule::in(['_self', '_blank'])],
            'icon' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'footer_section' => ['required', 'string', Rule::in(array_keys(FooterLink::FOOTER_SECTIONS))],
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'additional_attributes' => 'nullable|array'
        ]);

        $footerLink->update($validatedData);

        return response()->json([
            'message' => 'Footer link updated successfully',
            'data' => $footerLink
        ]);
    }

    /**
     * Remove the specified footer link
     */
    public function destroy(FooterLink $footerLink): JsonResponse
    {
        $footerLink->delete();

        return response()->json([
            'message' => 'Footer link deleted successfully'
        ]);
    }

    /**
     * Bulk update sort orders for footer links
     */
    public function updateSortOrder(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:footer_links,id',
            'items.*.sort_order' => 'required|integer|min:0',
            'items.*.footer_section' => ['required', 'string', Rule::in(array_keys(FooterLink::FOOTER_SECTIONS))]
        ]);

        foreach ($validatedData['items'] as $item) {
            FooterLink::where('id', $item['id'])->update([
                'sort_order' => $item['sort_order'],
                'footer_section' => $item['footer_section']
            ]);
        }

        return response()->json([
            'message' => 'Sort order updated successfully'
        ]);
    }

    /**
     * Get footer links grouped by section
     */
    public function grouped(Request $request): JsonResponse
    {
        $query = FooterLink::where('is_active', true);

        if ($request->filled('sections')) {
            $sections = explode(',', $request->sections);
            $query->whereIn('footer_section', $sections);
        }

        $footerLinks = $query->orderBy('footer_section')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('footer_section');

        return response()->json(['data' => $footerLinks]);
    }

    /**
     * Get footer sections with their available options
     */
    public function sections(): JsonResponse
    {
        return response()->json([
            'data' => FooterLink::FOOTER_SECTIONS
        ]);
    }

    /**
     * Duplicate a footer link
     */
    public function duplicate(FooterLink $footerLink): JsonResponse
    {
        $newLink = $footerLink->replicate();
        $newLink->title = $footerLink->title . ' (Copy)';
        $newLink->save();

        return response()->json([
            'message' => 'Footer link duplicated successfully',
            'data' => $newLink
        ], 201);
    }

    /**
     * Get social media links specifically
     */
    public function social(): JsonResponse
    {
        $socialLinks = FooterLink::social()->get();

        return response()->json(['data' => $socialLinks]);
    }

    /**
     * Get legal links specifically
     */
    public function legal(): JsonResponse
    {
        $legalLinks = FooterLink::legal()->get();

        return response()->json(['data' => $legalLinks]);
    }

    /**
     * Get contact links specifically
     */
    public function contact(): JsonResponse
    {
        $contactLinks = FooterLink::contact()->get();

        return response()->json(['data' => $contactLinks]);
    }
}
