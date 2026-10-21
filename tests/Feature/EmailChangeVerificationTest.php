<?php

use App\Models\User;
use App\Notifications\EmailChangeOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('keeps a self-service email change pending until the new address is verified', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'employee@example.com']);
    $newEmail = 'employee.new@example.com';

    $this->actingAs($user, 'api')->postJson('/api/profile/email-change', ['email' => $newEmail])
        ->assertOk()
        ->assertJsonPath('message', "A 6-digit verification code was sent to {$newEmail}. It expires in 10 minutes.");

    $otp = null;
    Notification::assertSentOnDemand(EmailChangeOtpNotification::class, function ($notification, $channels, $notifiable) use (&$otp, $newEmail): bool {
        $otp = $notification->otp;
        return $notifiable->routes['mail'] === $newEmail && preg_match('/^\d{6}$/', $otp) === 1;
    });
    expect($user->fresh()->email)->toBe('employee@example.com');

    expect(Hash::check($otp, cache()->get('email_change_otp:'.$user->id)['hash']))->toBeTrue();
    $this->actingAs($user, 'api')->postJson('/api/profile/email-change/verify', ['otp' => $otp])
        ->assertOk()->assertJsonPath('data.email', $newEmail);
    expect($user->fresh()->email)->toBe($newEmail)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});
