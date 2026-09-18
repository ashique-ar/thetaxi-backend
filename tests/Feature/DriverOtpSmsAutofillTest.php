<?php

use App\Models\Sms\SmsMessage;
use App\Services\Sms\SmsService;

it('includes the configured Android app hash in the driver OTP SMS', function (): void {
    config()->set('sms.driver_app_hash', 'AbCdEfGhIjK');

    $sms = Mockery::mock(SmsService::class);
    $sms->shouldReceive('queueSingleMessage')->once()->withArgs(function (array $payload): bool {
        return $payload['recipient'] === '+94771234567'
            && preg_match('/^Your driver app OTP is \d{6}\. It expires in 10 minutes\.\nAbCdEfGhIjK$/', $payload['message']) === 1
            && strlen($payload['message']) <= 140;
    })->andReturn(new SmsMessage());
    app()->instance(SmsService::class, $sms);

    $this->postJson('/api/driver/auth/request-otp', ['mobile' => '+94771234567'])
        ->assertOk()->assertJsonPath('data.expires_in', 600);
});
