<?php

namespace App\Console\Commands;

use App\Services\Sales\SalesProfileExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ExpireSalesProfileExports extends Command
{
    protected $signature = 'sales:expire-profile-exports';

    protected $description = 'Purge private Sales Profile roster exports after their approved retention period';

    public function handle(SalesProfileExportService $exports): int
    {
        if (! Schema::hasTable('sales_profile_exports')
            || ! Schema::hasColumn('sales_profile_exports', 'expires_at')
            || config('sales.features.sales_profiles', false) !== true) {
            return self::SUCCESS;
        }

        $this->info("Purged {$exports->purgeExpired()} expired Sales Profile export(s).");

        return self::SUCCESS;
    }
}
