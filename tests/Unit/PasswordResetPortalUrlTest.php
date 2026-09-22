<?php

use App\Models\User;
use App\Notifications\PasswordResetNotification;

uses(Tests\TestCase::class);

it('builds a portal reset link with the token and encoded email', function () {
    config()->set('app.portal_url', 'https://portal.example.com/');
    $user = new User(['email' => 'person+work@example.com']);
    $notification = new PasswordResetNotification('token-value');

    $url = (new ReflectionMethod($notification, 'resetUrl'))->invoke($notification, $user);

    expect($url)->toBe('https://portal.example.com/auth/reset-password?token=token-value&email=person%2Bwork%40example.com');
});
