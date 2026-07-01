<?php

namespace App\Http\Controllers\Api\Booking\Traits;

use App\Models\Booking\BookingGeneratedReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

trait BookingAnalyticsTrait
{
    public function getDashboardStats(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'nullable|string|in:today,week,month,quarter,year,custom',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'filters' => 'nullable|array'
        ]);

        try {
            $stats = $this->bookingFlowService->getDashboardStats($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $stats,
                'message' => 'Dashboard statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting dashboard stats: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dashboard statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getBookingTrends(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'required|string|in:daily,weekly,monthly,quarterly',
            'metric' => 'required|string|in:count,revenue,average_value,completion_rate',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'group_by' => 'nullable|string|in:service_type,vehicle_group,customer_type,status'
        ]);

        try {
            $trends = $this->bookingFlowService->getBookingTrends($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $trends,
                'message' => 'Booking trends retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking trends: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking trends',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getRevenueAnalytics(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'required|string|in:daily,weekly,monthly,quarterly,yearly',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'breakdown' => 'nullable|string|in:service_type,vehicle_group,customer_segment',
            'include_projections' => 'nullable|boolean'
        ]);

        try {
            $analytics = $this->bookingFlowService->getRevenueAnalytics($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $analytics,
                'message' => 'Revenue analytics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting revenue analytics: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve revenue analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUtilizationReports(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|string|in:vehicle,driver,service_type,time_based',
            'period' => 'required|string|in:daily,weekly,monthly',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'include_idle_time' => 'nullable|boolean'
        ]);

        try {
            $reports = $this->bookingFlowService->getUtilizationReports($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $reports,
                'message' => 'Utilization reports retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting utilization reports: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve utilization reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCustomerAnalytics(Request $request): JsonResponse
    {
        $request->validate([
            'metric' => 'required|string|in:acquisition,retention,lifetime_value,booking_frequency',
            'period' => 'required|string|in:monthly,quarterly,yearly',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'customer_segment' => 'nullable|string|in:corporate,individual,vip'
        ]);

        try {
            $analytics = $this->bookingFlowService->getCustomerAnalytics($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $analytics,
                'message' => 'Customer analytics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting customer analytics: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve customer analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generateReport(Request $request): JsonResponse
    {
        $request->merge([
            'report_type' => $request->input('report_type') ?: $this->reportTypeFromTemplate((string) $request->input('template_id')),
            'format' => $this->normaliseReportFormat((string) $request->input('format', 'csv')),
        ]);

        $request->validate([
            'report_type' => 'required|string|in:financial,operational,customer,vehicle_performance,driver_performance',
            'template_id' => 'nullable|string|max:100',
            'report_name' => 'nullable|string|max:255',
            'format' => 'nullable|string|in:pdf,excel,csv',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'filters' => 'nullable|array',
            'include_charts' => 'nullable|boolean',
            'email_to' => 'nullable|email'
        ]);

        try {
            $report = $this->bookingFlowService->generateReport($request->all());
            $record = BookingGeneratedReport::create([
                'name' => $request->input('report_name') ?: ucfirst(str_replace('_', ' ', $request->input('report_type'))) . ' Report',
                'report_type' => $request->input('report_type'),
                'template_id' => $request->input('template_id'),
                'format' => $request->input('format', 'csv'),
                'status' => 'ready',
                'filters' => $request->only(['date_from', 'date_to', 'filters', 'custom_fields', 'include_charts', 'include_summary']),
                'metadata' => $report['metadata'] ?? [],
                'row_count' => $this->reportRowCount($report['report_data'] ?? []),
                'size' => $this->estimateReportSize($report['report_data'] ?? []),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $record->id,
                    'name' => $record->name,
                    'type' => $record->report_type,
                    'created_at' => $record->created_at,
                    'status' => $record->status,
                    'download_url' => url("/api/reports/download/{$record->id}"),
                    'size' => $record->size,
                    'report_data' => $report['report_data'] ?? [],
                ],
                'message' => 'Report generated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating report: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function reportTypeFromTemplate(string $templateId): string
    {
        return match ($templateId) {
            'financial_report' => 'financial',
            'customer_analysis' => 'customer',
            'vehicle_utilization' => 'vehicle_performance',
            'driver_performance' => 'driver_performance',
            default => 'operational',
        };
    }

    private function normaliseReportFormat(string $format): string
    {
        return $format === 'excel' ? 'csv' : ($format ?: 'csv');
    }

    private function reportRowCount(array $reportData): int
    {
        foreach (['bookings', 'customers', 'vehicles', 'drivers', 'rows'] as $key) {
            if (isset($reportData[$key]) && is_countable($reportData[$key])) {
                return count($reportData[$key]);
            }
        }

        return count($reportData);
    }

    private function estimateReportSize(array $reportData): string
    {
        $bytes = strlen(json_encode($reportData) ?: '');

        if ($bytes < 1024) {
            return max($bytes, 1) . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    public function exportBookings(Request $request): JsonResponse
    {
        $request->validate([
            'format' => 'required|string|in:excel,csv,pdf',
            'filters' => 'nullable|array',
            'columns' => 'nullable|array',
            'include_relations' => 'nullable|boolean'
        ]);

        try {
            $export = $this->bookingFlowService->exportBookings($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $export,
                'message' => 'Bookings exported successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error exporting bookings: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
