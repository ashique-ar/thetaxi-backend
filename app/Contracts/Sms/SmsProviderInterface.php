<?php

namespace App\Contracts\Sms;

interface SmsProviderInterface
{
    public function identifier(): string;

    public function sendSingle(array $payload): array;

    public function sendBulk(array $payload): array;

    public function getBalance(): array;
}
