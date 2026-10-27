<?php

namespace App\Http\Middleware;

use App\Services\WebsiteSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBusinessModuleEnabled
{
    public function __construct(private readonly WebsiteSettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $setting = match ($module) {
            'hr' => 'feature_hr_management_enabled',
            'hr-attendance-ingestion' => 'feature_hr_attendance_ingestion_enabled',
            'sales' => 'feature_sales_management_enabled',
            'driver' => 'feature_driver_management_enabled',
            default => null,
        };

        if ($setting === null) {
            abort(404);
        }

        $default = $module === 'hr-attendance-ingestion' ? false : true;
        $value = $this->settings->get($setting, $default);
        $enabled = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;

        if (!$enabled) {
            return response()->json([
                'status' => 'error',
                'message' => ucfirst($module) . ' module is disabled by an administrator.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
