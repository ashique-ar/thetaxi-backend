<?php

namespace App\Console\Commands;

use App\Services\Sales\SalesCommissionNotificationService;
use Illuminate\Console\Command;

class ProcessSalesCommissionNotifications extends Command
{
    protected $signature = 'sales:process-commission-notifications {--commit} {--limit=100}';

    protected $description = 'Preview or deliver privacy-minimized in-app Sales commission hold notifications';

    public function handle(SalesCommissionNotificationService $notifications): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $due = $notifications->countDue();
        if (! $this->option('commit')) {
            $this->info("Would process up to {$limit} of {$due} queued commission notification(s). No writes performed.");
            return self::SUCCESS;
        }
        if (! config('sales.features.commission_notifications', false)) {
            $this->warn('Commission notifications are disabled; no deliveries were attempted.');
            return self::SUCCESS;
        }

        $refreshed = $notifications->refreshBlocked($limit);
        $delivered = $notifications->deliverDue($limit);
        $this->info("Refreshed {$refreshed} blocked notification(s); delivered {$delivered} in-app notification(s).");

        return self::SUCCESS;
    }
}
