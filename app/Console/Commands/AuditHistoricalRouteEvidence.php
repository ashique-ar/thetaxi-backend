<?php

namespace App\Console\Commands;

use App\Models\DriverAssignment;
use App\Services\Driver\HistoricalRouteEvidenceAuditService;
use Illuminate\Console\Command;

class AuditHistoricalRouteEvidence extends Command
{
    protected $signature = 'bookings:audit-route-evidence {--booking=} {--output=}';
    protected $description = 'Produce a read-only route-evidence report without changing bookings, pricing, or points';

    public function handle(HistoricalRouteEvidenceAuditService $audit): int
    {
        $rows = [];
        DriverAssignment::query()
            ->when($this->option('booking'), fn ($query, $id) => $query->where('booking_id', $id))
            ->whereNotNull('trip_completed_at')
            ->with('routePoints')
            ->orderBy('id')
            ->chunkById(100, function ($assignments) use (&$rows, $audit) {
                foreach ($assignments as $assignment) {
                    $result = $audit->inspect($assignment);
                    if ($result['issue_codes'] !== []) $rows[] = $result;
                }
            });
        $report = [
            'generated_at' => now('UTC')->toIso8601String(),
            'read_only' => true,
            'corrections_authorized' => false,
            'calculation_policy_version' => config('route_evidence.policy_version'),
            'scope' => ['booking_id' => $this->option('booking') ?: null],
            'items' => $rows,
        ];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($path = $this->option('output')) file_put_contents($path, $json.PHP_EOL);
        else $this->line($json);
        return self::SUCCESS;
    }
}
