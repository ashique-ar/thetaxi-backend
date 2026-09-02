<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InspectDriverMobileRelease extends Command
{
    protected $signature = 'driver-mobile:inspect-release {--json : Emit machine-readable JSON}';

    protected $description = 'Inspect the source driver-mobile release manifest without changing release policy';

    public function handle(): int
    {
        $manifest = (array) config('driver_mobile_release.manifest', []);
        $published = array_values((array) config('driver_mobile_release.published_releases', []));
        $version = trim((string) ($manifest['version_name'] ?? ''));
        $requiredMetadata = ['commit_sha', 'play_track', 'rollout_percentage', 'release_date'];
        $missing = array_values(array_filter(
            $requiredMetadata,
            fn (string $key): bool => trim((string) ($manifest[$key] ?? '')) === ''
        ));

        $report = [
            'read_only' => true,
            'android_package_id' => config('driver_mobile_release.android_package_id'),
            'version_name' => $version,
            'build_number' => $manifest['build_number'] ?? null,
            'commit_sha' => $manifest['commit_sha'] ?? null,
            'play_track' => $manifest['play_track'] ?? null,
            'rollout_percentage' => $manifest['rollout_percentage'] ?? null,
            'release_date' => $manifest['release_date'] ?? null,
            'declared_published' => $version !== '' && in_array($version, $published, true),
            'mandatory_release_validated' => (bool) config('driver_mobile_release.mandatory_release_validated', false),
            'missing_release_metadata' => $missing,
            'ready_to_advertise' => $version !== '' && in_array($version, $published, true) && $missing === [],
            'ready_for_mandatory_update' => $version !== ''
                && in_array($version, $published, true)
                && $missing === []
                && (bool) config('driver_mobile_release.mandatory_release_validated', false),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Field', 'Value'], collect($report)->map(
                fn ($value, $key) => [$key, is_array($value) ? implode(', ', $value) : json_encode($value)]
            )->values()->all());
        }

        return $report['ready_for_mandatory_update'] ? self::SUCCESS : self::FAILURE;
    }
}
