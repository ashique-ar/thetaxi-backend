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

    public function versionCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'platform' => ['nullable', 'string', 'max:50'],
        ]);

        $settings = $this->settingsService->getDriverMobileSettings();
        $latestVersion = $settings['driver_mobile_latest_version'] ?: '1.0.0';
        $mandatoryUpdate = $this->toBoolean($settings['driver_mobile_mandatory_update'] ?? false);
        $currentVersion = trim($validated['version']);
        $updateRequired = version_compare($this->normalizeVersion($currentVersion), $this->normalizeVersion($latestVersion), '<');

        return response()->json([
            'status' => 'success',
            'data' => [
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'update_required' => $updateRequired,
                'mandatory_update' => $mandatoryUpdate && $updateRequired,
                'can_continue' => !($mandatoryUpdate && $updateRequired),
                'message' => $settings['driver_mobile_update_message'] ?: 'A new driver app version is available. Please update to continue.',
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
