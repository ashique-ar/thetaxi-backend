<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\CustomerLoyaltyPoint;
use App\Models\LoyaltyTier;
use App\Models\Website\WebsiteSetting;
use Illuminate\Support\Facades\Log;

class LoyaltyService
{
    // ─────────────────────────────────────────────────────────────────
    // Points earning
    // ─────────────────────────────────────────────────────────────────

    /**
     * Award loyalty points for a completed booking.
     * Safe to call multiple times — idempotent via duplicate-transaction guard.
     */
    public function awardPointsForBooking(Booking $booking): array
    {
        if (!$booking->customer_id) {
            return ['awarded' => false, 'reason' => 'No customer linked to booking'];
        }

        $loyaltyRecord = $this->getOrCreateLoyaltyRecord($booking->customer_id);

        // Guard: don't re-award for the same booking
        $alreadyAwarded = $loyaltyRecord->transactions()
            ->where('booking_id', $booking->id)
            ->where('type', 'earned')
            ->exists();

        if ($alreadyAwarded) {
            return ['awarded' => false, 'reason' => 'Points already awarded for this booking'];
        }

        $points = $this->calculatePoints($booking, $loyaltyRecord);

        if ($points <= 0) {
            return ['awarded' => false, 'reason' => 'Booking value does not qualify for points'];
        }

        $transaction = $loyaltyRecord->earnPoints(
            $points,
            'Booking completion reward',
            $booking->id,
            ['booking_number' => $booking->booking_number, 'amount' => $booking->total_actual]
        );

        // Update lifetime stats
        $loyaltyRecord->increment('total_bookings');
        $loyaltyRecord->increment('total_spent', (float) ($booking->total_actual ?? 0));

        // Tier evaluation after earning
        $tierResult = $this->evaluateTier($loyaltyRecord->fresh());

        Log::info('Loyalty points awarded', [
            'customer_id'  => $booking->customer_id,
            'booking_id'   => $booking->id,
            'points'       => $points,
            'tier_changed' => $tierResult['changed'],
            'new_tier'     => $tierResult['current_tier'],
        ]);

        return [
            'awarded'      => true,
            'points'       => $points,
            'transaction'  => $transaction,
            'tier_changed' => $tierResult['changed'],
            'new_tier'     => $tierResult['current_tier'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Tier evaluation
    // ─────────────────────────────────────────────────────────────────

    /**
     * Evaluate and apply tier promotion / demotion for a customer.
     */
    public function evaluateTier(CustomerLoyaltyPoint $loyaltyRecord): array
    {
        $tiers = LoyaltyTier::active()->ordered()->get();

        if ($tiers->isEmpty()) {
            return ['changed' => false, 'current_tier' => $loyaltyRecord->current_tier];
        }

        // Find the highest tier the customer qualifies for based on lifetime points
        $qualifyingTier = null;
        foreach ($tiers->reverse() as $tier) {
            $meetsPoints   = $loyaltyRecord->total_points >= $tier->min_points;
            $meetsBookings = !$tier->min_bookings || $loyaltyRecord->total_bookings >= $tier->min_bookings;
            $meetsSpend    = !$tier->min_total_spent || $loyaltyRecord->total_spent >= $tier->min_total_spent;

            if ($meetsPoints && $meetsBookings && $meetsSpend) {
                $qualifyingTier = $tier;
                break;
            }
        }

        // Fall back to the default (lowest) tier if nothing qualifies
        if (!$qualifyingTier) {
            $qualifyingTier = $tiers->where('is_default', true)->first() ?? $tiers->first();
        }

        if (!$qualifyingTier || $qualifyingTier->name === $loyaltyRecord->current_tier) {
            return ['changed' => false, 'current_tier' => $loyaltyRecord->current_tier];
        }

        $previousTier     = $loyaltyRecord->current_tier;
        $isUpgrade        = $this->isTierHigher($qualifyingTier, $previousTier, $tiers);

        $updates = [
            'current_tier'                 => $qualifyingTier->name,
            'earning_rate_multiplier'      => $qualifyingTier->points_earning_multiplier ?? 1,
            'redemption_rate_multiplier'   => $qualifyingTier->points_redemption_multiplier ?? 1,
        ];

        if ($isUpgrade) {
            $updates['tier_upgrade_date'] = now()->toDateString();
            $updates['is_vip']            = ($qualifyingTier->priority_support ?? false)
                                            || ($qualifyingTier->priority_booking ?? false);

            // Award upgrade bonus points
            if (($qualifyingTier->bonus_points_on_upgrade ?? 0) > 0) {
                $loyaltyRecord->earnPoints(
                    $qualifyingTier->bonus_points_on_upgrade,
                    "Tier upgrade bonus: {$previousTier} → {$qualifyingTier->name}"
                );
            }
        } else {
            $updates['tier_downgrade_date'] = now()->toDateString();
        }

        $loyaltyRecord->update($updates);

        // Recalculate next tier info
        $nextTier = $tiers->first(fn ($t) => $t->min_points > $loyaltyRecord->total_points);
        $loyaltyRecord->update([
            'next_tier'          => $nextTier?->name,
            'points_to_next_tier' => $nextTier ? max(0, $nextTier->min_points - $loyaltyRecord->total_points) : 0,
        ]);

        Log::info('Customer tier changed', [
            'customer_id'   => $loyaltyRecord->customer_id,
            'previous_tier' => $previousTier,
            'new_tier'      => $qualifyingTier->name,
            'is_upgrade'    => $isUpgrade,
        ]);

        return [
            'changed'       => true,
            'previous_tier' => $previousTier,
            'current_tier'  => $qualifyingTier->name,
            'is_upgrade'    => $isUpgrade,
        ];
    }

    /**
     * Apply tier benefits (e.g. automatic discount) to a booking.
     * Returns the discount amount (0 if no benefit applies).
     */
    public function applyTierBenefits(Booking $booking, CustomerLoyaltyPoint $loyaltyRecord): float
    {
        $tier = LoyaltyTier::where('name', $loyaltyRecord->current_tier)->where('is_active', true)->first();

        if (!$tier) {
            return 0.0;
        }

        $discountMultiplier = (float) ($tier->discount_multiplier ?? 0);

        if ($discountMultiplier <= 0) {
            return 0.0;
        }

        // discount_multiplier is treated as a percentage off the base price
        $baseAmount    = (float) ($booking->total_estimated ?? $booking->base_amount ?? 0);
        $discountAmount = round($baseAmount * ($discountMultiplier / 100), 2);

        return $discountAmount;
    }

    // ─────────────────────────────────────────────────────────────────
    // Record helpers
    // ─────────────────────────────────────────────────────────────────

    public function getOrCreateLoyaltyRecord(string $customerId): CustomerLoyaltyPoint
    {
        return CustomerLoyaltyPoint::firstOrCreate(
            ['customer_id' => $customerId],
            [
                'total_points'                => 0,
                'available_points'            => 0,
                'pending_points'              => 0,
                'redeemed_points'             => 0,
                'expired_points'              => 0,
                'current_tier'               => $this->getDefaultTierName(),
                'earning_rate_multiplier'     => 1.0,
                'redemption_rate_multiplier'  => 1.0,
                'total_bookings'             => 0,
                'total_spent'               => 0,
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────

    private function calculatePoints(Booking $booking, CustomerLoyaltyPoint $loyaltyRecord): int
    {
        $amount = (float) ($booking->total_actual ?? $booking->total_estimated ?? 0);

        if ($amount <= 0) {
            return 0;
        }

        $baseRate   = (float) (WebsiteSetting::getValue('loyalty_points_per_unit', 1) ?? 1);
        $multiplier = (float) ($loyaltyRecord->earning_rate_multiplier ?? 1);

        return (int) floor($amount * $baseRate * $multiplier);
    }

    private function getDefaultTierName(): string
    {
        $default = LoyaltyTier::where('is_default', true)->where('is_active', true)->first()
            ?? LoyaltyTier::where('is_active', true)->orderBy('min_points')->first();

        return $default?->name ?? 'Bronze';
    }

    private function isTierHigher(LoyaltyTier $tier, ?string $currentTierName, $allTiers): bool
    {
        if (!$currentTierName) {
            return true;
        }

        $currentTierOrder   = $allTiers->search(fn ($t) => $t->name === $currentTierName);
        $newTierOrder       = $allTiers->search(fn ($t) => $t->name === $tier->name);

        return $newTierOrder !== false && $currentTierOrder !== false
            ? $newTierOrder > $currentTierOrder
            : true;
    }
}
