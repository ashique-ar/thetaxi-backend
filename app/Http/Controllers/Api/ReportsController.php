<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Vehicle\Vehicle;
use App\Models\Agent\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportsController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        $period = $request->get('period', 'month');
        $startDate = $this->getStartDate($period);
        
        $stats = [
            'total_bookings' => Booking::where('created_at', '>=', $startDate)->count(),
            'total_revenue' => Booking::where('created_at', '>=', $startDate)
                ->where('status', 'completed')->sum('total_actual'),
            'total_customers' => Customer::where('created_at', '>=', $startDate)->count(),
            'total_vehicles' => Vehicle::where('is_active', true)->count(),
            'booking_trends' => $this->getBookingTrends($startDate),
            'revenue_trends' => $this->getRevenueTrends($startDate)
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Get booking analytics
     */
    public function getBookingAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        
        $query = Booking::query();
        $this->applyFilters($query, $filters);

        $analytics = [
            'total_bookings' => $query->count(),
            'completed_bookings' => (clone $query)->where('status', 'completed')->count(),
            'cancelled_bookings' => (clone $query)->where('status', 'cancelled')->count(),
            'pending_bookings' => (clone $query)->where('status', 'pending')->count(),
            'average_duration' => $this->getAverageBookingDuration($query),
            'peak_hours' => $this->getPeakHours($query),
            'service_type_distribution' => $this->getServiceTypeDistribution($query)
        ];

        return response()->json([
            'status' => 'success',
            'data' => $analytics
        ]);
    }

    /**
     * Get booking trends
     */
    public function getBookingTrends(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        $groupBy = $request->get('group_by', 'day');
        
        $query = Booking::query();
        $this->applyFilters($query, $filters);
        
        $trends = $query->selectRaw("
            DATE({$this->getDateFormat($groupBy)}) as date,
            COUNT(*) as bookings,
            SUM(CASE WHEN status = 'completed' THEN total_actual ELSE 0 END) as revenue
        ")
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        return response()->json([
            'status' => 'success',
            'data' => $trends
        ]);
    }

    /**
     * Get service type performance
     */
    public function getServiceTypePerformance(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        
        $query = Booking::query()
            ->join('service_types', 'bookings.service_type_id', '=', 'service_types.id')
            ->select([
                'service_types.name as service_type',
                DB::raw('COUNT(bookings.id) as total_bookings'),
                DB::raw('SUM(CASE WHEN bookings.status = "completed" THEN bookings.total_actual ELSE 0 END) as revenue'),
                DB::raw('AVG(CASE WHEN bookings.status = "completed" THEN bookings.total_actual ELSE NULL END) as avg_booking_value'),
                DB::raw('SUM(CASE WHEN bookings.status = "completed" THEN 1 ELSE 0 END) as completed_bookings'),
                DB::raw('SUM(CASE WHEN bookings.status = "cancelled" THEN 1 ELSE 0 END) as cancelled_bookings')
            ])
            ->groupBy('service_types.id', 'service_types.name');
            
        $this->applyFilters($query, $filters);
        
        $performance = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $performance
        ]);
    }

    /**
     * Get financial reports
     */
    public function getFinancialReports(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        $period = $request->get('period', 'monthly');
        
        $query = Booking::where('status', 'completed');
        $this->applyFilters($query, $filters);
        
        $reports = $query->selectRaw("
            {$this->getDateFormat($period)} as period,
            SUM(total_actual) as total_revenue,
            COUNT(*) as total_bookings,
            AVG(total_actual) as average_booking_value,
            SUM(commission_amount) as commissions_paid,
            SUM(total_actual - commission_amount) as net_revenue
        ")
        ->groupBy('period')
        ->orderBy('period')
        ->get();

        // Calculate growth rates
        foreach ($reports as $index => $report) {
            if ($index > 0) {
                $previousRevenue = $reports[$index - 1]->total_revenue;
                $report->growth_rate = $previousRevenue > 0 
                    ? (($report->total_revenue - $previousRevenue) / $previousRevenue) * 100 
                    : 0;
            } else {
                $report->growth_rate = 0;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $reports
        ]);
    }

    /**
     * Get revenue analytics
     */
    public function getRevenueAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        
        $query = Booking::where('status', 'completed');
        $this->applyFilters($query, $filters);
        
        $analytics = [
            'total_revenue' => $query->sum('total_actual'),
            'total_commission' => $query->sum('commission_amount'),
            'net_revenue' => $query->sum(DB::raw('total_actual - commission_amount')),
            'revenue_by_service_type' => $this->getRevenueByServiceType($query),
            'revenue_by_agent' => $this->getRevenueByAgent($query),
            'monthly_growth' => $this->getMonthlyGrowth($query)
        ];

        return response()->json([
            'status' => 'success',
            'data' => $analytics
        ]);
    }

    /**
     * Get customer analytics
     */
    public function getCustomerAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        
        $analytics = [
            'total_customers' => Customer::count(),
            'active_customers' => Customer::whereHas('bookings', function($q) use ($filters) {
                if (isset($filters['date_from'])) {
                    $q->where('created_at', '>=', $filters['date_from']);
                }
                if (isset($filters['date_to'])) {
                    $q->where('created_at', '<=', $filters['date_to']);
                }
            })->count(),
            'new_customers' => $this->getNewCustomers($filters),
            'customer_demographics' => $this->getCustomerDemographics(),
            'loyalty_metrics' => $this->getLoyaltyMetrics($filters),
            'customer_segments' => $this->getCustomerSegments($filters)
        ];

        return response()->json([
            'status' => 'success',
            'data' => $analytics
        ]);
    }

    /**
     * Get vehicle analytics
     */
    public function getVehicleAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        
        $analytics = [
            'total_vehicles' => Vehicle::count(),
            'active_vehicles' => Vehicle::where('is_active', true)->count(),
            'maintenance_vehicles' => Vehicle::where('status', 'maintenance')->count(),
            'fleet_metrics' => $this->getFleetMetrics($filters),
            'performance_by_category' => $this->getPerformanceByCategory($filters),
            'maintenance_metrics' => $this->getMaintenanceMetrics($filters)
        ];

        return response()->json([
            'status' => 'success',
            'data' => $analytics
        ]);
    }

    /**
     * Export reports
     */
    public function exportReport(Request $request, string $type)
    {
        $request->validate([
            'format' => 'required|in:csv,excel,pdf'
        ]);

        $format = $request->get('format', 'csv');
        $filters = $this->parseFilters($request);
        
        switch ($type) {
            case 'bookings':
                return $this->exportBookingReport($filters, $format);
            case 'customers':
                return $this->exportCustomerReport($filters, $format);
            case 'vehicles':
                return $this->exportVehicleReport($filters, $format);
            case 'financial':
                return $this->exportFinancialReport($filters, $format);
            default:
                return response()->json(['error' => 'Invalid report type'], 400);
        }
    }

    // Private helper methods

    private function parseFilters(Request $request): array
    {
        return array_filter([
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'vehicle_id' => $request->get('vehicle_id'),
            'customer_id' => $request->get('customer_id'),
            'booking_status' => $request->get('booking_status'),
            'agent_id' => $request->get('agent_id'),
            'location_id' => $request->get('location_id'),
            'service_type_id' => $request->get('service_type_id')
        ]);
    }

    private function applyFilters($query, array $filters): void
    {
        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'date_from':
                    $query->where('created_at', '>=', $value);
                    break;
                case 'date_to':
                    $query->where('created_at', '<=', $value);
                    break;
                case 'vehicle_id':
                case 'customer_id':
                case 'agent_id':
                case 'service_type_id':
                    $query->where($key, $value);
                    break;
                case 'booking_status':
                    $query->where('status', $value);
                    break;
            }
        }
    }

    private function getStartDate(string $period): Carbon
    {
        switch ($period) {
            case 'week':
                return Carbon::now()->startOfWeek();
            case 'month':
                return Carbon::now()->startOfMonth();
            case 'year':
                return Carbon::now()->startOfYear();
            default:
                return Carbon::now()->startOfMonth();
        }
    }

    private function getDateFormat(string $groupBy): string
    {
        switch ($groupBy) {
            case 'hour':
                return 'created_at';
            case 'day':
                return 'created_at';
            case 'week':
                return 'YEARWEEK(created_at)';
            case 'month':
                return 'DATE_FORMAT(created_at, "%Y-%m")';
            case 'year':
                return 'YEAR(created_at)';
            default:
                return 'DATE(created_at)';
        }
    }

    // private function getBookingTrends(Carbon $startDate): array
    // {
    //     return Booking::where('created_at', '>=', $startDate)
    //         ->selectRaw('DATE(created_at) as date, COUNT(*) as bookings')
    //         ->groupBy('date')
    //         ->orderBy('date')
    //         ->get()
    //         ->toArray();
    // }

    private function getRevenueTrends(Carbon $startDate): array
    {
        return Booking::where('created_at', '>=', $startDate)
            ->where('status', 'completed')
            ->selectRaw('DATE(created_at) as date, SUM(total_actual) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    // Additional helper methods would be implemented here...
    // For brevity, I'm not including all helper methods, but they would follow similar patterns
}
