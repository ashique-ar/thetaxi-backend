<?php

namespace App\Http\Controllers\Api\Booking\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait BookingDiscountTrait
{
    public function applyPromoCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'promo_code' => 'required|string|max:50',
            'subtotal' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3|exists:currencies,code',
            'customer_id' => 'nullable|uuid|exists:customers,id',
        ]);

        $currency = strtoupper($validated['currency']);
        $baseSubtotal = (float) $validated['subtotal']
            * app(\App\Services\CurrencyService::class)->getExchangeRate($currency, 'LKR');
        $promoService = app(\App\Services\PromoCodeService::class);
        $customerId = $validated['customer_id']
            ?? \App\Models\Customer::where('user_id', Auth::id())->value('id');
        $result = $promoService->validatePromoCode($validated['promo_code'], $baseSubtotal, $customerId);

        if (!($result['valid'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'] ?? 'This promo code cannot be applied.',
                'error_code' => $result['error_code'] ?? 'PROMO_CODE_INVALID',
                'details' => $result['details'] ?? null,
            ], 422);
        }

        $promo = $promoService->getByCode($validated['promo_code']);
        if (!$promo) {
            return response()->json(['status' => 'error', 'message' => 'This promo code is no longer available.'], 422);
        }

        $discountBase = $promoService->calculateDiscount($promo, $baseSubtotal);
        $discount = round($discountBase * app(\App\Services\CurrencyService::class)->getExchangeRate('LKR', $currency), 2);

        return response()->json([
            'status' => 'success',
            'data' => [
                'code' => $promo->code,
                'name' => $promo->name,
                'description' => $promo->description,
                'discount' => $discount,
                'discount_base' => $discountBase,
                'currency' => $currency,
                'discount_type' => $promo->discount_type,
                'discount_value' => (float) $promo->discount_value,
                'order_amount_base' => round($baseSubtotal, 2),
            ],
            'message' => 'Promo code applied successfully.',
        ]);
    }

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
