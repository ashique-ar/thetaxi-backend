<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Services\Sms\SmsAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgentOperationalController extends Controller
{
    public function __construct(private readonly SmsAutomationService $smsAutomationService) {}

    public function profile(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent')->load('user');
        $agentApi = $request->attributes->get('agent_api');

        return response()->json([
            'status' => 'success',
            'data' => [
                'agent' => $agent,
                'api_key' => [
                    'id' => $agentApi->id,
                    'title' => $agentApi->title,
                    'access_level' => $agentApi->access_level,
                    'rate_limit' => $agentApi->rate_limit,
                    'total_requests' => $agentApi->total_requests,
                    'last_used_at' => $agentApi->last_used_at,
                ],
            ],
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $query = Booking::with('customer.user')
            ->where('agent_id', $agent->id)
            ->latest();

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')));
        $query->when($request->filled('date_from'), fn ($q) => $q->whereDate('booking_date', '>=', $request->input('date_from')));
        $query->when($request->filled('date_to'), fn ($q) => $q->whereDate('booking_date', '<=', $request->input('date_to')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function showBooking(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeAgentBooking($request, $booking);

        return response()->json([
            'status' => 'success',
            'data' => $booking->load(['customer.user', 'bookingItems']),
        ]);
    }

    public function updateBookingStatus(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeAgentBooking($request, $booking);

        $data = $request->validate([
            'status' => 'required|string|max:50',
        ]);

        $previousStatus = (string) $booking->status;
        $booking->update([
            'status' => $data['status'],
        ]);

        if ($data['status'] === 'confirmed' && $previousStatus !== 'confirmed') {
            $this->smsAutomationService->queueBookingConfirmation($booking->fresh());
        }

        return response()->json([
            'status' => 'success',
            'data' => $booking->fresh(),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $query = Customer::with('user')
            ->whereHas('bookings', fn ($bookingQuery) => $bookingQuery->where('agent_id', $agent->id))
            ->latest();

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function usage(Request $request): JsonResponse
    {
        $agentApi = $request->attributes->get('agent_api')->load('sessions');

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_requests' => $agentApi->total_requests,
                'last_used_at' => $agentApi->last_used_at,
                'recent_requests' => $agentApi->sessions()
                    ->latest('last_access')
                    ->limit(25)
                    ->get(),
            ],
        ]);
    }

    private function authorizeAgentBooking(Request $request, Booking $booking): void
    {
        $agent = $request->attributes->get('agent');

        abort_unless($booking->agent_id === $agent->id, Response::HTTP_NOT_FOUND);
    }
}
