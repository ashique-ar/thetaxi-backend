<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BookingRouteUsageController extends Controller
{
    private const ROUTE_KEYS = [
        'overview', 'dashboard', 'list', 'create', 'create_single_view', 'edit',
        'calendar', 'operations', 'operations_ongoing_hires',
        'operations_return_inspection', 'operations_post_return_availability',
        'settlements', 'assignment_redirect', 'workspace', 'report', 'vip_types',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'route_key' => ['required', Rule::in(self::ROUTE_KEYS)],
            'resolved_route_key' => ['nullable', Rule::in(self::ROUTE_KEYS)],
            'session_id' => ['required', 'uuid'],
        ]);

        if ($this->collectionWindowIsActive()) {
            Log::info('booking_management_route_viewed', [
                'route_key' => $validated['route_key'],
                'resolved_route_key' => $validated['resolved_route_key'] ?? null,
                'session_id' => $validated['session_id'],
                'actor_id' => $request->user()?->getAuthIdentifier(),
            ]);
        }

        return response()->json(['status' => 'accepted'], 202);
    }

    private function collectionWindowIsActive(): bool
    {
        if (!config('booking_observability.route_usage.enabled', false)) {
            return false;
        }

        $startsAt = config('booking_observability.route_usage.starts_at');
        $endsAt = config('booking_observability.route_usage.ends_at');
        if (!$startsAt || !$endsAt) {
            return false;
        }

        try {
            return now()->betweenIncluded(now()->parse($startsAt), now()->parse($endsAt));
        } catch (\Throwable) {
            return false;
        }
    }
}
