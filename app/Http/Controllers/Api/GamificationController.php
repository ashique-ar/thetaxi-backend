<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use QCod\Gamify\PointType;
use QCod\Gamify\Badge;
use QCod\Gamify\Reputation;

class GamificationController extends Controller
{
    // Enforce authentication and permissions for gamification endpoints
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->middleware('permission:gamification.view')->only([
            'getUserPoints',
            'getUserReputation',
            'getUserBadges',
            'getUserRank',
            'getLeaderboard'
        ]);
        $this->middleware('permission:gamification.give-points')->only(['givePoints']);
        $this->middleware('permission:gamification.undo-points')->only(['undoPoints']);
        $this->middleware('permission:gamification.reset-points')->only(['resetPoints']);
        $this->middleware('permission:gamification.bulk-give-points')->only(['bulkGivePoints']);
        $this->middleware('permission:gamification.stats')->only(['getBadgeStats', 'getReputationStats']);
    }

    /**
     * Get user gamification points
     * 
     * @api {get} /api/users/{user}/points Get User Points
     * @apiName GetUserPoints
     * @apiGroup Gamification
     * @apiPermission gamification.view
     * @apiDescription Retrieve user's gamification points, rank, and level
     * 
     * @apiParam {String} user User ID
     * 
     * @apiSuccess {String} status Response status
     * @apiSuccess {Object} data Points data
     * @apiSuccess {Number} data.points User points
     * @apiSuccess {Number} data.rank User rank
     * @apiSuccess {Number} data.level User level
     * 
     * @apiError 401 Unauthorized
     * @apiError 403 Forbidden
     * @apiError 404 User not found
     * @apiError 500 Internal server error
     * 
     * @param User $user
     * @return JsonResponse
     */
    public function getUserPoints(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'points' => $user->getPoints(),
                'rank' => $user->getRank(),
                'level' => $user->getLevel()
            ]
        ]);
    }

    /**
     * Get user reputation
     * GET /api/users/{user}/reputation
     */
    public function getUserReputation(User $user): JsonResponse
    {
        $reputation = $user->reputations()->latest()->get();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_reputation' => $user->getPoints(),
                'reputation_history' => $reputation->map(function ($rep) {
                    return [
                        'id' => $rep->id,
                        'name' => $rep->name,
                        'point' => $rep->point,
                        'meta' => $rep->meta,
                        'created_at' => $rep->created_at,
                        'updated_at' => $rep->updated_at
                    ];
                })
            ]
        ]);
    }

    /**
     * Give points to a user
     * 
     * @api {post} /api/users/{user}/points/give Give Points
     * @apiName GivePoints
     * @apiGroup Gamification
     * @apiPermission gamification.give-points
     * @apiDescription Give points to a user with optional reason
     * 
     * @apiParam {String} user User ID
     * @apiParam {Number} points Points to give (required)
     * @apiParam {String} [reason] Reason for giving points
     * 
     * @apiSuccess {String} status Response status
     * @apiSuccess {Object} data Updated points data
     * @apiSuccess {Number} data.points Updated user points
     * @apiSuccess {Number} data.rank Updated user rank
     * @apiSuccess {String} message Success message
     * 
     * @apiError 422 Validation error
     * @apiError 401 Unauthorized
     * @apiError 403 Forbidden
     * @apiError 404 User not found
     * @apiError 500 Internal server error
     * 
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function givePoints(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'points' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user->addPoint($request->points);

            return response()->json([
                'status' => 'success',
                'message' => 'Points awarded successfully',
                'data' => [
                    'awarded_points' => $request->points,
                    'total_points' => $user->fresh()->getPoints()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to award points',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Undo points from user
     * POST /api/users/{user}/points/undo
     */
    public function undoPoints(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'points' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user->reducePoint($request->points);

            return response()->json([
                'status' => 'success',
                'message' => 'Points deducted successfully',
                'data' => [
                    'deducted_points' => $request->points,
                    'total_points' => $user->fresh()->getPoints()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to deduct points',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset user points
     * POST /api/users/{user}/points/reset
     */
    public function resetPoints(User $user): JsonResponse
    {
        try {
            $user->resetPoint();

            return response()->json([
                'status' => 'success',
                'message' => 'Points reset successfully',
                'data' => [
                    'total_points' => $user->fresh()->getPoints()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset points',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user badges
     * 
     * @api {get} /api/users/{user}/badges Get User Badges
     * @apiName GetUserBadges
     * @apiGroup Gamification
     * @apiPermission gamification.view
     * @apiDescription Retrieve all badges earned by a user
     * 
     * @apiParam {String} user User ID
     * 
     * @apiSuccess {String} status Response status
     * @apiSuccess {Object} data Badges data
     * @apiSuccess {Object[]} data.badges User badges
     * @apiSuccess {Number} data.total_badges Total badges count
     * 
     * @apiError 401 Unauthorized
     * @apiError 403 Forbidden
     * @apiError 404 User not found
     * @apiError 500 Internal server error
     * 
     * @param User $user
     * @return JsonResponse
     */
    public function getUserBadges(User $user): JsonResponse
    {
        $badges = $user->badges()->get();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'badges' => $badges->map(function ($badge) {
                    return [
                        'id' => $badge->id,
                        'name' => $badge->name,
                        'icon' => $badge->icon,
                        'description' => $badge->description,
                        'level' => $badge->level,
                        'earned_at' => $badge->pivot->created_at ?? null,
                        'is_active' => $badge->is_active
                    ];
                })
            ]
        ]);
    }

    /**
     * Get user rank
     * GET /api/users/{user}/rank
     */
    public function getUserRank(User $user): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'rank' => $user->getRank(),
                'points' => $user->getPoints(),
                'level' => $user->getLevel()
            ]
        ]);
    }

    /**
     * Get all available badges
     * GET /api/badges
     */
    public function getAllBadges(): JsonResponse
    {
        $badges = Badge::all();
        
        return response()->json([
            'status' => 'success',
            'data' => [
                'badges' => $badges->map(function ($badge) {
                    return [
                        'id' => $badge->id,
                        'name' => $badge->name,
                        'icon' => $badge->icon,
                        'description' => $badge->description,
                        'level' => $badge->level,
                        'is_active' => $badge->is_active
                    ];
                })
            ]
        ]);
    }

    /**
     * Get badge details
     * GET /api/badges/{id}
     */
    public function getBadgeDetails($id): JsonResponse
    {
        $badge = Badge::find($id);
        
        if (!$badge) {
            return response()->json([
                'status' => 'error',
                'message' => 'Badge not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'badge' => [
                    'id' => $badge->id,
                    'name' => $badge->name,
                    'icon' => $badge->icon,
                    'description' => $badge->description,
                    'level' => $badge->level,
                    'is_active' => $badge->is_active
                ]
            ]
        ]);
    }

    /**
     * Get badge statistics
     * GET /api/badges/stats
     */
    public function getBadgeStats(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_badges' => Badge::count(),
                'active_badges' => Badge::where('is_active', true)->count(),
                'inactive_badges' => Badge::where('is_active', false)->count()
            ]
        ]);
    }

    /**
     * Get leaderboard
     * 
     * @api {get} /api/leaderboard Get Leaderboard
     * @apiName GetLeaderboard
     * @apiGroup Gamification
     * @apiPermission gamification.view
     * @apiDescription Get user leaderboard based on points
     * 
     * @apiParam {Number} [limit=10] Number of users to return
     * @apiParam {String} [period=all] Time period (all/month/week)
     * 
     * @apiSuccess {String} status Response status
     * @apiSuccess {Object} data Leaderboard data
     * @apiSuccess {Object[]} data.leaderboard Top users
     * @apiSuccess {Number} data.user_rank Current user rank
     * @apiSuccess {Number} data.total_users Total users count
     * 
     * @apiError 401 Unauthorized
     * @apiError 403 Forbidden
     * @apiError 500 Internal server error
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getLeaderboard(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);
        $period = $request->get('period', 'all'); // all, month, week, day

        $query = User::select([
            'users.id',
            'users.first_name',
            'users.last_name',
            'users.email',
            'users.profile_image',
            'users.' . config('gamify.reputation_column', 'reputation') . ' as total_points'
        ])
        ->whereNotNull('users.' . config('gamify.reputation_column', 'reputation'));

        $leaderboard = $query->orderByDesc('total_points')
                           ->limit($limit)
                           ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'leaderboard' => $leaderboard->map(function ($user, $index) {
                    return [
                        'id' => $user->id,
                        'name' => trim($user->first_name . ' ' . $user->last_name),
                        'email' => $user->email,
                        'profile_image' => $user->profile_image,
                        'total_points' => $user->total_points,
                        'rank' => $index + 1
                    ];
                }),
                'period' => $period,
                'limit' => $limit
            ]
        ]);
    }

    /**
     * Get reputation statistics
     * GET /api/reputation/stats
     */
    public function getReputationStats(): JsonResponse
    {
        $stats = [
            'total_transactions' => Reputation::count(),
            'total_points' => Reputation::sum('point'),
            'average_points' => Reputation::avg('point'),
            'max_points' => Reputation::max('point'),
            'min_points' => Reputation::min('point'),
            'unique_users' => Reputation::distinct('payee_id')->count('payee_id'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_transactions' => $stats['total_transactions'] ?? 0,
                'total_points' => $stats['total_points'] ?? 0,
                'average_points' => round($stats['average_points'] ?? 0, 2),
                'max_points' => $stats['max_points'] ?? 0,
                'min_points' => $stats['min_points'] ?? 0,
                'unique_users' => $stats['unique_users'] ?? 0
            ]
        ]);
    }

    /**
     * Bulk give points to users
     * POST /api/points/bulk-give
     */
    public function bulkGivePoints(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'users' => 'required|array',
            'users.*' => 'required|exists:users,id',
            'points' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $users = User::whereIn('id', $request->users)->get();
            $successCount = 0;
            $failedUsers = [];

            foreach ($users as $user) {
                try {
                    $user->addPoint($request->points);
                    $successCount++;
                } catch (\Exception $e) {
                    $failedUsers[] = [
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "Points awarded to {$successCount} users successfully",
                'data' => [
                    'total_users' => count($request->users),
                    'success_count' => $successCount,
                    'failed_count' => count($failedUsers),
                    'failed_users' => $failedUsers
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to award points',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
