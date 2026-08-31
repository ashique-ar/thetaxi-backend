<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\CorporateReportFiltersRequest;
use App\Services\CorporateBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorporateReportController extends Controller
{
    protected CorporateBookingService $bookingService;

    public function __construct(CorporateBookingService $bookingService)
    {
        $this->bookingService = $bookingService;
        $this->middleware('permission:view_reports');
    }

    public function bookingHistory(CorporateReportFiltersRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $bookings = $this->bookingService->getBookingsForCorporate(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data' => $bookings->items(),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
            ],
        ]);
    }

    public function summaryStats(CorporateReportFiltersRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $stats = $this->bookingService->getBookingSummaryStats(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data' => $stats,
        ]);
    }

    public function exportCsv(CorporateReportFiltersRequest $request): StreamedResponse
    {
        $filters = $request->safe()->except(['page', 'per_page']);

        $filePath = $this->bookingService->exportBookings(
            $request->corporate_id,
            $filters
        );

        return Storage::download($filePath, basename($filePath), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

}
