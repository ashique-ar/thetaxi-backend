<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Services\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * PromoCodeController handles admin API endpoints for promo code management.
 * 
 * Provides CRUD operations, status toggling, and analytics for promotional codes.
 * All endpoints require authentication and appropriate permissions.
 */
class PromoCodeController extends Controller
{
    protected PromoCodeService $promoCodeService;

    public function __construct(PromoCodeService $promoCodeService)
    {
        $this->promoCodeService = $promoCodeService;
    }

    /**
     * Display a listing of all promo codes.
     * 
     * GET /api/admin/promo-codes
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

        if ($request->has('discount_type')) {
            $filters['discount_type'] = $request->discount_type;
        }

        if ($request->has('search') && $request->search) {
            $filters['search'] = $request->search;
        }

        $promoCodes = $this->promoCodeService->getAll($filters);

        return response()->json([
            'status' => 'success',
            'message' => 'Promo codes retrieved successfully',
            'data' => $promoCodes,
            'meta' => [
                'total' => $promoCodes->count(),
            ]
        ]);
    }


    /**
     * Store a newly created promo code.
     * 
     * POST /api/admin/promo-codes
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:50',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'discount_type' => 'required|string|in:percentage,fixed',
                'discount_value' => 'required|numeric|min:0.01',
                'minimum_order_amount' => 'nullable|numeric|min:0',
                'maximum_discount_amount' => 'nullable|numeric|min:0.01',
                'usage_limit' => 'nullable|integer|min:1',
                'usage_limit_per_customer' => 'nullable|integer|min:1',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'is_active' => 'nullable|boolean',
            ]);

            $promoCode = $this->promoCodeService->create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Promo code created successfully',
                'data' => $promoCode
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
                'message' => 'Failed to create promo code: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified promo code.
     * 
     * GET /api/admin/promo-codes/{id}
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $promoCode = $this->promoCodeService->getById($id);

        if (!$promoCode) {
            return response()->json([
                'status' => 'error',
                'message' => 'Promo code not found',
                'error_code' => 'PROMO_CODE_NOT_FOUND'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Promo code retrieved successfully',
            'data' => $promoCode
        ]);
    }

    /**
     * Update the specified promo code.
     * 
     * PUT /api/admin/promo-codes/{id}
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'sometimes|required|string|max:50',
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'discount_type' => 'sometimes|required|string|in:percentage,fixed',
                'discount_value' => 'sometimes|required|numeric|min:0.01',
                'minimum_order_amount' => 'nullable|numeric|min:0',
                'maximum_discount_amount' => 'nullable|numeric|min:0.01',
                'usage_limit' => 'nullable|integer|min:1',
                'usage_limit_per_customer' => 'nullable|integer|min:1',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'is_active' => 'nullable|boolean',
            ]);

            $promoCode = $this->promoCodeService->update($id, $validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Promo code updated successfully',
                'data' => $promoCode
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
                'message' => 'Promo code not found',
                'error_code' => 'PROMO_CODE_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update promo code: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Soft delete the specified promo code.
     * 
     * DELETE /api/admin/promo-codes/{id}
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->promoCodeService->softDelete($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Promo code deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Promo code not found',
                'error_code' => 'PROMO_CODE_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete promo code: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle the active status of a promo code.
     * 
     * PATCH /api/admin/promo-codes/{id}/toggle
     *
     * @param string $id
     * @return JsonResponse
     */
    public function toggle(string $id): JsonResponse
    {
        try {
            $promoCode = $this->promoCodeService->toggleStatus($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Promo code status toggled successfully',
                'data' => $promoCode
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Promo code not found',
                'error_code' => 'PROMO_CODE_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle promo code status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get analytics for a specific promo code.
     * 
     * GET /api/admin/promo-codes/{id}/analytics
     *
     * @param string $id
     * @return JsonResponse
     */
    public function analytics(string $id): JsonResponse
    {
        try {
            $analytics = $this->promoCodeService->getAnalytics($id);

            return response()->json([
                'status' => 'success',
                'message' => 'Promo code analytics retrieved successfully',
                'data' => $analytics
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Promo code not found',
                'error_code' => 'PROMO_CODE_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get overall promo code statistics.
     * 
     * GET /api/admin/promo-codes/statistics
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->promoCodeService->getStatistics();

        return response()->json([
            'status' => 'success',
            'message' => 'Promo code statistics retrieved successfully',
            'data' => $stats
        ]);
    }
}
