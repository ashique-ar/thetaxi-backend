<?php

namespace App\Http\Controllers\Api\Booking\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait BookingApprovalTrait
{
    public function getBookingApprovalDetails(Request $request, string $bookingId): JsonResponse
    {
        try {
            $details = $this->bookingFlowService->getApprovalDetails($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $details,
                'message' => 'Approval details retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting approval details: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get approval details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function processBookingApproval(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|uuid|exists:bookings,id',
            'action' => 'required|in:approve,reject',
            'note' => 'nullable|string|max:1000',
            'comments' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $this->bookingFlowService->processApproval(
                $request->booking_id,
                $request->action,
                $request->input('note') ?? $request->input('comments'),
                Auth::id()
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => ucfirst($request->action) . 'd successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing approval: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getApprovalStatus(string $bookingId): JsonResponse
    {
        try {
            $status = $this->bookingFlowService->getApprovalStatus($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $status,
                'message' => 'Approval status retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting approval status: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get approval status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function requestManagerApproval(Request $request, string $bookingId): JsonResponse
    {
        $request->validate([
            'manager_id' => 'nullable|uuid|exists:users,id',
            'priority' => 'required|in:normal,high,urgent',
            'override_reasons' => 'required|array',
            'justification' => 'required|string|max:1000',
        ]);

        try {
            $approval = $this->bookingFlowService->requestManagerApproval($bookingId, $request->all());

            return response()->json([
                'status' => 'success',
                'data' => $approval,
                'message' => 'Manager approval requested successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error requesting manager approval: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to request manager approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
