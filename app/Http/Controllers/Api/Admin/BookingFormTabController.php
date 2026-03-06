<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingFormTab;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class BookingFormTabController extends Controller
{
    /**
     * Get all booking form tabs
     */
    public function index(): JsonResponse
    {
        $tabs = BookingFormTab::with('serviceType')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tabs,
        ]);
    }

    /**
     * Get a single tab
     */
    public function show(string $id): JsonResponse
    {
        $tab = BookingFormTab::with('serviceType')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $tab,
        ]);
    }

    /**
     * Update a tab
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'label' => 'sometimes|string|max:255',
            'enabled' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'icon_type' => 'sometimes|string|in:svg,icon-class,image',
            'icon_data' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tab = BookingFormTab::findOrFail($id);
        $tab->update($request->only([
            'label',
            'enabled',
            'sort_order',
            'icon_type',
            'icon_data',
            'metadata',
        ]));

        // Clear cache
        Cache::forget('booking_form_tabs');

        return response()->json([
            'success' => true,
            'message' => 'Tab updated successfully',
            'data' => $tab->fresh(),
        ]);
    }

    /**
     * Toggle tab enabled status
     */
    public function toggle(string $id): JsonResponse
    {
        $tab = BookingFormTab::findOrFail($id);
        $tab->toggle();

        // Clear cache
        Cache::forget('booking_form_tabs');

        return response()->json([
            'success' => true,
            'message' => 'Tab status toggled successfully',
            'data' => $tab,
        ]);
    }

    /**
     * Reorder tabs
     */
    public function reorder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tab_ids' => 'required|array',
            'tab_ids.*' => 'required|string|exists:booking_form_tabs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        BookingFormTab::updateOrder($request->tab_ids);

        // Clear cache
        Cache::forget('booking_form_tabs');

        return response()->json([
            'success' => true,
            'message' => 'Tabs reordered successfully',
            'data' => BookingFormTab::orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Bulk update tabs
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tabs' => 'required|array',
            'tabs.*.id' => 'required|string|exists:booking_form_tabs,id',
            'tabs.*.enabled' => 'sometimes|boolean',
            'tabs.*.sort_order' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        foreach ($request->tabs as $tabData) {
            $tab = BookingFormTab::find($tabData['id']);
            if ($tab) {
                $tab->update(array_intersect_key($tabData, array_flip(['enabled', 'sort_order', 'label'])));
            }
        }

        // Clear cache
        Cache::forget('booking_form_tabs');

        return response()->json([
            'success' => true,
            'message' => 'Tabs updated successfully',
            'data' => BookingFormTab::orderBy('sort_order')->get(),
        ]);
    }
}
