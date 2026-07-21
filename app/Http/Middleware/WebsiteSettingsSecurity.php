<?php

namespace App\Http\Middleware;

use App\Services\WebsiteSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebsiteSettingsSecurity
{
    protected WebsiteSettingsService $settingsService;

    public function __construct(WebsiteSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = $this->settingsService->getSecuritySettings();

        $sslForce = $this->normalizeBoolean($settings['ssl_force'] ?? null, false);
        if ($sslForce && !$request->isSecure()) {
            $target = 'https://' . $request->getHttpHost() . $request->getRequestUri();
            return redirect()->to($target, 301);
        }

        $maintenanceMode = $this->normalizeBoolean($settings['maintenance_mode'] ?? null, false);
        if ($maintenanceMode && !$this->isMaintenanceBypass($request)) {
            $message = $settings['maintenance_message'] ?? 'We are currently performing scheduled maintenance.';
            return response()->view('errors.maintenance', [
                'message' => $message,
                'theme' => get_active_theme(),
            ], 503);
        }

        $response = $next($request);

        if ($this->normalizeBoolean($settings['security_headers_enabled'] ?? null, false)) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
            $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

            if ($sslForce || $request->isSecure()) {
                $response->headers->set(
                    'Strict-Transport-Security',
                    'max-age=31536000; includeSubDomains; preload'
                );
            }

            $csp = trim((string) ($settings['content_security_policy'] ?? ''));
            if ($csp !== '') {
                $response->headers->set('Content-Security-Policy', $csp);
            }
        }

        return $response;
    }

    protected function isMaintenanceBypass(Request $request): bool
    {
        return $request->is('system*')
            || $request->is('api/*')
            || $request->is('checkout/webxpay/*')
            || $request->is('up');
    }

    protected function normalizeBoolean($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
