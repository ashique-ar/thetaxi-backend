<?php

namespace App\Console\Commands;

use App\Services\Sales\SalesCollectionCompanyIntegrity;
use Illuminate\Console\Command;

class AuditSalesCollectionCompanyOwnership extends Command
{
    protected $signature = 'sales:audit-collection-companies {--booking-number= : Limit the report to one readable booking number}';

    protected $description = 'Report collection ledger company mismatches without changing records';

    public function handle(SalesCollectionCompanyIntegrity $integrity): int
    {
        $bookingNumber = trim((string) $this->option('booking-number'));
        $items = $integrity->mismatchReport($bookingNumber !== '' ? $bookingNumber : null);

        $this->line(json_encode([
            'generated_at' => now()->utc()->toIso8601String(),
            'read_only' => true,
            'repair_performed' => false,
            'scope' => ['booking_number' => $bookingNumber !== '' ? $bookingNumber : null],
            'mismatch_count' => count($items),
            'summary' => $integrity->mismatchSummary($items),
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
