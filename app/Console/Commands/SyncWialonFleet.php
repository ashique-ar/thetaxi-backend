<?php

namespace App\Console\Commands;

use App\Services\WialonService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;

class SyncWialonFleet extends Command
{
    protected $signature = 'gps:sync-fleet';
    protected $description = 'Synchronize selected GPS devices with saved portal vehicles';

    public function handle(WialonService $wialon): int
    {
        try {
            $integration = DB::table('wialon_installation_settings')->where('id', 'installation')->first();
            if (! $integration || ! $integration->enabled) return self::SUCCESS;

            $credentialHash = hash('sha256', $integration->token);
            $rejectedCredential = Cache::get('gps:wialon:rejected-credential');
            if (hash_equals((string) $rejectedCredential, $credentialHash)) {
                $this->comment('Installation GPS sync is paused until the saved GPS token changes.');
                return self::SUCCESS;
            }

            $synced = $wialon->syncVehicles();
            Cache::forget('gps:wialon:rejected-credential');
            $this->info("Installation GPS: {$synced} vehicle(s) synchronized.");
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error("Installation GPS: {$error->getMessage()}");
            Log::error('Installation GPS fleet synchronization failed', [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);
            if (str_contains($error->getMessage(), 'GPS service rejected token/login (8)')) {
                Cache::forever('gps:wialon:rejected-credential', hash('sha256', $integration->token));
            }
            return self::FAILURE;
        }
    }
}
