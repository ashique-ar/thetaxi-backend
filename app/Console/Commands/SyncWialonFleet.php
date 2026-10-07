<?php

namespace App\Console\Commands;

use App\Services\WialonService;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

class SyncWialonFleet extends Command
{
    protected $signature = 'gps:sync-fleet';
    protected $description = 'Synchronize selected GPS devices with saved portal vehicles';

    public function handle(WialonService $wialon): int
    {
        $failed = false;
        foreach (DB::table('wialon_integrations')->where('enabled', true)->pluck('company_id') as $companyId) {
            try {
                $synced = $wialon->syncVehicles((string) $companyId);
                $this->info("Company {$companyId}: {$synced} vehicle(s) synchronized.");
            } catch (\Throwable $error) {
                $failed = true;
                $this->error("Company {$companyId}: {$error->getMessage()}");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
