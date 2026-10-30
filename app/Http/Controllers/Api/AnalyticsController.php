<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    public function getDashboardStats(Request $request): JsonResponse
    {
        $stats = Cache::remember('dashboard_stats.installation', 300, function () {
            $customers = Customer::query();
            $drivers = $this->driversWithActiveStaff();
            $vehicles = Vehicle::query();
            $agents = Agent::query();
            $bookings = Booking::query();
            return [
                'total_customers' => $customers->count(),
                'total_drivers' => $drivers->count(),
                'total_vehicles' => $vehicles->count(),
                'total_agents' => $agents->count(),
                'total_bookings' => (clone $bookings)->count(),
                'active_bookings' => (clone $bookings)->where('status', 'active')->count(),
                'pending_bookings' => (clone $bookings)->where('status', 'pending')->count(),
                'completed_bookings' => (clone $bookings)->where('status', 'completed')->count(),
                'cancelled_bookings' => (clone $bookings)->where('status', 'cancelled')->count(),
                'total_revenue' => (clone $bookings)->where('status', 'completed')
                    ->selectRaw('SUM(COALESCE(total_actual, total_estimated, 0)) as revenue')
                    ->value('revenue') ?? 0,
                'monthly_revenue' => (clone $bookings)->where('status', 'completed')
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->selectRaw('SUM(COALESCE(total_actual, total_estimated, 0)) as revenue')
                    ->value('revenue') ?? 0,
                'today_bookings' => (clone $bookings)->whereDate('created_at', Carbon::today())->count(),
                'this_week_bookings' => (clone $bookings)->whereBetween('created_at', [
                    Carbon::now()->startOfWeek(),
                    Carbon::now()->endOfWeek()
                ])->count(),
                'this_month_bookings' => (clone $bookings)->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->count(),
                'available_vehicles' => (clone $vehicles)->where('status', 'available')->count(),
                'active_drivers' => (clone $drivers)->where('status', 'active')->count(),
                'top_performing_agents' => $this->agentsWithBookings()->select(
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
        $filters = $request->validate([
            'period' => ['sometimes', 'in:day,week,month,year'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);
        $period = $filters['period'] ?? 'month';
        $limit = (int) ($filters['limit'] ?? 30);

        $cacheKey = "booking_trends_installation_{$period}_{$limit}";

        $trends = Cache::remember($cacheKey, 300, function () use ($period, $limit) {
            $query = Booking::query()->selectRaw($this->getDateFormat($period) . ' as date')
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
        $filters = $request->validate(['period' => ['sometimes', 'in:day,week,month,year']]);
        $period = $filters['period'] ?? 'month';
        $cacheKey = "revenue_stats_installation_{$period}";

        $stats = Cache::remember($cacheKey, 300, function () use ($period) {
            $dateRange = $this->getDateRange($period);
            $bookingValueStats = Booking::query()->where('status', 'completed')
                ->whereBetween('created_at', $dateRange)
                ->selectRaw('AVG(COALESCE(total_actual, total_estimated, 0)) as average_booking_value')
                ->selectRaw('MAX(COALESCE(total_actual, total_estimated, 0)) as highest_booking_value')
                ->selectRaw('MIN(COALESCE(total_actual, total_estimated, 0)) as lowest_booking_value')
                ->first();

            return [
                'total_revenue' => Booking::query()->where('status', 'completed')
                    ->whereBetween('created_at', $dateRange)
                    ->selectRaw('SUM(COALESCE(total_actual, total_estimated, 0)) as revenue')
                    ->value('revenue') ?? 0,
                'average_booking_value' => $bookingValueStats->average_booking_value ?? 0,
                'highest_booking_value' => $bookingValueStats->highest_booking_value ?? 0,
                'lowest_booking_value' => $bookingValueStats->lowest_booking_value ?? 0,
                'revenue_by_vehicle_type' => Vehicle::query()->select('vehicle_categories.name as category')
                    ->selectRaw("COALESCE(NULLIF(UPPER(booking_items.currency), ''), NULLIF(UPPER(bookings.currency), ''), 'LKR') as currency")
                    ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(booking_items.total_price, 0) ELSE 0 END) as revenue")
                    ->join('vehicle_categories', 'vehicles.category_id', '=', 'vehicle_categories.id')
                    ->leftJoin('booking_items', 'vehicles.id', '=', 'booking_items.vehicle_id')
                    ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                    ->whereNull('booking_items.deleted_at')
                    ->whereBetween('bookings.created_at', $dateRange)
                    ->groupBy('vehicle_categories.id', 'vehicle_categories.name', 'currency')
                    ->orderByDesc('revenue')
                    ->get(),
                'revenue_by_agent' => $this->agentsWithBookings()->select(
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
    public function getCustomerAnalytics(Request $request): JsonResponse
    {
        $stats = Cache::remember('customer_analytics.installation', 300, function () {
            $customers = Customer::query();
            return [
                'total_customers' => (clone $customers)->count(),
                'new_customers_this_month' => (clone $customers)->whereMonth('customers.created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year)
                    ->count(),
                'active_customers' => (clone $customers)->whereHas('bookings', function ($query) {
                    $query->where('created_at', '>=', Carbon::now()->subDays(30));
                })->count(),
                'top_customers' => Customer::query()->select(
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
                'customer_registration_trends' => Customer::query()->selectRaw('DATE(customers.created_at) as date')
                    ->selectRaw('COUNT(*) as registrations')
                    ->where('customers.created_at', '>=', Carbon::now()->subDays(30))
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
    public function getDriverPerformance(Request $request): JsonResponse
    {
        $stats = Cache::remember('driver_performance.installation', 300, function () {
            $driverBookings = \Illuminate\Support\Facades\DB::table('booking_items')
                ->select('driver_id', 'booking_id')
                ->whereNotNull('driver_id')
                ->whereNull('deleted_at')
                ->distinct();
            $topDrivers = $this->driversWithActiveStaff()->select(
                'drivers.id', 'users.first_name', 'users.last_name',
            )
                ->selectRaw('COUNT(DISTINCT bookings.id) as total_bookings')
                ->selectRaw('AVG(CASE WHEN bookings.driver_rating IS NOT NULL THEN bookings.driver_rating ELSE 0 END) as average_rating')
                ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, bookings.total_estimated, 0) ELSE 0 END) as total_revenue")
                ->join('users', 'drivers.user_id', '=', 'users.id')
                ->leftJoinSub($driverBookings, 'driver_bookings', fn ($join) => $join->on('driver_bookings.driver_id', '=', 'drivers.id'))
                ->leftJoin('bookings', 'driver_bookings.booking_id', '=', 'bookings.id');

            $driverRatings = Booking::query()->selectRaw('CASE
                        WHEN driver_rating >= 4.5 THEN \'5 Stars\'
                        WHEN driver_rating >= 3.5 THEN \'4 Stars\'
                        WHEN driver_rating >= 2.5 THEN \'3 Stars\'
                        WHEN driver_rating >= 1.5 THEN \'2 Stars\'
                        ELSE \'1 Star\'
                    END as rating_category')
                ->selectRaw('COUNT(*) as count')->whereNotNull('driver_rating')->groupBy('rating_category');
            return [
                'total_drivers' => $this->driversWithActiveStaff()->count(),
                'active_drivers' => $this->driversWithActiveStaff()->where('status', 'active')->count(),
                'top_drivers' => $topDrivers->groupBy('drivers.id', 'users.first_name', 'users.last_name')->orderByDesc('total_revenue')->limit(10)->get(),
                'driver_status_distribution' => $this->driversWithActiveStaff()->select('status')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('status')
                    ->get(),
                'driver_ratings_distribution' => $driverRatings->get()
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
    public function getVehicleUtilization(Request $request): JsonResponse
    {
        $stats = Cache::remember('vehicle_utilization.installation', 300, function () {
            $mostBooked = Vehicle::query()->select(
                'vehicles.id', 'vehicles.registration_number', 'vehicle_makes.name as make', 'vehicle_models.name as model',
            )
                ->selectRaw("COALESCE(NULLIF(UPPER(booking_items.currency), ''), NULLIF(UPPER(bookings.currency), ''), 'LKR') as currency")
                ->selectRaw('COUNT(DISTINCT bookings.id) as total_bookings')
                ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(booking_items.total_price, 0) ELSE 0 END) as total_revenue")
                ->join('vehicle_makes', 'vehicles.make_id', '=', 'vehicle_makes.id')
                ->join('vehicle_models', 'vehicles.model_id', '=', 'vehicle_models.id')
                ->leftJoin('booking_items', 'vehicles.id', '=', 'booking_items.vehicle_id')
                ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id');
            $mostBooked->whereNull('booking_items.deleted_at');

            $categoryPerformance = Vehicle::query()->select('vehicle_categories.name as category')
                ->selectRaw("COALESCE(NULLIF(UPPER(booking_items.currency), ''), NULLIF(UPPER(bookings.currency), ''), 'LKR') as currency")
                ->selectRaw('COUNT(DISTINCT vehicles.id) as total_vehicles')
                ->selectRaw('COUNT(DISTINCT bookings.id) as total_bookings')
                ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(booking_items.total_price, 0) ELSE 0 END) as total_revenue")
                ->join('vehicle_categories', 'vehicles.category_id', '=', 'vehicle_categories.id')
                ->leftJoin('booking_items', 'vehicles.id', '=', 'booking_items.vehicle_id')
                ->leftJoin('bookings', 'booking_items.booking_id', '=', 'bookings.id');
            $categoryPerformance->whereNull('booking_items.deleted_at');

            $vehicles = Vehicle::query();
            return [
                'total_vehicles' => (clone $vehicles)->count(),
                'available_vehicles' => (clone $vehicles)->where('status', 'available')->count(),
                'in_use_vehicles' => (clone $vehicles)->where('status', 'in_use')->count(),
                'maintenance_vehicles' => (clone $vehicles)->where('status', 'maintenance')->count(),
                'most_booked_vehicles' => $mostBooked->groupBy('vehicles.id', 'vehicles.registration_number', 'vehicle_makes.name', 'vehicle_models.name', 'currency')->orderByDesc('total_bookings')->limit(10)->get(),
                'vehicle_status_distribution' => Vehicle::query()->select('status')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('status')
                    ->get(),
                'vehicle_category_performance' => $categoryPerformance->groupBy('vehicle_categories.id', 'vehicle_categories.name', 'currency')->orderByDesc('total_revenue')->get()
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
    public function getAgentPerformance(Request $request): JsonResponse
    {
        $stats = Cache::remember('agent_performance.installation', 300, function () {
            $commissionsByBooking = \Illuminate\Support\Facades\DB::table('agent_commissions')
                ->selectRaw('booking_id, agent_id, SUM(amount) as amount')
                ->whereNull('deleted_at')
                ->groupBy('booking_id', 'agent_id');
            $topAgents = $this->agentsWithBookings()->select(
                'agents.id', 'users.first_name', 'users.last_name', 'users.email',
            )
                ->selectRaw('COUNT(bookings.id) as total_bookings')
                ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, bookings.total_estimated, 0) ELSE 0 END) as total_revenue")
                ->selectRaw('SUM(agent_commissions.amount) as total_commission')
                ->join('users', 'agents.user_id', '=', 'users.id')
                ->leftJoin('bookings', 'agents.id', '=', 'bookings.agent_id')
                ->leftJoinSub($commissionsByBooking, 'agent_commissions', fn ($join) => $join
                    ->on('agent_commissions.agent_id', '=', 'agents.id')
                    ->on('agent_commissions.booking_id', '=', 'bookings.id'));

            $monthlyAgents = $this->agentsWithBookings()->select(
                'agents.id', 'users.first_name', 'users.last_name',
            )
                ->selectRaw('COUNT(bookings.id) as monthly_bookings')
                ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, bookings.total_estimated, 0) ELSE 0 END) as monthly_revenue")
                ->join('users', 'agents.user_id', '=', 'users.id')
                ->leftJoin('bookings', 'agents.id', '=', 'bookings.agent_id')
                ->whereMonth('bookings.created_at', Carbon::now()->month)
                ->whereYear('bookings.created_at', Carbon::now()->year);

            return [
                'total_agents' => Agent::query()->count(),
                'active_agents' => Agent::query()->where('status', 'active')->count(),
                'top_agents' => $topAgents->groupBy('agents.id', 'users.first_name', 'users.last_name', 'users.email')->orderByDesc('total_revenue')->limit(10)->get(),
                'agent_status_distribution' => Agent::query()->select('status')
                    ->selectRaw('COUNT(*) as count')
                    ->groupBy('status')
                    ->get(),
                'monthly_agent_performance' => $monthlyAgents->groupBy('agents.id', 'users.first_name', 'users.last_name')->orderByDesc('monthly_revenue')->limit(10)->get()
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    private function driversWithActiveStaff()
    {
        return Driver::query()->whereExists(fn ($staff) => $staff->selectRaw('1')->from('staff')
            ->whereColumn('staff.user_id', 'drivers.user_id')
            ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at'));
    }

    private function agentsWithBookings()
    {
        return Agent::query()->whereExists(function ($bookings): void {
            $bookings->selectRaw('1')->from('bookings as agent_booking')
                ->whereColumn('agent_booking.agent_id', 'agents.id');
        });
    }

    /**
     * Get customer loyalty distribution
     */
    private function getCustomerLoyaltyDistribution(): array
    {
        $customers = Customer::query()->select([
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
                return "TO_CHAR(created_at, 'IYYY-\"W\"IW')";
            case 'month':
                return "TO_CHAR(created_at, 'YYYY-MM')";
            case 'year':
                return "TO_CHAR(created_at, 'YYYY')";
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
