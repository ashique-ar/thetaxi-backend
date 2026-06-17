<?php

namespace App\Http\Controllers\Api\Booking\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait BookingDiscountTrait
{
    public function applyGamifyDiscount(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|uuid|exists:bookings,id',
            'points_to_redeem' => 'required|integer|min:1',
        ]);

        try {
            $result = $this->bookingFlowService->applyGamifyDiscount(
                $request->booking_id,
                $request->points_to_redeem,
                Auth::id()
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Gamify discount applied successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error applying gamify discount: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to apply gamify discount',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCustomerLoyaltyInfo(Request $request, string $customerId): JsonResponse
    {
        try {
            $loyaltyInfo = $this->bookingFlowService->getCustomerLoyaltyInfo($customerId);

            return response()->json([
                'status' => 'success',
                'data' => $loyaltyInfo,
                'message' => 'Customer loyalty information retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting customer loyalty info: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get customer loyalty information',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function removeDiscount(Request $request): JsonResponse
    {
        $request->validate([
            'discount_id' => 'required|uuid|exists:booking_discounts,id',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->bookingFlowService->removeDiscount(
                $request->discount_id,
                $request->reason
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Discount removed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing discount: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove discount',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function getBookingDiscountSummary(Request $request, string $bookingId): JsonResponse
    {
        try {
            $summary = $this->bookingFlowService->getBookingDiscountSummary($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $summary,
                'message' => 'Booking discount summary retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking discount summary: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get booking discount summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processLoyaltyPointsEarning(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|uuid|exists:bookings,id',
        ]);

        try {
            $result = $this->bookingFlowService->processLoyaltyPointsEarning($request->booking_id);

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Loyalty points processed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing loyalty points: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process loyalty points',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
