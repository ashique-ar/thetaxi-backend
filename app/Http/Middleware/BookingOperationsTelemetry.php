<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BookingOperationsTelemetry
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->logFailure($request, $startedAt, 500, $exception::class);
            throw $exception;
        }

        $durationMs = $this->durationMs($startedAt);
        $targetMs = $request->isMethodSafe()
            ? (int) config('booking_observability.performance.read_target_ms', 2000)
            : (int) config('booking_observability.performance.action_target_ms', 3000);

        $response->headers->set('Server-Timing', sprintf('booking;dur=%.1f', $durationMs));

        if ($response->getStatusCode() >= 500) {
            $this->logFailure($request, $startedAt, $response->getStatusCode());
        } elseif ($durationMs > $targetMs) {
            Log::warning('booking_operations_api_slow', $this->context(
                $request,
                $durationMs,
                $response->getStatusCode(),
                ['target_ms' => $targetMs]
            ));
        }

        return $response;
    }

    private function logFailure(Request $request, int $startedAt, int $status, ?string $exceptionClass = null): void
    {
        Log::error('booking_operations_api_failed', $this->context(
            $request,
            $this->durationMs($startedAt),
            $status,
            array_filter(['exception_class' => $exceptionClass])
        ));
    }

    private function context(Request $request, float $durationMs, int $status, array $extra = []): array
    {
        return array_merge([
            'route' => $request->route()?->uri(),
            'method' => $request->method(),
            'status' => $status,
            'duration_ms' => round($durationMs, 1),
            'booking_id' => $this->routeIdentifier($request->route('booking'))
                ?? $request->route('bookingId')
                ?? $request->input('booking_id'),
            'booking_item_id' => $request->query('booking_item_id')
                ?? $request->input('booking_item_id')
                ?? $this->routeIdentifier($request->route('bookingItem')),
            'actor_id' => $request->user()?->getAuthIdentifier(),
        ], $extra);
    }

    private function durationMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    private function routeIdentifier(mixed $value): mixed
    {
        return is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : $value;
    }
}
