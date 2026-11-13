<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingsController extends Controller
{
    public function __construct(private WebsiteSettingsService $settingsService)
    {
    }

    /** Check whether the installed driver app version is supported or requires an update. */
    public function versionCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'build_number' => ['nullable', 'integer', 'min:1'],
            'platform' => ['nullable', 'string', 'max:50'],
        ]);

        $settings = $this->settingsService->getDriverMobileSettings();
        $configuredLatestVersion = $settings['driver_mobile_latest_version'] ?: '1.0.0';
        // The configured latest version is the version policy. Do not replace it
        // with the client's version when release metadata is incomplete, or an
        // older client will always compare equal and never receive an update.
        $latestVersion = trim((string) $configuredLatestVersion);
        $publishedReleases = config('driver_mobile_release.published_releases', []);
        $mandatoryUpdate = $this->toBoolean($settings['driver_mobile_mandatory_update'] ?? false);
        $currentVersion = trim($validated['version']);
        $platform = strtolower((string) ($validated['platform'] ?? ''));
        $policy = config("driver_mobile_release.platforms.$platform", []);
        $currentBuild = $validated['build_number'] ?? null;
        $latestBuild = (int) ($policy['latest_build'] ?? 0);
        $minimumBuild = (int) ($policy['minimum_supported_build'] ?? 0);
        $updateRequired = version_compare($this->normalizeVersion($currentVersion), $this->normalizeVersion($latestVersion), '<')
            || ($currentBuild !== null && $latestBuild > 0 && $currentBuild < $latestBuild);
        $belowMinimum = $currentBuild !== null && $minimumBuild > 0 && $currentBuild < $minimumBuild;
        $mandatoryUpdateRequired = $belowMinimum || ($mandatoryUpdate && $updateRequired);
        $releaseNotes = config('driver_mobile_release.release_notes');
        $message = $settings['driver_mobile_update_message'] ?: $releaseNotes ?: 'A new driver app version is available. Please update to continue.';

        return response()->json([
            'status' => 'success',
            'data' => [
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'update_required' => $updateRequired,
                'current_build_number' => $validated['build_number'] ?? null,
                'latest_build' => $latestBuild ?: null,
                'minimum_supported_build' => $minimumBuild ?: null,
                'update_url' => str_starts_with((string) ($policy['update_url'] ?? ''), 'https://') ? $policy['update_url'] : null,
                'release_notes' => $releaseNotes,
                'mandatory_update' => $mandatoryUpdateRequired,
                'can_continue' => !$mandatoryUpdateRequired,
                'release_policy' => [
                    'android_package_id' => config('driver_mobile_release.android_package_id'),
                    'advertised_release_published' => in_array($configuredLatestVersion, $publishedReleases, true),
                    'mandatory_release_validated' => (bool) config('driver_mobile_release.mandatory_release_validated', false),
                ],
                'message' => $message,
            ],
        ]);
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
