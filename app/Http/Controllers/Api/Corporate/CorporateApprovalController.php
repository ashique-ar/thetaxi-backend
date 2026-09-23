<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\ProcessApprovalRequest;
use App\Services\CorporateApprovalService;
use App\Services\CorporateBookingService;
use App\Services\CorporatePortalPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateApprovalController extends Controller
{
    protected CorporateApprovalService $approvalService;
    protected CorporateBookingService $bookingService;

    public function __construct(
        CorporateApprovalService $approvalService,
        CorporateBookingService $bookingService,
    ) {
        $this->approvalService = $approvalService;
        $this->bookingService = $bookingService;
        $this->middleware('permission:approve_bookings');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['date_from', 'date_to', 'page', 'per_page']);
        $filters['can_view_payments'] = CorporatePortalPermission::allows($request, 'view_payments');

        $queue = $this->bookingService->getApprovalQueue(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => $queue->items(),
            'meta'   => [
                'current_page' => $queue->currentPage(),
                'per_page' => $queue->perPage(),
                'total' => $queue->total(),
                'last_page' => $queue->lastPage(),
            ],
        ]);
    }

    public function approve(Request $request, string $bookingId): JsonResponse
    {
        $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $booking = $this->approvalService->approveBooking(
                $bookingId,
                $request->user()->id,
                $request->corporate_id,
                $request->comments
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Booking approved successfully',
            'data'    => ['booking' => $booking],
        ]);
    }

    public function reject(Request $request, string $bookingId): JsonResponse
    {
        $request->validate([
            'reason'   => ['required', 'string', 'max:1000'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $booking = $this->approvalService->rejectBooking(
                $bookingId,
                $request->user()->id,
                $request->corporate_id,
                $request->reason,
                $request->comments
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Booking rejected successfully',
            'data'    => ['booking' => $booking],
        ]);
    }
}
