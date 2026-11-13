<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\CorporateReportFiltersRequest;
use App\Models\Corporate\CorporateEmployee;
use App\Services\CorporateBookingService;
use App\Services\CorporatePortalPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $filters['can_view_payments'] = CorporatePortalPermission::allows($request, 'view_payments');

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

    public function employeeOptions(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($filters['q'] ?? ''));
        $employees = CorporateEmployee::query()
            ->where('corporate_id', $request->corporate_id)
            ->with('user:id,first_name,last_name,email')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('employee_code', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($userQuery) => $userQuery
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            }))
            ->orderBy('created_at')
            ->limit(25)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $employees->map(fn (CorporateEmployee $employee) => [
                'id' => $employee->user_id,
                'name' => trim(($employee->user?->first_name ?? '').' '.($employee->user?->last_name ?? '')),
                'code' => $employee->employee_code,
                'is_active' => $employee->is_active,
            ])->values(),
        ]);
    }

    public function summaryStats(CorporateReportFiltersRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $filters['can_view_payments'] = CorporatePortalPermission::allows($request, 'view_payments');

        $stats = $this->bookingService->getBookingSummaryStats(
            $request->corporate_id,
            $filters
        );

        if (! $filters['can_view_payments']) {
            unset($stats['total_cost'], $stats['estimated_value'], $stats['finalized_value'], $stats['currency_totals']);
        }

        return response()->json([
            'status' => 'success',
            'data' => $stats,
        ]);
    }

    public function exportCsv(CorporateReportFiltersRequest $request): StreamedResponse
    {
        $filters = $request->safe()->except(['page', 'per_page']);
        $filters['can_view_payments'] = CorporatePortalPermission::allows($request, 'view_payments');

        $filePath = $this->bookingService->exportBookings(
            $request->corporate_id,
            $filters
        );

        return Storage::download($filePath, basename($filePath), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

}
