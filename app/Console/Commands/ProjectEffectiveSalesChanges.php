<?php

namespace App\Console\Commands;

use App\Services\Sales\SalesEffectiveProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProjectEffectiveSalesChanges extends Command
{
    protected $signature = 'sales:project-effective-changes';

    protected $description = 'Project due Sales handler transfers and Profile closures into current operational state';

    public function handle(SalesEffectiveProjectionService $projections): int
    {
        if (! Schema::hasTable('sales_booking_attribution_events')
            || ! Schema::hasTable('sales_profile_events')
            || ! Schema::hasColumn('sales_booking_attribution_events', 'projected_at')
            || ! Schema::hasColumn('sales_profile_events', 'projected_at')
            || config('sales.features.sales_profiles', false) !== true) {
            return self::SUCCESS;
        }

        $counts = $projections->projectDue();
        $this->info("Projected {$counts['attributions']} attribution event(s) and {$counts['profiles']} Profile event(s).");

        return self::SUCCESS;
    }
}
