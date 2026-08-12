<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyPointTransaction;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoyaltyController extends Controller
{
    // Enforce authentication and permissions for loyalty endpoints
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->middleware('permission:loyalty.view|customers.loyalty')->only([
            'getCustomerLoyaltyPoints',
            'getCustomerLoyaltyTier',
            'getCustomerLoyaltyHistory',
            'getLoyaltyTiers',
            'getLoyaltyRewards',
            'getLoyaltyActivity',
            'getLoyaltyStats',
            'getLoyaltyLeaderboard',
            'getRewardRedemptions',
            'exportLoyaltyData',
        ]);
        $this->middleware('permission:customers.loyalty')->only([
            'addPoints',
            'adjustPoints',
            'storeReward',
            'updateReward',
            'deleteReward',
            'updateRewardStatus',
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
        $history = LoyaltyPointTransaction::query()
            ->where('customer_id', $customer->id)
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

    public function addPoints(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return $this->applyManualPointChange(
            $customer,
            (int) $data['points'],
            'bonus',
            $data['reason'],
            $request->user()?->id,
        );
    }

    public function adjustPoints(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'adjustment_type' => ['required', 'in:bonus,correction,penalty,migration'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $points = (int) $data['points'];
        if ($data['adjustment_type'] === 'penalty') {
            $points *= -1;
        }

        return $this->applyManualPointChange(
            $customer,
            $points,
            $data['adjustment_type'],
            $data['reason'],
            $request->user()?->id,
        );
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
        $tiers = LoyaltyTier::query()
            ->withCount('customers')
            ->active()
            ->ordered()
            ->get()
            ->map(fn (LoyaltyTier $tier) => [
                'id' => $tier->id,
                'name' => $tier->display_name ?: $tier->name,
                'min_points' => $tier->min_points,
                'max_points' => $tier->max_points,
                'benefits' => $this->tierBenefits($tier),
                'badge_color' => $tier->color_code,
                'customers_count' => $tier->customers_count,
                'multiplier' => (float) $tier->points_earning_multiplier,
                'badge_level' => null,
                'icon' => $tier->icon,
                'description' => $tier->description,
            ]);
        
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
        $rewards = LoyaltyReward::orderBy('points_required')->orderBy('name')->get();
        
        return response()->json([
            'status' => 'success',
            'data' => $rewards
        ]);
    }

    public function storeReward(Request $request): JsonResponse
    {
        $data = $this->validateReward($request);
        $reward = LoyaltyReward::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Reward created',
            'data' => $reward,
        ], 201);
    }

    public function updateReward(Request $request, LoyaltyReward $reward): JsonResponse
    {
        $reward->update($this->validateReward($request, true));

        return response()->json([
            'status' => 'success',
            'message' => 'Reward updated',
            'data' => $reward->fresh(),
        ]);
    }

    public function deleteReward(LoyaltyReward $reward): JsonResponse
    {
        $reward->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Reward deleted',
        ]);
    }

    public function updateRewardStatus(Request $request, LoyaltyReward $reward): JsonResponse
    {
        $data = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $reward->update(['is_active' => $data['is_active']]);

        return response()->json([
            'status' => 'success',
            'message' => $reward->is_active ? 'Reward activated' : 'Reward deactivated',
            'data' => $reward->fresh(),
        ]);
    }

    public function getRewardRedemptions(LoyaltyReward $reward): JsonResponse
    {
        $redemptions = LoyaltyPointTransaction::redeemed()
            ->with('customer.user')
            ->where('metadata->reward_id', $reward->id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (LoyaltyPointTransaction $transaction) => [
                'id' => $transaction->id,
                'reward_id' => $reward->id,
                'reward_name' => $reward->name,
                'reward_category' => $reward->category,
                'customer_id' => $transaction->customer_id,
                'customer_name' => trim(($transaction->customer?->user?->first_name ?? '') . ' ' . ($transaction->customer?->user?->last_name ?? '')) ?: 'Unknown customer',
                'points_spent' => abs((int) $transaction->points),
                'redeemed_at' => $transaction->created_at,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => $redemptions,
        ]);
    }

    public function getLoyaltyStats(): JsonResponse
    {
        $customerPoints = Customer::query()
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->whereNull('customers.deleted_at');
        $transactions = LoyaltyPointTransaction::query()->completed();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_members' => (clone $customerPoints)->count(),
                'members_with_points' => (clone $customerPoints)->where('users.reputation', '>', 0)->count(),
                'outstanding_points' => (int) (clone $customerPoints)->sum('users.reputation'),
                'points_issued' => (int) (clone $transactions)->where('points', '>', 0)->sum('points'),
                'points_deducted' => abs((int) (clone $transactions)->where('points', '<', 0)->sum('points')),
                'average_balance' => round((float) ((clone $customerPoints)->avg('users.reputation') ?? 0), 2),
            ],
        ]);
    }

    public function getLoyaltyLeaderboard(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->get('limit', 10), 1), 50);
        $members = DB::table('customers')
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->whereNull('customers.deleted_at')
            ->select([
                'customers.id as customer_id',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.reputation',
            ])
            ->orderByDesc('users.reputation')
            ->orderBy('customers.id')
            ->limit($limit)
            ->get()
            ->map(fn ($member, int $index) => [
                'id' => $member->customer_id,
                'name' => trim($member->first_name . ' ' . $member->last_name),
                'email' => $member->email,
                'points' => (int) $member->reputation,
                'tier' => $this->getLoyaltyTier((int) $member->reputation)['name'],
                'rank' => $index + 1,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'leaderboard' => $members,
                'limit' => $limit,
            ],
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

        if ($request->get('type') === 'redeemed') {
            $activity = LoyaltyPointTransaction::redeemed()
                ->completed()
                ->with('customer.user')
                ->latest()
                ->limit($limit)
                ->get()
                ->map(fn (LoyaltyPointTransaction $transaction) => [
                    'id' => $transaction->id,
                    'customer_id' => $transaction->customer_id,
                    'customer_name' => trim(($transaction->customer?->user?->first_name ?? '') . ' ' . ($transaction->customer?->user?->last_name ?? '')) ?: 'Unknown customer',
                    'reward_name' => data_get($transaction->metadata, 'reward_name', $transaction->redemption_reason ?: 'Loyalty reward'),
                    'reward_category' => data_get($transaction->metadata, 'reward_category', 'discount'),
                    'points_spent' => abs((int) $transaction->points),
                    'redeemed_at' => $transaction->created_at,
                ]);

            return response()->json([
                'status' => 'success',
                'data' => $activity,
            ]);
        }

        $query = LoyaltyPointTransaction::query()
            ->completed()
            ->with('customer.user');

        // Apply period filter
        if ($period !== 'all') {
            $date = now();
            switch ($period) {
                case 'day':
                    $query->whereDate('created_at', $date);
                    break;
                case 'week':
                    $query->whereBetween('created_at', [
                        $date->startOfWeek(),
                        $date->endOfWeek()
                    ]);
                    break;
                case 'month':
                    $query->whereMonth('created_at', $date->month)
                          ->whereYear('created_at', $date->year);
                    break;
            }
        }

        $activity = $query->orderByDesc('created_at')
                         ->limit($limit)
                         ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'activity' => $activity->map(function ($item) {
                    $meta = $item->metadata ?? [];
                    $customer = $item->customer;
                    $user = $customer?->user;

                    return [
                        'id' => (string) $item->id,
                        'customer_id' => $item->customer_id,
                        'customer_name' => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')) ?: 'Unknown customer',
                        'customer_email' => $user?->email,
                        'action_type' => $item->type,
                        'points' => (int) $item->points,
                        'current_points' => (int) $item->balance_after,
                        'tier' => $this->getLoyaltyTier((int) $item->balance_after)['name'],
                        'description' => data_get($meta, 'reason') ?: $item->description,
                        'created_at' => $item->created_at,
                    ];
                }),
                'period' => $period,
                'limit' => $limit
            ]
        ]);
    }

    /**
     * Export the canonical customer loyalty activity ledger.
     * GET /api/customers/loyalty/export
     */
    public function exportLoyaltyData(): StreamedResponse
    {
        $filename = 'loyalty-activity-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, [
                'customer_id',
                'customer_name',
                'email',
                'points',
                'activity_type',
                'activity_at',
            ]);

            LoyaltyPointTransaction::query()
                ->completed()
                ->with('customer.user')
                ->orderBy('created_at')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($output): void {
                    foreach ($rows as $row) {
                        $user = $row->customer?->user;
                        fputcsv($output, [
                            $this->csvValue($row->customer_id),
                            $this->csvValue(trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? ''))),
                            $this->csvValue($user?->email),
                            (int) $row->points,
                            $this->csvValue($row->type),
                            $row->created_at,
                        ]);
                    }
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    private function applyManualPointChange(
        Customer $customer,
        int $points,
        string $type,
        string $reason,
        ?string $actorId,
    ): JsonResponse {
        $result = DB::transaction(function () use ($customer, $points, $type, $reason, $actorId): array {
            $user = User::query()->lockForUpdate()->findOrFail($customer->user_id);
            $balanceBefore = $user->getPoints();

            abort_if($balanceBefore + $points < 0, 422, 'The adjustment cannot reduce the customer balance below zero.');

            $user->forceFill(['reputation' => $balanceBefore + $points])->save();

            LoyaltyPointTransaction::create([
                'customer_id' => $customer->id,
                'type' => 'adjusted',
                'points' => $points,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceBefore + $points,
                'reference_number' => 'MANUAL-' . Str::upper(Str::random(16)),
                'description' => $reason,
                'processed_by' => $actorId,
                'processed_at' => now(),
                'status' => 'completed',
                'metadata' => [
                    'reason' => $reason,
                    'adjustment_type' => $type,
                    'processed_by' => $actorId,
                ],
            ]);

            return [
                'customer_id' => $customer->id,
                'points_changed' => $points,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceBefore + $points,
                'adjustment_type' => $type,
                'reason' => $reason,
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => $points < 0 ? 'Loyalty points deducted successfully' : 'Loyalty points added successfully',
            'data' => $result,
        ]);
    }

    private function validateReward(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'points_required' => [$required, 'integer', 'min:1'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:50'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function tierBenefits(LoyaltyTier $tier): array
    {
        $benefits = $tier->privileges ?? [];
        if ($benefits === [] && $tier->getRawOriginal('privileges') !== null) {
            $benefits = $tier->getRawOriginal('privileges');
        }
        if (is_string($benefits)) {
            $decoded = json_decode($benefits, true);
            $benefits = is_array($decoded) ? $decoded : [$benefits];
        }
        if (!is_array($benefits)) {
            $benefits = [];
        }

        if ($tier->priority_booking) {
            $benefits[] = 'Priority booking';
        }
        if ($tier->free_cancellation) {
            $benefits[] = 'Free cancellation';
        }
        if ($tier->priority_support) {
            $benefits[] = 'Priority support';
        }

        return array_values(array_unique(array_filter($benefits, 'is_scalar')));
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
