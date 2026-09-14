<?php

namespace App\Console\Commands;

use App\Services\CorporateStaffTransportService;
use Illuminate\Console\Command;

class GenerateCorporateTransportBookings extends Command
{
    protected $signature = 'corporate-transport:generate-bookings
        {--date= : Service date in YYYY-MM-DD format}
        {--corporate= : Limit generation to one corporate UUID}
        {--dry-run : Resolve rosters without creating or updating bookings}
        {--force : Generate even if cutoff has not passed}
        {--lookahead=14 : Number of days ahead to evaluate when --date is omitted}';

    protected $description = 'Generate grouped multi-stop bookings for corporate staff transport rosters';

    public function handle(CorporateStaffTransportService $service): int
    {
        $dates = $this->option('date')
            ? [$this->option('date')]
            : collect(range(0, max(0, (int) $this->option('lookahead'))))
                ->map(fn (int $offset) => now()->addDays($offset)->toDateString())
                ->all();

        $summary = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'dry_run' => (bool) $this->option('dry-run')];
        foreach ($dates as $date) {
            $result = $service->generateForDate(
                $date,
                $this->option('corporate') ?: null,
                (bool) $this->option('dry-run'),
                (bool) $this->option('force')
            );
            foreach (['processed', 'created', 'updated', 'skipped', 'errors'] as $key) {
                $summary[$key] += (int) ($result[$key] ?? 0);
            }
        }

        $this->table(['Metric', 'Count'], collect($summary)->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : $value])->values()->all());

        return ($summary['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
