<?php

namespace App\Console\Commands;

use App\Enums\TripPhase;
use App\Models\DriverAssignment;
use App\Services\BookingLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileCompletedDriverHires extends Command
{
    protected $signature = 'bookings:reconcile-driver-completions {--limit=200 : Maximum completed assignments to inspect}';

    protected $description = 'Complete booking items whose drivers have already completed their assignments';

    public function handle(BookingLifecycleService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $assignments = DriverAssignment::query()
            ->where('trip_phase', TripPhase::COMPLETED->value)
            ->whereNotNull('trip_completed_at')
            ->whereHas('booking.bookingItems', function ($query) {
                $query->whereNull('completed_at')
                    ->whereNotIn('status', ['cancelled', 'rejected']);
            })
            ->orderBy('trip_completed_at')
            ->limit($limit)
            ->get(['id']);

        $completed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($assignments as $assignment) {
            try {
                $service->reconcileCompletedDriverAssignment((string) $assignment->id)
                    ? $completed++
                    : $skipped++;
            } catch (\Throwable $exception) {
                $errors++;
                Log::error('Driver completion reconciliation failed', [
                    'assignment_id' => (string) $assignment->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->table(['Metric', 'Count'], [
            ['Inspected', $assignments->count()],
            ['Booking items completed', $completed],
            ['Skipped', $skipped],
            ['Errors', $errors],
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
