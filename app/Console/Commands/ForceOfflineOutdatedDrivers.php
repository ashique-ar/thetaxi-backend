<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Services\Driver\DriverAuthService;
use App\Services\Driver\RouteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ForceOfflineOutdatedDrivers extends Command
{
    protected $signature = 'drivers:force-offline-outdated {--minimum-version= : Minimum supported app version; older active devices are logged out} {--dry-run : Show the affected driver count without changing anything}';

    protected $description = 'Force drivers using an app version below the minimum to go offline and log in again';

    public function handle(): int
    {
        $minimumVersion = trim((string) $this->option('minimum-version'));
        if ($minimumVersion === '') {
            $this->error('Provide --minimum-version (the release cutoff two versions behind current).');
            return self::FAILURE;
        }

        $query = Driver::query()->whereHas('activeDevices', function ($devices) use ($minimumVersion) {
            $devices->whereNotNull('app_version')->where('app_version', '!=', '');
        })->with(['activeDevices', 'user']);
        $drivers = $query->get()->filter(fn (Driver $driver) => $driver->activeDevices->contains(
            fn ($device) => $device->app_version
                && version_compare(ltrim(trim((string) $device->app_version), 'vV'), ltrim($minimumVersion, 'vV'), '<')
        ));

        if ($this->option('dry-run')) {
            $this->info("{$drivers->count()} driver(s) would be forced offline and logged out.");
            return self::SUCCESS;
        }

        $routes = app(RouteService::class);
        $auth = app(DriverAuthService::class);
        foreach ($drivers as $driver) {
            DB::transaction(function () use ($driver, $routes, $auth): void {
                $now = now();
                $sessions = $driver->sessions()->where('status', 'active')->get();
                foreach ($sessions as $session) {
                    $session->update([
                        'status' => 'auto_closed',
                        'end_time' => $now,
                        'end_latitude' => $driver->current_latitude,
                        'end_longitude' => $driver->current_longitude,
                        'total_distance_km' => $routes->calculateDistance($session),
                    ]);
                }
                $driver->update(['is_online' => false, 'current_device_uuid' => null]);
                if ($driver->user) {
                    $auth->revokeAllTokens($driver->user);
                }
            });
        }

        $this->info("{$drivers->count()} driver(s) forced offline; all their login tokens were revoked.");
        return self::SUCCESS;
    }
}
