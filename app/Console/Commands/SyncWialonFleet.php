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
        $integration = DB::table('wialon_installation_settings')->where('id', 'installation')->first();
        if (! $integration || ! $integration->enabled) return self::SUCCESS;

        try {
            $synced = $wialon->syncVehicles();
            $this->info("Installation GPS: {$synced} vehicle(s) synchronized.");
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error("Installation GPS: {$error->getMessage()}");
            return self::FAILURE;
        }
    }
}
