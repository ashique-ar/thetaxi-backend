<?php

namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Services\PopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PopupController handles public API endpoints for popup display on the website.
 * 
 * Provides endpoints for retrieving active popups for display on the public website.
 * These endpoints are public and do not require authentication.
 */
class PopupController extends Controller
{
    protected PopupService $popupService;

    public function __construct(PopupService $popupService)
    {
        $this->popupService = $popupService;
    }

    /**
     * Get active popups for the public website.
     * 
     * GET /api/popups/active
     * 
     * Returns active popups filtered by page, ordered by priority.
     * Results are cached for performance.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getActivePopups(Request $request): JsonResponse
    {
        $page = $request->get('page', 'all');

        // Validate page parameter
        $validPages = ['all', 'homepage', 'checkout', 'booking', 'search'];
        if (!in_array($page, $validPages)) {
            $page = 'all';
        }

        $popups = $this->popupService->getActivePopups($page);

        return response()->json([
            'status' => 'success',
            'message' => 'Active popups retrieved successfully',
            'data' => $popups,
            'meta' => [
                'page' => $page,
                'count' => $popups->count(),
            ]
        ]);
    }

    /**
     * Get the highest priority popup for a specific page.
     * 
     * GET /api/popups/highest-priority
     * 
     * Returns only the single highest priority active popup for the specified page.
     * Useful for displaying one popup at a time.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getHighestPriorityPopup(Request $request): JsonResponse
    {
        $page = $request->get('page', 'all');

        // Validate page parameter
        $validPages = ['all', 'homepage', 'checkout', 'booking', 'search'];
        if (!in_array($page, $validPages)) {
            $page = 'all';
        }

        $popup = $this->popupService->getHighestPriorityPopup($page);

        if (!$popup) {
            return response()->json([
                'status' => 'success',
                'message' => 'No active popup found for this page',
                'data' => null
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Popup retrieved successfully',
            'data' => $popup
        ]);
    }
}
