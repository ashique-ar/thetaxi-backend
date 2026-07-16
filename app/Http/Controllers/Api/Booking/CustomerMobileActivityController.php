<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Services\CustomerMobileActivityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerMobileActivityController extends Controller
{
    public function __construct(
        private readonly CustomerMobileActivityService $activityService
    ) {
    }

    /**
     * Capture cumulative trip telemetry from the authenticated customer's app.
     * Only operational metrics are accepted; rates and charges are deliberately
     * excluded and remain owned by server-side pricing definitions.
     */
    public function store(Request $request, string $bookingId, string $bookingItemId): JsonResponse
    {
        $validated = $request->validate([
            'client_event_id' => ['required', 'uuid'],
            'event_type' => ['required', 'string', 'in:trip_started,telemetry,trip_completed'],
            'occurred_at' => ['required', 'date'],
            'actual_start_time' => ['nullable', 'date'],
            'actual_return_time' => ['nullable', 'date'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'waiting_minutes' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $user = $request->user('api') ?: $request->user();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Scope both lookups through customer ownership to avoid exposing the
        // existence of another customer's booking or item.
        $booking = Booking::query()
            ->with('customer')
            ->whereKey($bookingId)
            ->whereHas('customer', fn ($query) => $query->where('user_id', $user->id))
            ->first();
        if (!$booking) {
            throw (new ModelNotFoundException())->setModel(Booking::class, [$bookingId]);
        }

        $bookingItem = $booking->bookingItems()
            ->whereKey($bookingItemId)
            ->first();
        if (!$bookingItem) {
            throw (new ModelNotFoundException())->setModel(BookingItem::class, [$bookingItemId]);
        }

        try {
            $result = $this->activityService->record(
                $booking,
                $bookingItem,
                $user,
                $validated,
                [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]
            );
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'activity' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['replayed']
                ? 'Activity was already recorded.'
                : 'Activity recorded successfully.',
            'data' => [
                'activity_id' => $result['activity']->id,
                'client_event_id' => $result['activity']->client_event_id,
                'replayed' => $result['replayed'],
                'summary' => $result['summary'],
            ],
        ], $result['replayed'] ? 200 : 201);
    }
}
