<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingGeneratedReport;
use App\Models\Customer;
use App\Models\CustomerLoyaltyPoint;
use App\Models\Country;
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
        $this->middleware('permission:reports.generate')->only(['exportReport', 'deleteGeneratedReport']);
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
                'revenue_by_currency' => Booking::where('created_at', '>=', $startDate)
                    ->where('status', 'completed')
                    ->selectRaw("COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency, SUM(COALESCE(total_actual, 0)) as revenue")
                    ->groupBy('currency')->orderBy('currency')->get(),
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
            ->selectRaw("COALESCE(NULLIF(UPPER(bookings.currency), ''), 'LKR') as currency")
            ->selectRaw("COUNT(DISTINCT bookings.id) as total_bookings")
            ->selectRaw("COUNT(DISTINCT CASE WHEN bookings.status = 'completed' THEN bookings.id END) as completed_bookings")
            ->selectRaw("COUNT(DISTINCT CASE WHEN bookings.status IN ('cancelled','inquiry_cancelled') THEN bookings.id END) as cancelled_bookings")
            ->selectRaw("SUM(CASE WHEN bookings.status = 'completed' THEN COALESCE(booking_items.total_price, 0) ELSE 0 END) as revenue")
            ->selectRaw("AVG(CASE WHEN bookings.status = 'completed' THEN booking_items.total_price END) as average_item_value")
            ->groupBy('service_types.id', 'service_types.name', 'currency');

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
                COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency,
                SUM(COALESCE(total_actual, 0)) as total_revenue,
                COUNT(*) as total_bookings,
                AVG(COALESCE(total_actual, 0)) as average_booking_value")
            ->groupBy('period', 'currency')
            ->orderBy('period')->orderBy('currency')
            ->get();

        $bookingDateFormat = str_replace('created_at', 'bookings.created_at', $fmt);
        $serviceRevenue = DB::table('booking_items')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('service_types', 'booking_items.service_type_id', '=', 'service_types.id')
            ->where('bookings.status', 'completed')
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('bookings.created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('bookings.created_at', '<=', $filters['date_to'] . ' 23:59:59'))
            ->selectRaw("{$bookingDateFormat} as period, COALESCE(NULLIF(UPPER(bookings.currency), ''), 'LKR') as currency, service_types.name as service_type, SUM(COALESCE(booking_items.total_price, 0)) as revenue")
            ->groupBy('period', 'currency', 'service_types.name')
            ->get()
            ->groupBy(fn ($row) => $row->period . '|' . $row->currency);

        foreach ($reports as $report) {
            $report->revenue_by_service_type = ($serviceRevenue->get($report->period . '|' . $report->currency) ?? collect())
                ->map(fn ($row) => ['service_type' => $row->service_type, 'revenue' => (float) $row->revenue])
                ->values();
        }

        $previousRevenue = [];
        foreach ($reports as $report) {
            $currency = (string) $report->currency;
            $prev = $previousRevenue[$currency] ?? 0;
            $report->growth_rate = $prev > 0
                ? round((($report->total_revenue - $prev) / $prev) * 100, 2)
                : 0;
            $previousRevenue[$currency] = (float) $report->total_revenue;
        }

        return response()->json(['status' => 'success', 'data' => $reports]);
    }

    public function getRevenueAnalytics(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);

        $query         = Booking::where('status', 'completed');
        $this->applyFilters($query, $filters);

        $revenueByCurrency = (clone $query)
            ->selectRaw("COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency, SUM(COALESCE(total_actual, 0)) as revenue")
            ->groupBy('currency')->orderBy('currency')->get();

        $analytics = [
            'revenue_by_currency'     => $revenueByCurrency,
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
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                }
            })->count(),
            'new_customers'       => $this->getNewCustomers($filters),
            'customer_demographics' => $this->getCustomerDemographics(),
            'loyalty_metrics'     => $this->getLoyaltyMetrics($filters),
            'customer_segments'   => $this->getCustomerSegments($filters),
        ];

        return response()->json(['status' => 'success', 'data' => $analytics]);
    }

    public function getCustomerSegmentation(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->getCustomerSegments($this->parseFilters($request)),
        ]);
    }

    public function getLoyaltyMetricsEndpoint(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->getLoyaltyMetrics($this->parseFilters($request)),
        ]);
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

    public function getFleetPerformance(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->getFleetMetrics($this->parseFilters($request)),
        ]);
    }

    public function getMaintenanceAnalytics(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->getMaintenanceMetrics($this->parseFilters($request)),
        ]);
    }

    public function getProfitabilityAnalysis(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        $query = Booking::where('status', 'completed');
        $this->applyFilters($query, $filters);

        $byCurrency = (clone $query)
            ->selectRaw("COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency, SUM(COALESCE(total_actual, 0)) as revenue")
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'revenue' => (float) $row->revenue]);

        return response()->json([
            'status' => 'success',
            'data' => ['revenue_by_currency' => $byCurrency],
        ]);
    }

    public function getBookingReports(Request $request): JsonResponse
    {
        $filters = $this->parseFilters($request);
        $query = Booking::with(['customer.user', 'bookingItems.vehicle'])
            ->select('id', 'booking_number', 'customer_id', 'agent_id', 'total_actual', 'currency', 'status', 'created_at');

        $this->applyFilters($query, $filters);

        $rows = $query->orderByDesc('created_at')->limit(500)->get()->map(fn ($booking) => [
            'id' => (string) $booking->id,
            'booking_number' => $booking->booking_number,
            'customer_name' => trim(($booking->customer?->user?->first_name ?? '') . ' ' . ($booking->customer?->user?->last_name ?? '')),
            'vehicle_info' => $booking->bookingItems->pluck('vehicle.title')->filter()->first()
                ?? $booking->bookingItems->pluck('vehicle.license_plate')->filter()->first()
                ?? '',
            'start_date' => $booking->bookingItems->min('from_date'),
            'end_date' => $booking->bookingItems->max('to_date'),
            'total_amount' => (float) $booking->total_actual,
            'currency' => strtoupper((string) ($booking->currency ?: 'LKR')),
            'status' => $booking->status,
        ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function getCustomerReports(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->customerReportRows($this->parseFilters($request))]);
    }

    private function customerReportRows(array $filters): array
    {
        $customers = Customer::with('user')
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('created_at', '<=', $filters['date_to'] . ' 23:59:59'))
            ->withCount('bookings')
            ->withMax('bookings', 'created_at')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
        $spend = DB::table('bookings')
            ->whereIn('customer_id', $customers->pluck('id'))
            ->where('status', 'completed')
            ->selectRaw("customer_id, COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency, SUM(COALESCE(total_actual, 0)) as total_spent")
            ->groupBy('customer_id', 'currency')
            ->orderBy('currency')
            ->get()
            ->groupBy(fn ($row) => (string) $row->customer_id);

        return $customers->map(function ($customer) use ($spend) {
            $spentByCurrency = $spend->get((string) $customer->id, collect())->map(fn ($row) => [
                'currency' => (string) $row->currency,
                'total_spent' => (float) $row->total_spent,
            ])->values()->all();
            $totalBookings = (int) $customer->bookings_count;

            return [
                    'id' => (string) $customer->id,
                    'name' => trim(($customer->user?->first_name ?? '') . ' ' . ($customer->user?->last_name ?? '')),
                    'customer_name' => trim(($customer->user?->first_name ?? '') . ' ' . ($customer->user?->last_name ?? '')),
                    'email' => $customer->user?->email,
                    'phone' => $customer->user?->phone,
                    'total_bookings' => $totalBookings,
                    'spend_by_currency' => $spentByCurrency,
                    'last_booking_date' => $customer->bookings_max_created_at,
                    'registration_date' => $customer->created_at,
                    'status' => $customer->status ?? 'active',
                    'loyalty_tier' => CustomerLoyaltyPoint::where('customer_id', $customer->id)->value('current_tier') ?? '',
                ];
        })->all();
    }

    public function getVehicleReports(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->vehicleReportRows($this->parseFilters($request))]);
    }

    private function vehicleReportRows(array $filters): array
    {
        $metrics = DB::table('booking_items')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->whereNotNull('booking_items.vehicle_id')
            ->whereNotNull('booking_items.from_date')
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('booking_items.from_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('booking_items.from_date', '<=', $filters['date_to']))
            ->selectRaw("booking_items.vehicle_id, COALESCE(NULLIF(UPPER(booking_items.currency), ''), NULLIF(UPPER(bookings.currency), ''), 'LKR') as currency, COUNT(*) as trip_count, SUM(CASE WHEN booking_items.status = 'completed' THEN COALESCE(booking_items.total_price, 0) ELSE 0 END) as completed_revenue")
            ->groupBy('booking_items.vehicle_id', 'currency')
            ->orderBy('booking_items.vehicle_id')->orderBy('currency')
            ->get()
            ->groupBy(fn ($row) => (string) $row->vehicle_id);

        return Vehicle::with('group')
            ->when(!empty($filters['vehicle_id']), fn ($query) => $query->whereKey($filters['vehicle_id']))
            ->orderBy('title')
            ->limit(500)
            ->get()
            ->flatMap(function ($vehicle) use ($metrics) {
                $rows = $metrics->get((string) $vehicle->id, collect());
                if ($rows->isEmpty()) $rows = collect([(object) ['currency' => '', 'trip_count' => 0, 'completed_revenue' => 0]]);
                return $rows->map(fn ($metric) => [
                'id' => (string) $vehicle->id,
                'registration_number' => $vehicle->license_plate,
                'vehicle_info' => $vehicle->title ?? $vehicle->license_plate,
                'category' => $vehicle->group?->name ?? '',
                'total_bookings' => (int) $metric->trip_count,
                'total_revenue' => (float) $metric->completed_revenue,
                'currency' => (string) $metric->currency,
                'status' => $vehicle->status ?? $vehicle->availability_status,
                ]);
            })->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────
    // Export
    // ─────────────────────────────────────────────────────────────────

    public function exportReport(Request $request, ?string $type = null): Response|JsonResponse
    {
        $request->validate([
            'format' => 'sometimes|in:csv,pdf',
            'type' => 'sometimes|string|in:bookings,customers,vehicles,financial',
        ]);

        $type = $type ?? $request->get('type', 'bookings');
        $format  = $request->get('format', 'csv');
        $filters = $this->parseFilters($request);

        return match ($type) {
            'bookings'  => $this->exportBookingReport($filters, $format),
            'customers' => $this->exportCustomerReport($filters, $format),
            'vehicles'  => $this->exportVehicleReport($filters, $format),
            'financial' => $this->exportFinancialReport($filters, $format, $request->get('period', 'monthly')),
            default     => response()->json(['error' => 'Invalid report type'], 400),
        };
    }

    public function generatedReports(Request $request): JsonResponse
    {
        $reports = BookingGeneratedReport::query()
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => collect($reports->items())
                ->map(fn (BookingGeneratedReport $report) => $this->generatedReportPayload($report))
                ->values(),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    public function downloadGeneratedReport(BookingGeneratedReport $report): Response
    {
        $filters = $this->filtersFromGeneratedReport($report);

        return match ($report->report_type) {
            'financial' => $this->exportFinancialReport($filters, $report->format, $filters['period'] ?? 'monthly'),
            'customer' => $this->exportCustomerReport($filters, $report->format),
            'vehicle_performance' => $this->exportVehicleReport($filters, $report->format),
            default => $this->exportBookingReport($filters, $report->format),
        };
    }

    public function deleteGeneratedReport(BookingGeneratedReport $report): JsonResponse
    {
        $report->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Report deleted',
            'report_id' => $report->id,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════
    // Private helpers — shared
    // ═════════════════════════════════════════════════════════════════

    private function parseFilters(Request $request): array
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $today = Carbon::today()->toDateString();

        return array_filter([
            'date_from'      => $request->get('date_from') ?: Carbon::today()->startOfMonth()->toDateString(),
            'date_to'        => $request->get('date_to') ?: $today,
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

    private function generatedReportPayload(BookingGeneratedReport $report): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'type' => $report->report_type,
            'created_at' => $report->created_at,
            'status' => $report->status,
            'download_url' => url("/api/reports/download/{$report->id}"),
            'size' => $report->size,
            'row_count' => $report->row_count,
            'format' => $report->format,
        ];
    }

    private function filtersFromGeneratedReport(BookingGeneratedReport $report): array
    {
        $saved = $report->filters ?? [];
        $nestedFilters = $saved['filters'] ?? [];

        return array_filter([
            'date_from' => $saved['date_from'] ?? null,
            'date_to' => $saved['date_to'] ?? null,
            'period' => $saved['period'] ?? 'monthly',
            'booking_status' => $nestedFilters['status'][0] ?? $nestedFilters['status'] ?? null,
            'customer_id' => $nestedFilters['customer_id'] ?? null,
            'vehicle_id' => $nestedFilters['vehicle_id'] ?? null,
            'agent_id' => $nestedFilters['agent_id'] ?? null,
            'service_type_id' => $nestedFilters['service_type_id'] ?? $nestedFilters['service_type'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
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
            'hour'    => "TO_CHAR(created_at, 'YYYY-MM-DD HH24:00')",
            'week'    => "TO_CHAR(created_at, 'IYYY-\"W\"IW')",
            'month',
            'monthly' => "TO_CHAR(created_at, 'YYYY-MM')",
            'year',
            'yearly'  => "TO_CHAR(created_at, 'YYYY')",
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
            ->selectRaw("DATE(created_at) as date, COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency, SUM(COALESCE(total_actual,0)) as revenue")
            ->groupBy('date', 'currency')
            ->orderBy('date')->orderBy('currency')
            ->get()
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────
    // Booking analytics helpers
    // ─────────────────────────────────────────────────────────────────

    private function getAverageBookingDuration($query): float
    {
        $avg = (clone $query)
            ->join('booking_items', 'bookings.id', '=', 'booking_items.booking_id')
            ->whereNotNull('booking_items.from_date')
            ->whereNotNull('booking_items.to_date')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (booking_items.to_date - booking_items.from_date)) / 3600) as avg_hours')
            ->value('avg_hours');

        return round((float) $avg, 2);
    }

    private function getPeakHours($query): array
    {
        return (clone $query)
            ->selectRaw('EXTRACT(HOUR FROM created_at) as hour, COUNT(*) as bookings')
            ->groupBy('hour')
            ->orderByDesc('bookings')
            ->limit(5)
            ->get()
            ->toArray();
    }

    private function getServiceTypeDistribution($query): array
    {
        $rows = (clone $query)
            ->join('booking_items', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('service_types', 'booking_items.service_type_id', '=', 'service_types.id')
            ->select('service_types.name as service_type')
            ->selectRaw('COUNT(DISTINCT bookings.id) as bookings')
            ->groupBy('service_types.id', 'service_types.name')
            ->orderByDesc('bookings')
            ->get();

        $total = $rows->sum('bookings');

        return $rows->map(function ($row) use ($total) {
            $bookings = (int) $row->bookings;

            return [
                'service_type' => $row->service_type,
                'bookings' => $bookings,
                'percentage' => $total > 0 ? round(($bookings / $total) * 100, 2) : 0,
            ];
        })->values()->all();
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
            ->selectRaw("COALESCE(NULLIF(UPPER(bookings.currency), ''), 'LKR') as currency")
            ->selectRaw('SUM(COALESCE(booking_items.total_price,0)) as revenue, COUNT(DISTINCT bookings.id) as bookings')
            ->groupBy('service_types.id', 'service_types.name', 'currency')
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
            ->selectRaw("COALESCE(NULLIF(UPPER(bookings.currency), ''), 'LKR') as currency")
            ->selectRaw('SUM(COALESCE(bookings.total_actual,0)) as revenue, COUNT(*) as bookings')
            ->groupBy('agents.id', 'users.first_name', 'users.last_name', 'currency')
            ->orderByDesc('revenue')
            ->get()
            ->toArray();
    }

    private function getMonthlyGrowth($query): array
    {
        $rows = (clone $query)
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency, SUM(COALESCE(total_actual,0)) as revenue")
            ->groupBy('month', 'currency')
            ->orderBy('month')->orderBy('currency')
            ->get();

        $result = [];
        $previousRevenue = [];
        foreach ($rows as $row) {
            $prev = $previousRevenue[$row->currency] ?? 0;
            $result[] = [
                'month'       => $row->month,
                'currency'    => $row->currency,
                'revenue'     => (float) $row->revenue,
                'growth_rate' => $prev > 0 ? round((($row->revenue - $prev) / $prev) * 100, 2) : 0,
            ];
            $previousRevenue[$row->currency] = (float) $row->revenue;
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
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, COUNT(*) as new_customers")
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
        $byCountry = Country::query()
            ->select('id', 'name')
            ->whereHas('customers')
            ->withCount('customers')
            ->orderByDesc('customers_count')
            ->limit(10)
            ->get()
            ->map(fn (Country $country) => [
                'country' => $country->name,
                'count' => $country->customers_count,
            ])
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
            'total_points_issued'    => (clone $query)->sum('total_points'),
            'total_points_redeemed'  => (clone $query)->sum('redeemed_points'),
            'active_point_balances'  => (clone $query)->sum('available_points'),
            'by_tier'                => (clone $query)
                ->selectRaw('current_tier, COUNT(*) as customers, SUM(available_points) as total_points')
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
        if (!empty($filters['date_to'])) {
            $completedBookings->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
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
        $usedVehicleIds = DB::table('booking_items')
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->where('bookings.status', 'completed')
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('bookings.created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->where('bookings.created_at', '<=', $filters['date_to'] . ' 23:59:59'))
            ->whereNotNull('booking_items.vehicle_id')
            ->distinct()
            ->pluck('booking_items.vehicle_id');

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
            ->join('booking_items', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('vehicles', 'booking_items.vehicle_id', '=', 'vehicles.id')
            ->join('vehicle_groups', 'vehicles.vehicle_group_id', '=', 'vehicle_groups.id')
            ->select('vehicle_groups.name as category')
            ->selectRaw('COUNT(DISTINCT bookings.id) as bookings')
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('bookings.created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']),   fn ($q) => $q->where('bookings.created_at', '<=', $filters['date_to'] . ' 23:59:59'))
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
            ->when(!empty($filters['date_to']),   fn ($q) => $q->where('created_at', '<=', $filters['date_to'] . ' 23:59:59'))
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
            ->select('booking_number', 'status', 'from_date', 'to_date', 'total_actual', 'currency', 'created_at');

        $this->applyFilters($query, $filters);
        $rows = $query->orderByDesc('created_at')->get();

        $headers = ['Booking #', 'Status', 'From', 'To', 'Total', 'Currency', 'Created'];
        $data    = $rows->map(fn ($b) => [
            $b->booking_number,
            $b->status,
            $b->from_date,
            $b->to_date,
            $b->total_actual,
            strtoupper((string) ($b->currency ?: 'LKR')),
            $b->created_at,
        ])->toArray();

        return $this->csvResponse('bookings_report.csv', $headers, $data);
    }

    private function exportCustomerReport(array $filters, string $format): Response
    {
        $rows = collect($this->customerReportRows($filters));
        $headers = ['Customer', 'Email', 'Phone', 'Lifetime bookings', 'Lifetime completed spend', 'Currency', 'Last booking', 'Registered'];
        $data = $rows->flatMap(function ($row) {
            $spend = collect($row['spend_by_currency']);
            if ($spend->isEmpty()) $spend = collect([['currency' => '', 'total_spent' => 0]]);
            return $spend->map(fn ($total) => [
                $row['name'], $row['email'], $row['phone'], $row['total_bookings'],
                $total['total_spent'], $total['currency'], $row['last_booking_date'], $row['registration_date'],
            ]);
        })->all();

        return $this->csvResponse('customers_report.csv', $headers, $data);
    }

    private function exportVehicleReport(array $filters, string $format): Response
    {
        $rows = collect($this->vehicleReportRows($filters));
        $headers = ['Vehicle', 'Registration', 'Category', 'Trips in range', 'Completed revenue', 'Currency', 'Status'];
        $data = $rows->map(fn ($vehicle) => [
            $vehicle['vehicle_info'], $vehicle['registration_number'], $vehicle['category'],
            $vehicle['total_bookings'], $vehicle['total_revenue'], $vehicle['currency'], $vehicle['status'],
        ])->all();

        return $this->csvResponse('vehicles_report.csv', $headers, $data);
    }

    private function exportFinancialReport(array $filters, string $format, string $period): Response
    {
        $fmt = $this->getDateFormat($period);
        $query = Booking::where('status', 'completed')
            ->selectRaw("$fmt as period,
                COALESCE(NULLIF(UPPER(currency), ''), 'LKR') as currency,
                COUNT(*) as bookings,
                SUM(COALESCE(total_actual,0)) as revenue");

        $this->applyFilters($query, $filters);
        $rows = $query->groupBy('period', 'currency')->orderBy('period')->orderBy('currency')->get();

        $headers = ['Period', 'Currency', 'Completed bookings', 'Completed revenue'];
        $data    = $rows->map(fn ($r) => [
            $r->period, $r->currency, $r->bookings, $r->revenue,
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

    public function getPerformanceMetrics(Request $request): JsonResponse
    {
        $period = $request->input('period', 30);
        $from   = now()->subDays($period);

        $totalBookings    = Booking::where('created_at', '>=', $from)->count();
        $completedBookings= Booking::where('created_at', '>=', $from)->where('status', 'completed')->count();
        $cancelledBookings= Booking::where('created_at', '>=', $from)->where('status', 'cancelled')->count();
        $completionRate   = $totalBookings > 0 ? round($completedBookings / $totalBookings * 100, 1) : 0;
        $cancellationRate = $totalBookings > 0 ? round($cancelledBookings / $totalBookings * 100, 1) : 0;

        return response()->json([
            'status' => 'success',
            'data'   => [
                'period_days'       => $period,
                'total_bookings'    => $totalBookings,
                'completed'         => $completedBookings,
                'cancelled'         => $cancelledBookings,
                'completion_rate'   => $completionRate,
                'cancellation_rate' => $cancellationRate,
            ],
        ]);
    }
}
