<?php

namespace App\Http\Middleware;

use App\Models\Booking\Booking;
use App\Services\BookingOwnership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScopeOwnBookings
{
    public function __construct(private readonly BookingOwnership $ownership)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || (! $this->ownership->isScopedRole($user)
            && (! $user->can('bookings.view-assigned') || $user->can('bookings.view')))) {
            return $next($request);
        }

        $route = $request->route();
        $ids = collect();
        foreach (['bookingId', 'draftId', 'booking_id', 'booking'] as $key) {
            $value = $route?->parameter($key);
            if ($value instanceof Booking) {
                $ids->push((string) $value->id);
            } elseif (is_string($value) || is_numeric($value)) {
                $ids->push((string) $value);
            }
        }
        foreach (['booking_id', 'bookingId', 'booking_ids', 'bookingIds'] as $key) {
            $values = $request->input($key, []);
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_string($value) || is_numeric($value)) {
                    $ids->push((string) $value);
                }
            }
        }
        $ids = $ids->unique()->values();

        if (str_contains((string) $route?->uri(), 'booking-flow/edit/{id}')) {
            $ids->push((string) $route->parameter('id'));
        }

        abort_if($ids->count() > 100, 422, 'Too many booking references.');

        if ($ids->isNotEmpty()) {
            foreach (Booking::query()->whereIn('id', $ids)->get() as $booking) {
                abort_unless($this->ownership->canAccess($user, $booking), 404);
            }
        }

        return $next($request);
    }
}
