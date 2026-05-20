<?php

namespace App\Services;


use App\Models\Booking\BookingDiscount;
use App\Models\CustomerLoyaltyPoint;
use App\Models\LoyaltyTier;
use App\Models\Customer;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class DiscountService
{

    /**
     * Apply discount to booking with enhanced pricing integration
     */
    public function applyDiscount(array $discountData, string $bookingId = null): array
    {
        DB::beginTransaction();

        try {
            $discountType = $discountData['type'] ?? 'manual';
            $userId = Auth::id();

            // Get current pricing breakdown if available
            $currentPricingBreakdown = $discountData['current_pricing_breakdown'] ?? null;
            $originalAmount = $discountData['order_amount'] ?? 0;

            switch ($discountType) {
                case 'manual':
                    $result = $this->applyManualDiscount($discountData, $bookingId, $userId);
                    break;
                case 'loyalty_points':
                    $result = $this->applyLoyaltyPointsDiscount($discountData, $bookingId, $userId);
                    break;
                default:
                    throw new \Exception('Invalid discount type');
            }

            // Enhance result with detailed pricing breakdown
            if ($currentPricingBreakdown) {
                $result['pricing_breakdown'] = $this->calculateDetailedPricingBreakdown(
                    $currentPricingBreakdown,
                    $result['discount_amount'],
                    $result['discount'],
                    $originalAmount
                );
            }

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error applying discount: ' . $e->getMessage(), [
                'discount_data' => $discountData,
                'booking_id' => $bookingId
            ]);
            throw $e;
        }
    }

    /**
     * Apply manual discount
     */
    private function applyManualDiscount(array $discountData, ?string $bookingId, string $userId): array
    {
        $discountName = $discountData['name'] ?? 'Manual Discount';
        $discountType = $discountData['discount_type']; // 'percentage' or 'fixed_amount'
        $discountValue = $discountData['value'];
        $orderAmount = $discountData['order_amount'];
        $reason = $discountData['reason'] ?? '';

        if ($discountType === 'percentage') {
            $discountAmount = $orderAmount * ($discountValue / 100);
        } else {
            $discountAmount = min($discountValue, $orderAmount);
        }

        $finalAmount = $orderAmount - $discountAmount;

        $bookingDiscount = BookingDiscount::create([
            'booking_id' => $bookingId,
            'discount_name' => $discountName,
            'description' => $reason,
            'type' => $discountType,
            'value' => $discountValue,
            'discount_amount' => $discountAmount,
            'original_amount' => $orderAmount,
            'final_amount' => $finalAmount,
            'application_method' => 'manual',
            'applied_by' => $userId,
            'requires_approval' => true, // Manual discounts always require approval
            'approval_status' => 'pending',
            'calculation_details' => [
                'manual_discount' => true,
                'reason' => $reason,
                'applied_at' => now()->toISOString(),
            ],
        ]);

        return [
            'success' => true,
            'discount' => $bookingDiscount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'requires_approval' => true,
            'message' => 'Manual discount applied successfully',
        ];
    }

    /**
     * Apply loyalty points discount
     */
    private function applyLoyaltyPointsDiscount(array $discountData, ?string $bookingId, string $userId): array
    {
        $customerId = $discountData['customer_id'];
        $pointsToRedeem = $discountData['points_to_redeem'];
        $orderAmount = $discountData['order_amount'];

        $customerLoyalty = CustomerLoyaltyPoint::where('customer_id', $customerId)->first();

        if (!$customerLoyalty) {
            throw new \Exception('Customer loyalty account not found');
        }

        if (!$customerLoyalty->canRedeemPoints($pointsToRedeem)) {
            throw new \Exception('Insufficient loyalty points for redemption');
        }

        // Calculate redemption value (1 point = 0.01 LKR by default)
        $pointValue = 0.01;
        $tier = LoyaltyTier::where('name', $customerLoyalty->current_tier)->first();
        if ($tier) {
            $pointValue = $pointValue * $tier->points_redemption_multiplier;
        }

        $redemptionValue = $pointsToRedeem * $pointValue;
        $discountAmount = min($redemptionValue, $orderAmount);
        $finalAmount = $orderAmount - $discountAmount;

        // Create booking discount record
        $bookingDiscount = BookingDiscount::create([
            'booking_id' => $bookingId,
            'discount_name' => 'Loyalty Points Redemption',
            'description' => "Redeemed {$pointsToRedeem} loyalty points",
            'type' => 'loyalty_points',
            'value' => $pointsToRedeem,
            'discount_amount' => $discountAmount,
            'original_amount' => $orderAmount,
            'final_amount' => $finalAmount,
            'loyalty_points_used' => $pointsToRedeem,
            'points_to_amount_rate' => $pointValue,
            'application_method' => 'loyalty_redemption',
            'applied_by' => $userId,
            'requires_approval' => false,
            'approval_status' => 'not_required',
            'calculation_details' => [
                'points_redeemed' => $pointsToRedeem,
                'point_value' => $pointValue,
                'redemption_value' => $redemptionValue,
                'tier_multiplier' => $tier ? $tier->points_redemption_multiplier : 1,
                'applied_at' => now()->toISOString(),
            ],
        ]);

        // Redeem the points
        $customerLoyalty->redeemPoints(
            $pointsToRedeem,
            $discountAmount,
            'Booking discount redemption',
            $bookingId
        );

        return [
            'success' => true,
            'discount' => $bookingDiscount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'points_redeemed' => $pointsToRedeem,
            'remaining_points' => $customerLoyalty->fresh()->available_points,
            'requires_approval' => false,
            'message' => 'Loyalty points redeemed successfully',
        ];
    }

    /**
     * Remove discount from booking
     */
    public function removeDiscount(string $discountId, ?string $reason = null): array
    {
        DB::beginTransaction();

        try {
            $discount = BookingDiscount::findOrFail($discountId);

            // If it's a loyalty points discount, refund the points
            if ($discount->type === 'loyalty_points' && $discount->loyalty_points_used > 0) {
                $booking = $discount->booking;
                if ($booking) {
                    $customerLoyalty = CustomerLoyaltyPoint::where('customer_id', $booking->customer_id)->first();
                    if ($customerLoyalty) {
                        $customerLoyalty->earnPoints(
                            $discount->loyalty_points_used,
                            'Refund from cancelled discount: ' . ($reason ?? 'Discount removed'),
                            $booking->id
                        );
                    }
                }
            }

            $discount->update([
                'is_active' => false,
                'internal_notes' => 'Removed: ' . ($reason ?? 'No reason provided'),
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Discount removed successfully',
                'refunded_points' => $discount->type === 'loyalty_points' ? $discount->loyalty_points_used : 0,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error removing discount: ' . $e->getMessage(), [
                'discount_id' => $discountId,
                'reason' => $reason
            ]);
            throw $e;
        }
    }

    /**
     * Get customer loyalty information
     */
    public function getCustomerLoyaltyInfo(string $customerId): array
    {
        $customerLoyalty = CustomerLoyaltyPoint::where('customer_id', $customerId)->first();

        if (!$customerLoyalty) {
            // Create default loyalty account
            $defaultTier = LoyaltyTier::where('is_default', true)->first();
            $customerLoyalty = CustomerLoyaltyPoint::create([
                'customer_id' => $customerId,
                'current_tier' => $defaultTier ? $defaultTier->name : 'bronze',
                'earning_rate_multiplier' => $defaultTier ? $defaultTier->points_earning_multiplier : 1.0,
                'redemption_rate_multiplier' => $defaultTier ? $defaultTier->points_redemption_multiplier : 1.0,
            ]);
        }

        $tier = LoyaltyTier::where('name', $customerLoyalty->current_tier)->first();
        $nextTier = $tier?->getNextTier();

        // Calculate redemption options
        $redemptionOptions = [];
        if ($customerLoyalty->available_points > 0) {
            $pointValue = 0.01 * $customerLoyalty->redemption_rate_multiplier;
            
            // Different redemption tiers
            $redemptionTiers = [50, 100, 250, 500, 1000];
            foreach ($redemptionTiers as $points) {
                if ($points <= $customerLoyalty->available_points) {
                    $redemptionOptions[] = [
                        'points' => $points,
                        'value' => $points * $pointValue,
                        'formatted_value' => 'LKR ' . number_format($points * $pointValue, 2),
                    ];
                }
            }
        }

        return [
            'customer_id' => $customerId,
            'total_points' => $customerLoyalty->total_points,
            'available_points' => $customerLoyalty->available_points,
            'redeemed_points' => $customerLoyalty->redeemed_points,
            'current_tier' => [
                'name' => $customerLoyalty->current_tier,
                'display_name' => $tier?->display_name ?? ucfirst($customerLoyalty->current_tier),
                'color_code' => $tier?->color_code,
                'icon' => $tier?->icon,
                'earning_multiplier' => $customerLoyalty->earning_rate_multiplier,
                'redemption_multiplier' => $customerLoyalty->redemption_rate_multiplier,
            ],
            'next_tier' => $nextTier ? [
                'name' => $nextTier->name,
                'display_name' => $nextTier->display_name,
                'points_required' => $nextTier->min_points - $customerLoyalty->total_points,
                'progress_percentage' => min(100, ($customerLoyalty->total_points / $nextTier->min_points) * 100),
            ] : null,
            'redemption_options' => $redemptionOptions,
            'estimated_value' => $customerLoyalty->available_points * (0.01 * $customerLoyalty->redemption_rate_multiplier),
            'total_bookings' => $customerLoyalty->total_bookings,
            'total_spent' => $customerLoyalty->total_spent,
            'last_activity_date' => $customerLoyalty->last_activity_date,
            'is_vip' => $customerLoyalty->is_vip,
        ];
    }

    /**
     * Calculate and process loyalty points earning for a completed booking
     */
    public function processLoyaltyPointsEarning(string $bookingId): array
    {
        $booking = Booking::findOrFail($bookingId);
        
        if ($booking->status !== 'completed') {
            throw new \Exception('Booking must be completed to earn loyalty points');
        }

        $customerLoyalty = CustomerLoyaltyPoint::firstOrCreate(
            ['customer_id' => $booking->customer_id],
            [
                'current_tier' => 'bronze',
                'earning_rate_multiplier' => 1.0,
                'redemption_rate_multiplier' => 1.0,
            ]
        );

        // Calculate points based on amount spent
        $baseEarningRate = 0.1; // 10 points per 100 LKR spent
        $amountSpent = $booking->total_actual ?? $booking->total_estimated ?? 0;
        $basePoints = floor($amountSpent * $baseEarningRate);
        
        // Apply tier multiplier
        $finalPoints = floor($basePoints * $customerLoyalty->earning_rate_multiplier);

        if ($finalPoints > 0) {
            $transaction = $customerLoyalty->earnPoints(
                $finalPoints,
                'Booking completion reward',
                $bookingId,
                [
                    'base_earning_rate' => $baseEarningRate,
                    'tier_multiplier' => $customerLoyalty->earning_rate_multiplier,
                    'amount_spent' => $amountSpent,
                ]
            );

            // Update customer statistics
            $customerLoyalty->increment('total_bookings');
            $customerLoyalty->increment('total_spent', $amountSpent);

            return [
                'success' => true,
                'points_earned' => $finalPoints,
                'total_points' => $customerLoyalty->fresh()->total_points,
                'available_points' => $customerLoyalty->fresh()->available_points,
                'transaction_id' => $transaction->id,
                'tier_upgraded' => $customerLoyalty->current_tier !== $customerLoyalty->fresh()->current_tier,
                'message' => "Earned {$finalPoints} loyalty points!",
            ];
        }

        return [
            'success' => false,
            'points_earned' => 0,
            'message' => 'No points earned for this booking',
        ];
    }

    /**
     * Get comprehensive discount summary for booking display
     */
    public function getBookingDiscountSummary(string $bookingId): array
    {
        $discounts = BookingDiscount::where('booking_id', $bookingId)
            ->where('is_active', true)
            ->with(['appliedBy'])
            ->get();

        $totalDiscount = $discounts->sum('discount_amount');
        $totalPointsUsed = $discounts->where('type', 'loyalty_points')->sum('loyalty_points_used');
        
        $summary = [
            'total_discount' => $totalDiscount,
            'total_points_used' => $totalPointsUsed,
            'discount_count' => $discounts->count(),
            'requires_approval' => $discounts->where('approval_status', 'pending')->isNotEmpty(),
            'discounts' => $discounts->map(function ($discount) {
                return [
                    'id' => $discount->id,
                    'name' => $discount->discount_name,
                    'type' => $discount->type,
                    'value' => $discount->value,
                    'discount_amount' => $discount->discount_amount,
                    'formatted_discount' => $discount->getFormattedDiscountAttribute(),
                    'application_method' => $discount->application_method,
                    'approval_status' => $discount->approval_status,
                    'requires_approval' => $discount->requires_approval,
                    'applied_by' => $discount->appliedBy?->name,
                    'applied_at' => $discount->applied_at,
                    'loyalty_points_used' => $discount->loyalty_points_used,
                    'can_remove' => $discount->approval_status !== 'approved',
                ];
            })->toArray(),
        ];

        return $summary;
    }

    /**
     * Calculate detailed pricing breakdown with discount integration
     */
    private function calculateDetailedPricingBreakdown(
        array $currentPricingBreakdown, 
        float $discountAmount, 
        BookingDiscount $discount, 
        float $originalAmount
    ): array {
        // Extract components from current pricing breakdown
        $basePricing = $currentPricingBreakdown['base_pricing'] ?? [];
        $addonsPricing = $currentPricingBreakdown['addons_pricing'] ?? [];
        $summary = $currentPricingBreakdown['summary'] ?? [];

        // Calculate price before customizations
        $baseAmount = $basePricing['base_amount'] ?? 0;
        $addonsAmount = $addonsPricing['addons_total'] ?? 0;
        $priceBeforeCustomizations = $baseAmount + $addonsAmount;

        // Calculate price after customizations (if any variable customizations were applied)
        $customizationAdjustments = 0;
        if (!empty($currentPricingBreakdown['applied_customizations'])) {
            foreach ($currentPricingBreakdown['applied_customizations'] as $customization) {
                $adjustment = ($customization['custom_value'] ?? 0) - ($customization['original_value'] ?? 0);
                $customizationAdjustments += $adjustment;
            }
        }
        $priceAfterCustomizations = $priceBeforeCustomizations + $customizationAdjustments;

        // Calculate total discount amount (including this new discount)
        $existingDiscounts = $this->getExistingDiscounts($discount->booking_id ?? null);
        $totalDiscountAmount = $existingDiscounts + $discountAmount;

        // Calculate final totals
        $subtotalAfterDiscounts = max(0, $priceAfterCustomizations - $totalDiscountAmount);
        $taxAmount = $this->calculateTax($subtotalAfterDiscounts);
        $finalTotal = $subtotalAfterDiscounts + $taxAmount;

        return [
            'price_before_customizations' => [
                'base_amount' => $baseAmount,
                'addons_amount' => $addonsAmount,
                'subtotal' => $priceBeforeCustomizations,
                'formatted' => 'LKR ' . number_format($priceBeforeCustomizations, 2),
            ],
            'customizations' => [
                'total_adjustments' => $customizationAdjustments,
                'applied_count' => count($currentPricingBreakdown['applied_customizations'] ?? []),
                'details' => $currentPricingBreakdown['applied_customizations'] ?? [],
                'formatted' => ($customizationAdjustments >= 0 ? '+' : '') . 'LKR ' . number_format($customizationAdjustments, 2),
            ],
            'price_after_customizations' => [
                'amount' => $priceAfterCustomizations,
                'formatted' => 'LKR ' . number_format($priceAfterCustomizations, 2),
            ],
            'discounts' => [
                'new_discount' => [
                    'name' => $discount->discount_name,
                    'type' => $discount->type,
                    'amount' => $discountAmount,
                    'formatted' => '-LKR ' . number_format($discountAmount, 2),
                ],
                'existing_discounts' => $existingDiscounts,
                'total_discount_amount' => $totalDiscountAmount,
                'formatted_total' => '-LKR ' . number_format($totalDiscountAmount, 2),
            ],
            'tax_calculation' => [
                'taxable_amount' => $subtotalAfterDiscounts,
                'tax_amount' => $taxAmount,
                'tax_rate' => '0%', // Adjust based on your tax calculation
                'formatted' => 'LKR ' . number_format($taxAmount, 2),
            ],
            'final_totals' => [
                'subtotal_after_discounts' => $subtotalAfterDiscounts,
                'tax_amount' => $taxAmount,
                'final_total' => $finalTotal,
                'original_amount' => $originalAmount,
                'total_savings' => max(0, $originalAmount - $finalTotal),
                'formatted' => [
                    'subtotal' => 'LKR ' . number_format($subtotalAfterDiscounts, 2),
                    'tax' => 'LKR ' . number_format($taxAmount, 2),
                    'total' => 'LKR ' . number_format($finalTotal, 2),
                    'savings' => 'LKR ' . number_format(max(0, $originalAmount - $finalTotal), 2),
                ],
            ],
            'breakdown_summary' => [
                'has_customizations' => !empty($currentPricingBreakdown['applied_customizations']),
                'has_discounts' => $totalDiscountAmount > 0,
                'requires_approval' => $discount->requires_approval,
                'currency' => $currentPricingBreakdown['currency'] ?? 'LKR',
            ],
        ];
    }

    /**
     * Get existing discount amount for a booking
     */
    private function getExistingDiscounts(?string $bookingId): float
    {
        if (!$bookingId) {
            return 0;
        }

        return BookingDiscount::where('booking_id', $bookingId)
            ->where('is_active', true)
            ->sum('discount_amount');
    }

    /**
     * Calculate tax amount (placeholder - implement according to your tax rules)
     */
    private function calculateTax(float $taxableAmount): float
    {
        // Implement your tax calculation logic here
        // For now, returning 0 as LKR typically doesn't have additional taxes
        return 0;
    }
}
