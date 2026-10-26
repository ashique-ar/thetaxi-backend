<?php

use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Laravel\Passport\Contracts\DeviceUserCodeViewResponse;

it('registers Passport authorization views', function () {
    expect(app()->bound(AuthorizationViewResponse::class))->toBeTrue()
        ->and(app()->bound(DeviceAuthorizationViewResponse::class))->toBeTrue()
        ->and(app()->bound(DeviceUserCodeViewResponse::class))->toBeTrue()
        ->and(view()->exists('auth.oauth.authorize'))->toBeTrue()
        ->and(view()->exists('auth.oauth.device.authorize'))->toBeTrue()
        ->and(view()->exists('auth.oauth.device.user-code'))->toBeTrue();
});
