<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Agent\Agent;
use App\Models\Vehicle\Vehicle;
use App\Models\Booking\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    // Enforce authentication and permissions for analytics endpoints
    public function __construct()
    {
        $this->middleware('auth:api');
        $this->middleware('permission:analytics.dashboard')->only('getDashboardStats');
        $this->middleware('permission:analytics.bookings')->only('getBookingTrends');
        $this->middleware('permission:analytics.revenue')->only('getRevenueStats');
        $this->middleware('permission:analytics.customers')->only('getCustomerAnalytics');
        $this->middleware('permission:analytics.drivers')->only('getDriverPerformance');
        $this->middleware('permission:analytics.vehicles')->only('getVehicleUtilization');
        $this->middleware('permission:analytics.agents')->only('getAgentPerformance');
    }

    /**
     * Get main dashboard statistics
     * GET /api/analytics/dashboard
     */
    public function getDashboardStats(): JsonResponse
    {
        $stats = Cache::remember('dashboard_stats', 300, function () {
            return [
                'total_customers' => Customer::count(),
                'total_drivers' => Driver::count(),
                'total_vehicles' => Vehicle::count(),
                'total_agents' => Agent::count(),
                'total_bookings' => Booking::count(),
                'active_bookings' => Booking::where('status', 'active')->count(),
                'pending_bookings' => Booking::where('status', 'pending')->count(),
                'completed_bookings' => Booking::where('status', 'completed')->count(),
                'cancelled_bookings' => Booking::where('status', 'cancelled')->count(),
                'total_revenue' => Booking::where('status', 'completed')->sum('total_actual'),
                'monthly_revenue' => Booking::where('status', 'completed')
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->selectRaw('SUM(COALESCE(total_actual, total_estimated, 0)) as revenue')
                    ->value('revenue') ?? 0,
                'today_bookings' => Booking::whereDate('created_at', Carbon::today())->count(),
                'this_week_bookings' => Booking::whereBetween('created_at', [
                    Carbon::now()->startOfWeek(),
                    Carbon::now()->endOfWeek()
                ])->count(),
                'this_month_bookings' => Booking::whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->count(),
                'available_vehicles' => Vehicle::where('status', 'available')->count(),
                'active_drivers' => Driver::where('status', 'active')->count(),
                'top_performing_agents' => Agent::select(
                    'agents.id',
                    'users.first_name',
                    'users.last_name'
                )
                    ->selectRaw('COUNT(bookings.id) as total_bookings')
                    ->join('users', 'agents.user_id', '=', 'users.id')
                    ->leftJoin('bookings', 'agents.id', '=', 'bookings.agent_id')
                    ->groupBy('agents.id', 'users.first_name', 'users.last_name')
                    ->orderByDesc('total_bookings')
                    ->limit(5)
                    ->get(),
                'recent_bookings' => Booking::with(['customer.user', 'vehicle', 'driver.user'])
                    ->latest()
                    ->limit(5)
                    ->get()
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Get booking trends
     * GET /api/analytics/booking-trends
     */
    public function getBookingTrends(Request $request): JsonResponse
    {
        $period = $request->get('period', 'month'); // day, week, month, year
        $limit = $request->get('limit', 30);

        $cacheKey = "booking_trends_{$period}_{$limit}";

        $trends = Cache::remember($cacheKey, 300, function () use ($period, $limit) {
            $query = Booking::selectRaw($this->getDateFormat($period) . ' as date')
                ->selectRaw('COUNT(*) as total_bookings')
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings")
                ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings")
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN COALESCE(total_actual, total_estimated, 0) ELSE 0 END) as revenue")
                ->groupBy('date')
                ->orderBy('date', 'DESC')
                ->limit($limit);

            return $query->get();
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'trends' => $trends,
                'period' => $period,
                'limit' => $limit
            ]
        ]);
    }

    /**
     * Get revenue statistics
     * GET /api/analytics/revenue-stats
     */
    public function getRevenueStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'month');
        $cacheKey = "revenue_stats_{$period}";

        $stats = Cache::remember($cacheKey, 300, function () use ($period) {
            $dateRange = $this->getDateRange($period);
            $bookingValueStats = Booking::where('status', 'completed')
                ->whereBetween('created_at', $dateRange)
                ->selectRaw('AVG(COALESCE(total_actual, total_estimated, 0)) as average_booking_value')
                ->selectRaw('MAX(COALESCE(total_actual, total_estimated, 0)) as highest_booking_value')
                ->selectRaw('MIN(COALESCE(total_actual, total_estimated, 0)) as lowest_booking_value')
                ->first();

            return [
                'total_revenue' => Booking::where('status', 'completed')
                    ->whereBetween('created_at', $dateRange)
                    ->sum('total_actual'),
                'average_booking_value' => $bookingValueStats->average_booking_value ?? 0,
                'highest_booking_value' => $bookingValueStats->highest_booking_value ?? 0,
                'lowest_booking_value' => $bookingValueStats->lowest_booking_value ?? 0,
                'revenue_by_vehicle_type' => Vehicle::select('vehicle_categories.name as category')
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN bookings.total_estimated ELSE 0 END) as revenue")
                    ->join('vehicle_categories', 'vehicles.category_id', '=', 'vehicle_categories.id')
                    ->leftJoin('booking_items', 'vehicles.id', '=', 'booking_items.vehicle_id')
                    ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                    ->whereBetween('bookings.created_at', $dateRange)
                    ->groupBy('vehicle_categories.id', 'vehicle_categories.name')
                    ->orderByDesc('revenue')
                    ->get(),
                'revenue_by_agent' => Agent::select(
                    'agents.id',
                    'users.first_name',
                    'users.last_name'
                )
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, bookings.total_estimated, 0) ELSE 0 END) as revenue")
                    ->join('users', 'agents.user_id', '=', 'users.id')
                    ->leftJoin('bookings', 'agents.id', '=', 'bookings.agent_id')
                    ->whereBetween('bookings.created_at', $dateRange)
                    ->groupBy('agents.id', 'users.first_name', 'users.last_name')
                    ->orderByDesc('revenue')
                    ->limit(10)
                    ->get()
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Get customer analytics
     * GET /api/analytics/customer-analytics
     */
    public function getCustomerAnalytics(): JsonResponse
    {
        $stats = Cache::remember('customer_analytics', 300, function () {
            return [
                'total_customers' => Customer::count(),
                'new_customers_this_month' => Customer::whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->count(),
                'active_customers' => Customer::whereHas('bookings', function ($query) {
                    $query->where('created_at', '>=', Carbon::now()->subDays(30));
                })->count(),
                'top_customers' => Customer::select(
                    'customers.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email'
                )
                    ->selectRaw('COUNT(bookings.id) as total_bookings')
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, bookings.total_estimated, 0) ELSE 0 END) as total_spent")
                    ->join('users', 'customers.user_id', '=', 'users.id')
                    ->leftJoin('bookings', 'customers.id', '=', 'bookings.customer_id')
                    ->groupBy('customers.id', 'users.first_name', 'users.last_name', 'users.email')
                    ->orderByDesc('total_spent')
                    ->limit(10)
                    ->get(),
                'customer_loyalty_distribution' => $this->getCustomerLoyaltyDistribution(),
                'customer_registration_trends' => Customer::selectRaw('DATE(created_at) as date')
                    ->selectRaw('COUNT(*) as registrations')
                    ->where('created_at', '>=', Carbon::now()->subDays(30))
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get()
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Get driver performance
     * GET /api/analytics/driver-performance
     */
    public function getDriverPerformance(): JsonResponse
    {
        $stats = Cache::remember('driver_performance', 300, function () {
            return [
                'total_drivers' => Driver::count(),
                'active_drivers' => Driver::where('status', 'active')->count(),
                'top_drivers' => Driver::select(
                    'drivers.id',
                    'users.first_name',
                    'users.last_name'
                )
                    ->selectRaw('COUNT(DISTINCT bookings.id) as total_bookings')
                    ->selectRaw('AVG(CASE WHEN bookings.driver_rating IS NOT NULL THEN bookings.driver_rating ELSE 0 END) as average_rating')
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN bookings.total_actual ELSE 0 END) as total_revenue")
                    ->join('users', 'drivers.user_id', '=', 'users.id')
                    ->leftJoin('booking_items', 'drivers.id', '=', 'booking_items.driver_id')
                    ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                    ->groupBy('drivers.id', 'users.first_name', 'users.last_name')
                    ->orderByDesc('total_revenue')
                    ->limit(10)
                    ->get(),
                'driver_status_distribution' => Driver::select('status')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('status')
                    ->get(),
                'driver_ratings_distribution' => Booking::selectRaw('CASE 
                        WHEN driver_rating >= 4.5 THEN \'5 Stars\'
                        WHEN driver_rating >= 3.5 THEN \'4 Stars\'
                        WHEN driver_rating >= 2.5 THEN \'3 Stars\'
                        WHEN driver_rating >= 1.5 THEN \'2 Stars\'
                        ELSE \'1 Star\'
                    END as rating_category')
                    ->selectRaw('COUNT(*) as count')
                    ->whereNotNull('driver_rating')
                    ->groupBy('rating_category')
                    ->get()
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Get vehicle utilization
     * GET /api/analytics/vehicle-utilization
     */
    public function getVehicleUtilization(): JsonResponse
    {
        $stats = Cache::remember('vehicle_utilization', 300, function () {
            return [
                'total_vehicles' => Vehicle::count(),
                'available_vehicles' => Vehicle::where('status', 'available')->count(),
                'in_use_vehicles' => Vehicle::where('status', 'in_use')->count(),
                'maintenance_vehicles' => Vehicle::where('status', 'maintenance')->count(),
                'most_booked_vehicles' => Vehicle::select(
                    'vehicles.id',
                    'vehicles.registration_number',
                    'vehicle_makes.name as make',
                    'vehicle_models.name as model'
                )
                    ->selectRaw('COUNT(DISTINCT bookings.id) as total_bookings')
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN bookings.total_estimated ELSE 0 END) as total_revenue")
                    ->join('vehicle_makes', 'vehicles.make_id', '=', 'vehicle_makes.id')
                    ->join('vehicle_models', 'vehicles.model_id', '=', 'vehicle_models.id')
                    ->leftJoin('booking_items', 'vehicles.id', '=', 'booking_items.vehicle_id')
                    ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                    ->groupBy('vehicles.id', 'vehicles.registration_number', 'vehicle_makes.name', 'vehicle_models.name')
                    ->orderByDesc('total_bookings')
                    ->limit(10)
                    ->get(),
                'vehicle_status_distribution' => Vehicle::select('status')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('status')
                    ->get(),
                'vehicle_category_performance' => Vehicle::select('vehicle_categories.name as category')
                    ->selectRaw('COUNT(DISTINCT vehicles.id) as total_vehicles')
                    ->selectRaw('COUNT(DISTINCT bookings.id) as total_bookings')
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN bookings.total_estimated ELSE 0 END) as total_revenue")
                    ->join('vehicle_categories', 'vehicles.category_id', '=', 'vehicle_categories.id')
                    ->leftJoin('booking_items', 'vehicles.id', '=', 'booking_items.vehicle_id')
                    ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                    ->groupBy('vehicle_categories.id', 'vehicle_categories.name')
                    ->orderByDesc('total_revenue')
                    ->get()
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Get agent performance
     * GET /api/analytics/agent-performance
     */
    public function getAgentPerformance(): JsonResponse
    {
        $stats = Cache::remember('agent_performance', 300, function () {
            return [
                'total_agents' => Agent::count(),
                'active_agents' => Agent::where('status', 'active')->count(),
                'top_agents' => Agent::select(
                    'agents.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email'
                )
                    ->selectRaw('COUNT(bookings.id) as total_bookings')
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, bookings.total_estimated, 0) ELSE 0 END) as total_revenue")
                    ->selectRaw('SUM(agent_commissions.amount) as total_commission')
                    ->join('users', 'agents.user_id', '=', 'users.id')
                    ->leftJoin('bookings', 'agents.id', '=', 'bookings.agent_id')
                    ->leftJoin('agent_commissions', 'agents.id', '=', 'agent_commissions.agent_id')
                    ->groupBy('agents.id', 'users.first_name', 'users.last_name', 'users.email')
                    ->orderByDesc('total_revenue')
                    ->limit(10)
                    ->get(),
                'agent_status_distribution' => Agent::select('status')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('status')
                    ->get(),
                'monthly_agent_performance' => Agent::select(
                    'agents.id',
                    'users.first_name',
                    'users.last_name'
                )
                    ->selectRaw('COUNT(bookings.id) as monthly_bookings')
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, bookings.total_estimated, 0) ELSE 0 END) as monthly_revenue")
                    ->join('users', 'agents.user_id', '=', 'users.id')
                    ->leftJoin('bookings', 'agents.id', '=', 'bookings.agent_id')
                    ->whereMonth('bookings.created_at', Carbon::now()->month)
                    ->whereYear('bookings.created_at', Carbon::now()->year)
                    ->groupBy('agents.id', 'users.first_name', 'users.last_name')
                    ->orderByDesc('monthly_revenue')
                    ->limit(10)
                    ->get()
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Get customer loyalty distribution
     */
    private function getCustomerLoyaltyDistribution(): array
    {
        $customers = Customer::select([
            'customers.id',
            'users.' . config('gamify.reputation_column', 'reputation') . ' as points'
        ])
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->get();

        $distribution = [
            'Bronze' => 0,
            'Silver' => 0,
            'Gold' => 0,
            'Platinum' => 0
        ];

        foreach ($customers as $customer) {
            $points = $customer->points ?? 0;

            if ($points >= 1000) {
                $distribution['Platinum']++;
            } elseif ($points >= 500) {
                $distribution['Gold']++;
            } elseif ($points >= 100) {
                $distribution['Silver']++;
            } else {
                $distribution['Bronze']++;
            }
        }

        return $distribution;
    }

    /**
     * Get date format for SQL based on period
     */
    private function getDateFormat(string $period): string
    {
        switch ($period) {
            case 'day':
                return 'DATE(created_at)';
            case 'week':
                return 'YEARWEEK(created_at)';
            case 'month':
                return 'YEAR(created_at), MONTH(created_at)';
            case 'year':
                return 'YEAR(created_at)';
            default:
                return 'DATE(created_at)';
        }
    }

    /**
     * Get date range based on period
     */
    private function getDateRange(string $period): array
    {
        $now = Carbon::now();

        switch ($period) {
            case 'day':
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case 'week':
                return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()];
            case 'month':
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            case 'year':
                return [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
            default:
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
        }
    }
}
