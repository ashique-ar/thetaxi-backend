<?php

namespace App\Console\Commands;

use App\Models\Booking\BookingItem;
use App\Services\BookingLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryPendingFinalPricing extends Command
{
    protected $signature = 'bookings:retry-final-pricing {--limit=200 : Maximum booking items to retry in one run}';

    protected $description = 'Retry final pricing for completed booking items still flagged pending_manual_pricing, and invoice them once resolved';

    public function handle(BookingLifecycleService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $items = BookingItem::query()
            ->where('metadata->final_pricing_audit->status', 'pending_manual_pricing')
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id']);

        if ($items->isEmpty()) {
            $this->info('No booking items pending final-pricing review.');
            return self::SUCCESS;
        }

        $this->info("Retrying final pricing for {$items->count()} booking item(s)...");

        $resolved = 0;
        $stillPending = 0;
        $errors = 0;

        foreach ($items as $item) {
            try {
                $result = $service->retryPendingFinalPricing((string) $item->id);
                if ($result['resolved']) {
                    $resolved++;
                } else {
                    $stillPending++;
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::error('bookings:retry-final-pricing failed for booking item', [
                    'booking_item_id' => (string) $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Retried', $items->count()],
                ['Resolved', $resolved],
                ['Still pending', $stillPending],
                ['Errors', $errors],
            ]
        );

        if ($stillPending > 0) {
            Log::warning('bookings:retry-final-pricing: items remain pending manual pricing review', [
                'still_pending_count' => $stillPending,
            ]);
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
