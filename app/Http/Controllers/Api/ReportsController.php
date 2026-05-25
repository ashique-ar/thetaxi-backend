<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\CustomerLoyaltyPoint;
use App\Models\Driver\Driver;
use App\Models\Vehicle\Vehicle;
use App\Models\Agent\Agent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportsController extends Controller
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct()
    {
        $this->middleware('auth:api');
        $this->middleware('permission:reports.view');
    }

    // ─────────────────────────────────────────────────────────────────
    // Dashboard
    // ─────────────────────────────────────────────────────────────────

    public function getDashboardStats(Request $request): JsonResponse
    {
        $period    = $request->get('period', 'month');
        $startDate = $this->getStartDate($period);

        $cacheKey = "reports.dashboard.{$period}";

        $stats = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($startDate) {
            return [
                'total_bookings'   => Booking::where('created_at', '>=', $startDate)->count(),
                'total_revenue'    => Booking::where('created_at', '>=', $startDate)
                    ->where('status', 'completed')->sum('total_actual'),
                'total_customers'  => Customer::where('created_at', '>=', $startDate)->count(),
                'total_vehicles'   => Vehicle::where('is_active', true)->count(),
                'booking_trends'   => $this->getBookingTrends($startDate),
                'revenue_trends'   => $this->getRevenueTrends($startDate),
            ];
        });

        return response()->json(['status' => 'success', 'data' => $stats]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Booking analytics
    // ─────────────────────────────────────────────────────────────────

    public function getBookingAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        $query   = Booking::query();
        $this->applyFilters($query, $filters);

        $analytics = [
            'total_bookings'           => (clone $query)->count(),
            'completed_bookings'       => (clone $query)->where('status', 'completed')->count(),
            'cancelled_bookings'       => (clone $query)->whereIn('status', ['cancelled', 'inquiry_cancelled'])->count(),
            'pending_bookings'         => (clone $query)->where('status', 'pending_approval')->count(),
            'average_duration'         => $this->getAverageBookingDuration(clone $query),
            'peak_hours'               => $this->getPeakHours(clone $query),
            'service_type_distribution' => $this->getServiceTypeDistribution(clone $query),
        ];

        return response()->json(['status' => 'success', 'data' => $analytics]);
    }

    public function getBookingTrendsEndpoint(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        $groupBy = $request->get('group_by', 'day');

        $query = Booking::query();
        $this->applyFilters($query, $filters);

        $trends = $query
            ->selectRaw("DATE(created_at) as date, COUNT(*) as bookings,
                SUM(CASE WHEN status = 'completed' THEN COALESCE(total_actual, 0) ELSE 0 END) as revenue")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json(['status' => 'success', 'data' => $trends]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Service type performance
    // ─────────────────────────────────────────────────────────────────

    public function getServiceTypePerformance(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);

        $query = Booking::query()
            ->join('booking_items', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('service_types', 'booking_items.service_type_id', '=', 'service_types.id')
            ->select('service_types.name as service_type')
            ->selectRaw('COUNT(DISTINCT bookings.id) as total_bookings')
            ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, 0) ELSE 0 END) as revenue")
            ->selectRaw("AVG(CASE WHEN bookings.status = 'completed' THEN COALESCE(bookings.total_actual, NULL) END) as avg_booking_value")
            ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN 1 ELSE 0 END) as completed_bookings")
            ->selectRaw("SUM(CASE WHEN bookings.status IN ('cancelled','inquiry_cancelled') THEN 1 ELSE 0 END) as cancelled_bookings")
            ->groupBy('service_types.id', 'service_types.name');

        $this->applyFilters($query, $filters);

        return response()->json(['status' => 'success', 'data' => $query->get()]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Financial
    // ─────────────────────────────────────────────────────────────────

    public function getFinancialReports(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        $period  = $request->get('period', 'monthly');

        $query = Booking::where('status', 'completed');
        $this->applyFilters($query, $filters);

        $fmt = $this->getDateFormat($period);

        $reports = $query
            ->selectRaw("$fmt as period,
                SUM(COALESCE(total_actual, 0)) as total_revenue,
                COUNT(*) as total_bookings,
                AVG(COALESCE(total_actual, 0)) as average_booking_value,
                SUM(COALESCE(commission_amount, 0)) as commissions_paid,
                SUM(COALESCE(total_actual, 0) - COALESCE(commission_amount, 0)) as net_revenue")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        foreach ($reports as $i => $report) {
            $prev                = $i > 0 ? $reports[$i - 1]->total_revenue : 0;
            $report->growth_rate = $prev > 0
                ? round((($report->total_revenue - $prev) / $prev) * 100, 2)
                : 0;
        }

        return response()->json(['status' => 'success', 'data' => $reports]);
    }

    public function getRevenueAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);

        $query         = Booking::where('status', 'completed');
        $this->applyFilters($query, $filters);

        $totalRevenue    = (clone $query)->sum('total_actual');
        $totalCommission = (clone $query)->sum('commission_amount');

        $analytics = [
            'total_revenue'           => (float) $totalRevenue,
            'total_commission'        => (float) $totalCommission,
            'net_revenue'             => (float) ($totalRevenue - $totalCommission),
            'revenue_by_service_type' => $this->getRevenueByServiceType(clone $query),
            'revenue_by_agent'        => $this->getRevenueByAgent(clone $query),
            'monthly_growth'          => $this->getMonthlyGrowth(clone $query),
        ];

        return response()->json(['status' => 'success', 'data' => $analytics]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Customer analytics
    // ─────────────────────────────────────────────────────────────────

    public function getCustomerAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);

        $analytics = [
            'total_customers'     => Customer::count(),
            'active_customers'    => Customer::whereHas('bookings', function ($q) use ($filters) {
                if (isset($filters['date_from'])) {
                    $q->where('created_at', '>=', $filters['date_from']);
                }
                if (isset($filters['date_to'])) {
                    $q->where('created_at', '<=', $filters['date_to']);
                }
            })->count(),
            'new_customers'       => $this->getNewCustomers($filters),
            'customer_demographics' => $this->getCustomerDemographics(),
            'loyalty_metrics'     => $this->getLoyaltyMetrics($filters),
            'customer_segments'   => $this->getCustomerSegments($filters),
        ];

        return response()->json(['status' => 'success', 'data' => $analytics]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Vehicle / fleet analytics
    // ─────────────────────────────────────────────────────────────────

    public function getVehicleAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);

        $analytics = [
            'total_vehicles'          => Vehicle::count(),
            'active_vehicles'         => Vehicle::where('is_active', true)->count(),
            'maintenance_vehicles'    => Vehicle::where('availability_status', 'unavailable_maintenance')->count(),
            'fleet_metrics'           => $this->getFleetMetrics($filters),
            'performance_by_category' => $this->getPerformanceByCategory($filters),
            'maintenance_metrics'     => $this->getMaintenanceMetrics($filters),
        ];

        return response()->json(['status' => 'success', 'data' => $analytics]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Export
    // ─────────────────────────────────────────────────────────────────

    public function exportReport(Request $request, string $type): Response|JsonResponse
    {
        $request->validate([
            'format' => 'required|in:csv,pdf',
        ]);

        $format  = $request->get('format', 'csv');
        $filters = $this->parseFilters($request);

        return match ($type) {
            'bookings'  => $this->exportBookingReport($filters, $format),
            'customers' => $this->exportCustomerReport($filters, $format),
            'vehicles'  => $this->exportVehicleReport($filters, $format),
            'financial' => $this->exportFinancialReport($filters, $format),
            default     => response()->json(['error' => 'Invalid report type'], 400),
        };
    }

    // ═════════════════════════════════════════════════════════════════
    // Private helpers — shared
    // ═════════════════════════════════════════════════════════════════

    private function parseFilters(Request $request): array
    {
        return array_filter([
            'date_from'      => $request->get('date_from'),
            'date_to'        => $request->get('date_to'),
            'vehicle_id'     => $request->get('vehicle_id'),
            'customer_id'    => $request->get('customer_id'),
            'booking_status' => $request->get('booking_status'),
            'agent_id'       => $request->get('agent_id'),
            'service_type_id' => $request->get('service_type_id'),
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
                    $query->where('created_at', '<=', $value . ' 23:59:59');
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
        return match ($period) {
            'week'  => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            'year'  => Carbon::now()->startOfYear(),
            default => Carbon::now()->startOfMonth(),
        };
    }

    private function getDateFormat(string $groupBy): string
    {
        return match ($groupBy) {
            'hour'    => "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')",
            'week'    => "DATE_FORMAT(created_at, '%x-W%v')",
            'month',
            'monthly' => "DATE_FORMAT(created_at, '%Y-%m')",
            'year',
            'yearly'  => "YEAR(created_at)",
            default   => "DATE(created_at)",
        };
    }

    // ─────────────────────────────────────────────────────────────────
    // Dashboard private helpers
    // ─────────────────────────────────────────────────────────────────

    private function getBookingTrends(Carbon $startDate): array
    {
        return Booking::where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as bookings')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function getRevenueTrends(Carbon $startDate): array
    {
        return Booking::where('created_at', '>=', $startDate)
            ->where('status', 'completed')
            ->selectRaw('DATE(created_at) as date, SUM(COALESCE(total_actual,0)) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────
    // Booking analytics helpers
    // ─────────────────────────────────────────────────────────────────

    private function getAverageBookingDuration($query): float
    {
        $avg = (clone $query)
            ->whereNotNull('from_date')
            ->whereNotNull('to_date')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, from_date, to_date)) as avg_hours')
            ->value('avg_hours');

        return round((float) $avg, 2);
    }

    private function getPeakHours($query): array
    {
        return (clone $query)
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as bookings')
            ->groupBy('hour')
            ->orderByDesc('bookings')
            ->limit(5)
            ->get()
            ->toArray();
    }

    private function getServiceTypeDistribution($query): array
    {
        return (clone $query)
            ->join('booking_items', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('service_types', 'booking_items.service_type_id', '=', 'service_types.id')
            ->select('service_types.name as service_type')
            ->selectRaw('COUNT(DISTINCT bookings.id) as count')
            ->groupBy('service_types.id', 'service_types.name')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────
    // Revenue helpers
    // ─────────────────────────────────────────────────────────────────

    private function getRevenueByServiceType($query): array
    {
        return (clone $query)
            ->join('booking_items', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('service_types', 'booking_items.service_type_id', '=', 'service_types.id')
            ->select('service_types.name as service_type')
            ->selectRaw('SUM(COALESCE(bookings.total_actual,0)) as revenue, COUNT(DISTINCT bookings.id) as bookings')
            ->groupBy('service_types.id', 'service_types.name')
            ->orderByDesc('revenue')
            ->get()
            ->toArray();
    }

    private function getRevenueByAgent($query): array
    {
        return (clone $query)
            ->join('agents', 'bookings.agent_id', '=', 'agents.id')
            ->join('users', 'agents.user_id', '=', 'users.id')
            ->selectRaw("CONCAT(users.first_name, ' ', users.last_name) as agent_name, agents.id as agent_id")
            ->selectRaw('SUM(COALESCE(bookings.total_actual,0)) as revenue, COUNT(*) as bookings,
                SUM(COALESCE(bookings.commission_amount,0)) as commission')
            ->groupBy('agents.id', 'users.first_name', 'users.last_name')
            ->orderByDesc('revenue')
            ->get()
            ->toArray();
    }

    private function getMonthlyGrowth($query): array
    {
        $rows = (clone $query)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(COALESCE(total_actual,0)) as revenue")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $result = [];
        foreach ($rows as $i => $row) {
            $prev = $i > 0 ? $rows[$i - 1]->revenue : 0;
            $result[] = [
                'month'       => $row->month,
                'revenue'     => (float) $row->revenue,
                'growth_rate' => $prev > 0 ? round((($row->revenue - $prev) / $prev) * 100, 2) : 0,
            ];
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────
    // Customer helpers
    // ─────────────────────────────────────────────────────────────────

    private function getNewCustomers(array $filters): array
    {
        $query = Customer::query();
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        $byMonth = (clone $query)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as new_customers")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();

        return [
            'total'    => (clone $query)->count(),
            'by_month' => $byMonth,
        ];
    }

    private function getCustomerDemographics(): array
    {
        $byCountry = Customer::join('users', 'customers.user_id', '=', 'users.id')
            ->selectRaw('users.country, COUNT(*) as count')
            ->whereNotNull('users.country')
            ->groupBy('users.country')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();

        $corporate = Customer::where('type', 'corporate')->count();
        $individual = Customer::where('type', '!=', 'corporate')->count();

        return [
            'by_country'  => $byCountry,
            'by_type'     => [
                ['type' => 'corporate', 'count' => $corporate],
                ['type' => 'individual', 'count' => $individual],
            ],
        ];
    }

    private function getLoyaltyMetrics(array $filters): array
    {
        $query = CustomerLoyaltyPoint::query();

        return [
            'total_enrolled'         => $query->count(),
            'total_points_issued'    => (clone $query)->sum('lifetime_points_earned'),
            'total_points_redeemed'  => (clone $query)->sum('lifetime_points_redeemed'),
            'active_point_balances'  => (clone $query)->sum('current_balance'),
            'by_tier'                => (clone $query)
                ->selectRaw('current_tier, COUNT(*) as customers, SUM(current_balance) as total_points')
                ->groupBy('current_tier')
                ->get()
                ->toArray(),
        ];
    }

    private function getCustomerSegments(array $filters): array
    {
        $completedBookings = Booking::where('status', 'completed')
            ->selectRaw('customer_id, COUNT(*) as booking_count, SUM(COALESCE(total_actual,0)) as lifetime_value')
            ->groupBy('customer_id');

        if (!empty($filters['date_from'])) {
            $completedBookings->where('created_at', '>=', $filters['date_from']);
        }

        $data = $completedBookings->get();

        $vip      = $data->where('booking_count', '>=', 10)->count();
        $regular  = $data->whereBetween('booking_count', [3, 9])->count();
        $new      = $data->where('booking_count', '<', 3)->count();

        return [
            ['segment' => 'VIP (10+ bookings)', 'count' => $vip],
            ['segment' => 'Regular (3-9 bookings)', 'count' => $regular],
            ['segment' => 'New (< 3 bookings)', 'count' => $new],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Fleet helpers
    // ─────────────────────────────────────────────────────────────────

    private function getFleetMetrics(array $filters): array
    {
        $totalVehicles  = Vehicle::count();
        $totalActive    = Vehicle::where('is_active', true)->count();

        // Utilisation: vehicles with at least 1 completed booking in the period
        $usedVehicleIds = Booking::where('status', 'completed')
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->where('created_at', '<=', $filters['date_to']))
            ->whereNotNull('vehicle_id')
            ->distinct()
            ->pluck('vehicle_id');

        $utilisationRate = $totalActive > 0
            ? round(($usedVehicleIds->count() / $totalActive) * 100, 2)
            : 0;

        return [
            'total'            => $totalVehicles,
            'active'           => $totalActive,
            'in_maintenance'   => Vehicle::where('availability_status', 'unavailable_maintenance')->count(),
            'utilisation_rate' => $utilisationRate,
        ];
    }

    private function getPerformanceByCategory(array $filters): array
    {
        return DB::table('bookings')
            ->join('vehicles', 'bookings.vehicle_id', '=', 'vehicles.id')
            ->join('vehicle_groups', 'vehicles.vehicle_group_id', '=', 'vehicle_groups.id')
            ->select('vehicle_groups.name as category')
            ->selectRaw('COUNT(DISTINCT bookings.id) as bookings,
                SUM(CASE WHEN bookings.status = \'completed\' THEN COALESCE(bookings.total_actual,0) ELSE 0 END) as revenue')
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('bookings.created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->where('bookings.created_at', '<=', $filters['date_to']))
            ->groupBy('vehicle_groups.id', 'vehicle_groups.name')
            ->orderByDesc('revenue')
            ->get()
            ->toArray();
    }

    private function getMaintenanceMetrics(array $filters): array
    {
        if (!DB::getSchemaBuilder()->hasTable('vehicle_maintenance_records')) {
            return [];
        }

        return DB::table('vehicle_maintenance_records')
            ->selectRaw('status, COUNT(*) as count, SUM(COALESCE(cost, 0)) as total_cost')
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->where('created_at', '<=', $filters['date_to']))
            ->groupBy('status')
            ->get()
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────
    // Export helpers
    // ─────────────────────────────────────────────────────────────────

    private function exportBookingReport(array $filters, string $format): Response
    {
        $query = Booking::with(['customer.user', 'serviceType'])
            ->select('booking_number', 'status', 'from_date', 'to_date', 'total_actual', 'created_at');

        $this->applyFilters($query, $filters);
        $rows = $query->orderByDesc('created_at')->get();

        $headers = ['Booking #', 'Status', 'From', 'To', 'Total', 'Created'];
        $data    = $rows->map(fn ($b) => [
            $b->booking_number,
            $b->status,
            $b->from_date,
            $b->to_date,
            $b->total_actual,
            $b->created_at,
        ])->toArray();

        return $this->csvResponse('bookings_report.csv', $headers, $data);
    }

    private function exportCustomerReport(array $filters, string $format): Response
    {
        $rows = Customer::with('user')
            ->selectRaw('customers.id, customers.created_at')
            ->join('users', 'customers.user_id', '=', 'users.id')
            ->selectRaw("CONCAT(users.first_name, ' ', users.last_name) as name, users.email, users.phone")
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('customers.created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->where('customers.created_at', '<=', $filters['date_to']))
            ->orderByDesc('customers.created_at')
            ->get();

        $headers = ['Name', 'Email', 'Phone', 'Created'];
        $data    = $rows->map(fn ($r) => [$r->name, $r->email, $r->phone, $r->created_at])->toArray();

        return $this->csvResponse('customers_report.csv', $headers, $data);
    }

    private function exportVehicleReport(array $filters, string $format): Response
    {
        $rows = Vehicle::with('group')
            ->select('title', 'license_plate', 'status', 'availability_status', 'created_at')
            ->orderBy('title')
            ->get();

        $headers = ['Name', 'License Plate', 'Status', 'Availability', 'Created'];
        $data    = $rows->map(fn ($v) => [
            $v->title,
            $v->license_plate,
            $v->status,
            $v->availability_status,
            $v->created_at,
        ])->toArray();

        return $this->csvResponse('vehicles_report.csv', $headers, $data);
    }

    private function exportFinancialReport(array $filters, string $format): Response
    {
        $query = Booking::where('status', 'completed')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as period,
                COUNT(*) as bookings,
                SUM(COALESCE(total_actual,0)) as revenue,
                SUM(COALESCE(commission_amount,0)) as commission,
                SUM(COALESCE(total_actual,0) - COALESCE(commission_amount,0)) as net_revenue");

        $this->applyFilters($query, $filters);
        $rows = $query->groupBy('period')->orderBy('period')->get();

        $headers = ['Period', 'Bookings', 'Revenue', 'Commission', 'Net Revenue'];
        $data    = $rows->map(fn ($r) => [
            $r->period, $r->bookings, $r->revenue, $r->commission, $r->net_revenue,
        ])->toArray();

        return $this->csvResponse('financial_report.csv', $headers, $data);
    }

    private function csvResponse(string $filename, array $headers, array $rows): Response
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }
}
