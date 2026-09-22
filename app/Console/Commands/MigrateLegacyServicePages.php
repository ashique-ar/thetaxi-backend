<?php

namespace App\Console\Commands;

use App\Services\Website\LegacyServiceCmsMigration;
use Illuminate\Console\Command;

class MigrateLegacyServicePages extends Command
{
    protected $signature = 'services:migrate-legacy {--apply : Apply safe mappings; default is dry run}';

    protected $description = 'Report or safely migrate legacy service pages to CMS services';

    public function handle(LegacyServiceCmsMigration $migration): int
    {
        $rows = $this->option('apply') ? $migration->apply() : $migration->report();
        $this->table(['Legacy ID', 'Legacy slug', 'Action', 'CMS ID'], array_map('array_values', $rows));
        $counts = collect($rows)->countBy('action');
        $this->info(($this->option('apply') ? 'Applied' : 'Dry run').': '.$counts->map(fn ($count, $action) => "$action=$count")->implode(', '));

        return $counts->has('collision') || $counts->has('ambiguous') ? self::FAILURE : self::SUCCESS;
    }
}
