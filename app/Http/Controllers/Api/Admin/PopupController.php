<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Website\Popup;
use App\Services\PopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * PopupController handles admin API endpoints for popup management.
 * 
 * Provides CRUD operations for marketing popups displayed on the public website.
 * All endpoints require authentication and appropriate permissions.
 */
class PopupController extends Controller
{
    protected PopupService $popupService;

    public function __construct(PopupService $popupService)
    {
        $this->popupService = $popupService;
    }

    /**
     * Display a listing of all popups.
     * 
     * GET /api/admin/popups
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [];

        if ($request->has('is_active')) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        if ($request->has('search') && $request->search) {
            $filters['search'] = $request->search;
        }

        $popups = $this->popupService->getAll($filters);

        return response()->json([
            'status' => 'success',
            'message' => 'Popups retrieved successfully',
            'data' => $popups,
            'meta' => [
                'total' => $popups->count(),
            ]
        ]);
    }


    /**
     * Store a newly created popup.
     * 
     * POST /api/admin/popups
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'image' => 'nullable|string|max:500',
                'cta_text' => 'nullable|string|max:100',
                'cta_link' => 'nullable|string|max:500',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'display_frequency' => 'nullable|string|in:always,once_per_session,once_per_day',
                'target_pages' => 'nullable|array',
                'target_pages.*' => 'string',
                'priority' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean',
            ]);

            $popup = $this->popupService->createPopup($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Popup created successfully',
                'data' => $popup
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create popup: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified popup.
     * 
     * GET /api/admin/popups/{id}
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $popup = $this->popupService->getPopupById($id);

        if (!$popup) {
            return response()->json([
                'status' => 'error',
                'message' => 'Popup not found',
                'error_code' => 'POPUP_NOT_FOUND'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Popup retrieved successfully',
            'data' => $popup
        ]);
    }

    /**
     * Update the specified popup.
     * 
     * PUT /api/admin/popups/{id}
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'content' => 'sometimes|required|string',
                'image' => 'nullable|string|max:500',
                'cta_text' => 'nullable|string|max:100',
                'cta_link' => 'nullable|string|max:500',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'display_frequency' => 'nullable|string|in:always,once_per_session,once_per_day',
                'target_pages' => 'nullable|array',
                'target_pages.*' => 'string',
                'priority' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean',
            ]);

            $popup = $this->popupService->updatePopup($id, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Popup updated successfully',
                'data' => $popup
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Popup not found',
                'error_code' => 'POPUP_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update popup: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Remove the specified popup.
     * 
     * DELETE /api/admin/popups/{id}
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->popupService->deletePopup($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Popup deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Popup not found',
                'error_code' => 'POPUP_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete popup: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the active status of a popup.
     * 
     * PATCH /api/admin/popups/{id}/toggle
     *
     * @param string $id
     * @return JsonResponse
     */
    public function toggle(string $id): JsonResponse
    {
        try {
            $popup = $this->popupService->toggleStatus($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Popup status toggled successfully',
                'data' => $popup
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Popup not found',
                'error_code' => 'POPUP_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle popup status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get popup statistics.
     * 
     * GET /api/admin/popups/statistics
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->popupService->getStatistics();

        return response()->json([
            'status' => 'success',
            'message' => 'Popup statistics retrieved successfully',
            'data' => $stats
        ]);
    }
}
