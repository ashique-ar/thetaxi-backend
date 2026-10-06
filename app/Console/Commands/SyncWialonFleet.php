<?php

namespace App\Console\Commands;

use App\Services\WialonService;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;
use RuntimeException;

class SyncWialonFleet extends Command
{
    protected $signature = 'wialon:sync-fleet';
    protected $description = 'Import newly registered Wialon units into the vehicle fleet';

    public function handle(WialonService $wialon): int
    {
        $failed = false;
        foreach (DB::table('wialon_integrations')->where('enabled', true)->pluck('company_id') as $companyId) {
            try {
                $created = $wialon->syncVehicles((string) $companyId);
                $this->info("Company {$companyId}: {$created} vehicle(s) imported.");
            } catch (RuntimeException $error) {
                $failed = true;
                $this->error("Company {$companyId}: {$error->getMessage()}");
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
