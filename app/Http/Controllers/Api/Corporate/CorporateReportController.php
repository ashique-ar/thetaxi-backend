<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Services\CorporateBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateReportController extends Controller
{
    protected CorporateBookingService $bookingService;

    public function __construct(CorporateBookingService $bookingService)
    {
        $this->bookingService = $bookingService;
        $this->middleware('permission:view_reports');
    }

    public function bookingHistory(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'department_id', 'division_id', 'date_from', 'date_to']);

        $bookings = $this->bookingService->getBookingsForCorporate(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => ['bookings' => $bookings],
        ]);
    }

    public function summaryStats(Request $request): JsonResponse
    {
        $filters = $request->only(['date_from', 'date_to']);

        $stats = $this->bookingService->getBookingSummaryStats(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => ['stats' => $stats],
        ]);
    }

    public function exportCsv(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'department_id', 'division_id', 'date_from', 'date_to']);

        $filePath = $this->bookingService->exportBookings(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => ['file_path' => $filePath],
        ]);
    }
}
