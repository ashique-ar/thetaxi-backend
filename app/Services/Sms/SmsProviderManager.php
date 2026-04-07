<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsProviderInterface;
use App\Services\Sms\Providers\EsmsProvider;
use InvalidArgumentException;

class SmsProviderManager
{
    public function __construct(
        private SmsSettingsService $settingsService
    ) {}

    public function active(): SmsProviderInterface
    {
        return $this->make($this->settingsService->getActiveProvider());
    }

    public function make(string $provider): SmsProviderInterface
    {
        return match ($provider) {
            'esms' => new EsmsProvider(
                $this->settingsService->getProviderConfig('esms')
            ),
            default => throw new InvalidArgumentException("Unsupported SMS provider: {$provider}"),
        };
    }
}
