<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LoyaltyController extends Controller
{
    // Enforce authentication and permissions for loyalty endpoints
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->middleware('permission:loyalty.view')->only([
            'getCustomerLoyaltyPoints',
            'getCustomerLoyaltyTier',
            'getCustomerLoyaltyHistory',
            'getLoyaltyTiers',
            'getLoyaltyRewards',
            'getLoyaltyActivity'
        ]);
        $this->middleware('permission:loyalty.redeem')->only(['redeemPoints']);
    }

    /**
     * Get customer loyalty points
     * GET /api/customers/{customer}/loyalty/points
     */
    public function getCustomerLoyaltyPoints(Customer $customer): JsonResponse
    {
        $user = $customer->user;
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'points' => $user->getPoints(),
                'tier' => $this->getLoyaltyTier($user->getPoints()),
                'next_tier' => $this->getNextLoyaltyTier($user->getPoints()),
                'points_to_next_tier' => $this->getPointsToNextTier($user->getPoints())
            ]
        ]);
    }

    /**
     * Get customer loyalty tier
     * GET /api/customers/{customer}/loyalty/tier
     */
    public function getCustomerLoyaltyTier(Customer $customer): JsonResponse
    {
        $user = $customer->user;
        $tier = $this->getLoyaltyTier($user->getPoints());
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'current_tier' => $tier,
                'points' => $user->getPoints(),
                'tier_benefits' => $this->getTierBenefits($tier['name'])
            ]
        ]);
    }

    /**
     * Get customer loyalty history
     * GET /api/customers/{customer}/loyalty/history
     */
    public function getCustomerLoyaltyHistory(Customer $customer): JsonResponse
    {
        $user = $customer->user;
        $history = $user->reputations()
            ->latest()
            ->paginate(20);
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'history' => $history->items(),
                'pagination' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total()
                ]
            ]
        ]);
    }

    /**
     * Redeem loyalty points
     * POST /api/customers/{customer}/loyalty/redeem
     */
    public function redeemPoints(Request $request, Customer $customer): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'points' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
            'booking_id' => 'nullable|exists:bookings,id',
            'discount_amount' => 'nullable|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $customer->user;
        
        if ($user->getPoints() < $request->points) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient points'
            ], 400);
        }

        try {
            $user->reducePoint($request->points);
            
            // Log the redemption
            $this->logRedemption($customer, $request->points, $request->reason, $request->booking_id);

            return response()->json([
                'status' => 'success',
                'message' => 'Points redeemed successfully',
                'data' => [
                    'redeemed_points' => $request->points,
                    'remaining_points' => $user->fresh()->getPoints(),
                    'discount_amount' => $request->discount_amount ?? 0
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to redeem points',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get loyalty program tiers
     * GET /api/customers/loyalty/tiers
     */
    public function getLoyaltyTiers(): JsonResponse
    {
        $tiers = $this->getLoyaltyTierStructure();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'tiers' => $tiers
            ]
        ]);
    }

    /**
     * Get loyalty program rewards
     * GET /api/customers/loyalty/rewards
     */
    public function getLoyaltyRewards(): JsonResponse
    {
        $rewards = [
            [
                'id' => 1,
                'name' => '5% Discount',
                'description' => '5% discount on next booking',
                'points_required' => 100,
                'type' => 'discount',
                'value' => 5,
                'is_active' => true
            ],
            [
                'id' => 2,
                'name' => '10% Discount',
                'description' => '10% discount on next booking',
                'points_required' => 200,
                'type' => 'discount',
                'value' => 10,
                'is_active' => true
            ],
            [
                'id' => 3,
                'name' => 'Free Upgrade',
                'description' => 'Free vehicle upgrade',
                'points_required' => 500,
                'type' => 'upgrade',
                'value' => 1,
                'is_active' => true
            ],
            [
                'id' => 4,
                'name' => 'Free Booking',
                'description' => 'One free booking (up to $50)',
                'points_required' => 1000,
                'type' => 'free_booking',
                'value' => 50,
                'is_active' => true
            ]
        ];
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'rewards' => $rewards
            ]
        ]);
    }

    /**
     * Get loyalty activity
     * GET /api/customers/loyalty/activity
     */
    public function getLoyaltyActivity(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 20);
        $period = $request->get('period', 'all');

        $query = User::select([
            'users.id',
            'users.first_name',
            'users.last_name',
            'users.email',
            'reputations.point',
            'reputations.name as activity_type',
            'reputations.created_at'
        ])
        ->join('reputations', 'users.id', '=', 'reputations.payee_id')
        ->join('customers', 'users.id', '=', 'customers.user_id');

        // Apply period filter
        if ($period !== 'all') {
            $date = now();
            switch ($period) {
                case 'day':
                    $query->whereDate('reputations.created_at', $date);
                    break;
                case 'week':
                    $query->whereBetween('reputations.created_at', [
                        $date->startOfWeek(),
                        $date->endOfWeek()
                    ]);
                    break;
                case 'month':
                    $query->whereMonth('reputations.created_at', $date->month)
                          ->whereYear('reputations.created_at', $date->year);
                    break;
            }
        }

        $activity = $query->orderByDesc('reputations.created_at')
                         ->limit($limit)
                         ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'activity' => $activity->map(function ($item) {
                    return [
                        'user_id' => $item->id,
                        'user_name' => trim($item->first_name . ' ' . $item->last_name),
                        'email' => $item->email,
                        'points' => $item->point,
                        'activity_type' => $item->activity_type,
                        'created_at' => $item->created_at
                    ];
                }),
                'period' => $period,
                'limit' => $limit
            ]
        ]);
    }

    /**
     * Get loyalty tier structure
     */
    private function getLoyaltyTierStructure(): array
    {
        return [
            [
                'name' => 'Bronze',
                'min_points' => 0,
                'max_points' => 99,
                'benefits' => ['Basic customer support', 'Standard booking priority'],
                'color' => '#CD7F32'
            ],
            [
                'name' => 'Silver',
                'min_points' => 100,
                'max_points' => 499,
                'benefits' => ['Priority customer support', 'Higher booking priority', '5% discount on bookings'],
                'color' => '#C0C0C0'
            ],
            [
                'name' => 'Gold',
                'min_points' => 500,
                'max_points' => 999,
                'benefits' => ['Premium customer support', 'Highest booking priority', '10% discount on bookings', 'Free vehicle upgrades'],
                'color' => '#FFD700'
            ],
            [
                'name' => 'Platinum',
                'min_points' => 1000,
                'max_points' => null,
                'benefits' => ['VIP customer support', 'Exclusive booking priority', '15% discount on bookings', 'Free vehicle upgrades', 'Personal account manager'],
                'color' => '#E5E4E2'
            ]
        ];
    }

    /**
     * Get loyalty tier based on points
     */
    private function getLoyaltyTier(int $points): array
    {
        $tiers = $this->getLoyaltyTierStructure();
        
        foreach ($tiers as $tier) {
            if ($points >= $tier['min_points'] && ($tier['max_points'] === null || $points <= $tier['max_points'])) {
                return $tier;
            }
        }
        
        return $tiers[0]; // Default to Bronze
    }

    /**
     * Get next loyalty tier based on points
     */
    private function getNextLoyaltyTier(int $points): ?array
    {
        $tiers = $this->getLoyaltyTierStructure();
        
        foreach ($tiers as $tier) {
            if ($points < $tier['min_points']) {
                return $tier;
            }
        }
        
        return null; // Already at highest tier
    }

    /**
     * Get points needed to reach next tier
     */
    private function getPointsToNextTier(int $points): int
    {
        $nextTier = $this->getNextLoyaltyTier($points);
        
        if ($nextTier) {
            return $nextTier['min_points'] - $points;
        }
        
        return 0; // Already at highest tier
    }

    /**
     * Get tier benefits
     */
    private function getTierBenefits(string $tierName): array
    {
        $tiers = $this->getLoyaltyTierStructure();
        
        foreach ($tiers as $tier) {
            if ($tier['name'] === $tierName) {
                return $tier['benefits'];
            }
        }
        
        return [];
    }

    /**
     * Log point redemption
     */
    private function logRedemption(Customer $customer, int $points, string $reason, ?string $bookingId = null): void
    {
        // You can implement a custom logging mechanism here
        // For now, we'll just log it to the Laravel log
        \Log::info('Loyalty Points Redeemed', [
            'customer_id' => $customer->id,
            'user_id' => $customer->user_id,
            'points' => $points,
            'reason' => $reason,
            'booking_id' => $bookingId,
            'timestamp' => now()
        ]);
    }
}
