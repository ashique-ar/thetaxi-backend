<?php

namespace App\Services\Sms;

use App\Services\WebsiteSettingsService;

class SmsSettingsService
{
    public function __construct(
        private WebsiteSettingsService $websiteSettingsService
    ) {}

    public function getSettings(): array
    {
        $settings = $this->websiteSettingsService->getSmsSettings();

        return [
            'enabled' => $this->toBool($settings['sms_enabled'] ?? true, true),
            'provider' => $settings['sms_provider'] ?: 'esms',
            'default_sender_mask' => $settings['sms_default_sender_mask'] ?: null,
            'allow_mask_override' => $this->toBool(
                $settings['sms_allow_mask_override'] ?? true,
                true
            ),
            'queue_enabled' => $this->toBool(
                $settings['sms_queue_enabled'] ?? true,
                true
            ),
            'bulk_chunk_size' => max(1, (int) ($settings['sms_bulk_chunk_size'] ?: 250)),
            'webhook_secret' => $settings['sms_webhook_secret'] ?: null,
            'booking_status_enabled' => $this->toBool(
                $settings['sms_booking_status_enabled'] ?? false,
                false
            ),
            'providers' => [
                'esms' => [
                    'base_url' => rtrim(
                        $settings['sms_esms_base_url'] ?: 'https://e-sms.dialog.lk/api',
                        '/'
                    ),
                    'username' => $settings['sms_esms_username'] ?: null,
                    'password' => $settings['sms_esms_password'] ?: null,
                    'api_key' => $settings['sms_esms_api_key'] ?: null,
                    'esmsqk' => $settings['sms_esms_esmsqk'] ?: null,
                    'default_sender_mask' => $settings['sms_default_sender_mask'] ?: null,
                    'delivery_callback_url' => $settings['sms_esms_delivery_callback_url'] ?: null,
                ],
            ],
        ];
    }

    public function getActiveProvider(): string
    {
        return $this->getSettings()['provider'];
    }

    public function getProviderConfig(string $provider): array
    {
        return $this->getSettings()['providers'][$provider] ?? [];
    }

    public function isEnabled(): bool
    {
        return $this->getSettings()['enabled'];
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}
