<?php

use App\Services\Sms\Providers\EsmsProvider;
use Illuminate\Support\Facades\Http;

it('uses the configured API key for sending even when login credentials and a URL key exist', function () {
    Http::fake([
        'https://e-sms.dialog.lk/api/v2/sms' => Http::response([
            'status' => 'success',
            'campaignId' => 'campaign-123',
        ]),
    ]);

    $provider = new EsmsProvider([
        'base_url' => 'https://e-sms.dialog.lk/api',
        'username' => 'locked-user',
        'password' => 'locked-password',
        'api_key' => 'configured-api-key',
        'esmsqk' => 'url-message-key',
    ]);

    $result = $provider->sendSingle([
        'recipient' => '94767706768',
        'message' => 'Company SMS test message',
        'sender_mask' => 'TheTaxi',
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['provider_campaign_id'])->toBe('campaign-123');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) =>
        $request->method() === 'POST'
        && $request->url() === 'https://e-sms.dialog.lk/api/v2/sms'
        && $request->hasHeader('Authorization', 'Bearer configured-api-key')
    );
});

it('refreshes an expired configured API key through login and retries once', function () {
    $smsAttempts = 0;

    Http::fake(function ($request) use (&$smsAttempts) {
        if ($request->url() === 'https://e-sms.dialog.lk/api/v2/user/login') {
            return Http::response([
                'status' => 'success',
                'token' => 'fresh-api-key',
                'expiration' => 43200,
            ]);
        }

        if ($request->url() === 'https://e-sms.dialog.lk/api/v2/sms') {
            $smsAttempts++;

            if ($smsAttempts === 1) {
                return Http::response([
                    'status' => 'failed',
                    'comment' => 'Authentication Token Expired',
                    'errCode' => 100,
                ], 401);
            }

            expect($request->hasHeader('Authorization', 'Bearer fresh-api-key'))->toBeTrue();

            return Http::response([
                'status' => 'success',
                'campaignId' => 'campaign-after-refresh',
            ]);
        }

        return Http::response([], 404);
    });

    $provider = new EsmsProvider([
        'base_url' => 'https://e-sms.dialog.lk/api',
        'username' => 'valid-user',
        'password' => 'valid-password',
        'api_key' => 'expired-api-key',
        'esmsqk' => 'url-message-key',
    ]);

    $result = $provider->sendSingle([
        'recipient' => '94767706768',
        'message' => 'Company SMS test message',
        'sender_mask' => 'TheTaxi',
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['provider_campaign_id'])->toBe('campaign-after-refresh')
        ->and($smsAttempts)->toBe(2);

    Http::assertSentCount(3);
});

it('uses the URL Message Key only for the dedicated balance endpoint', function () {
    Http::fake([
        'https://e-sms.dialog.lk/api/v1/message-via-url/check/balance*' => Http::response('1|1000'),
    ]);

    $provider = new EsmsProvider([
        'base_url' => 'https://e-sms.dialog.lk/api',
        'api_key' => 'configured-api-key',
        'esmsqk' => 'url-message-key',
    ]);

    $balance = $provider->getBalance();

    expect($balance['balance'])->toBe('1000')
        ->and($balance['source'])->toBe('url_key');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) =>
        $request->method() === 'GET'
        && str_contains($request->url(), '/v1/message-via-url/check/balance')
        && $request['esmsqk'] === 'url-message-key'
    );
});

it('uses the configured mask without attempting login in API key mode', function () {
    Http::fake();

    $provider = new EsmsProvider([
        'base_url' => 'https://e-sms.dialog.lk/api',
        'username' => 'locked-user',
        'password' => 'locked-password',
        'api_key' => 'configured-api-key',
        'default_sender_mask' => 'TheTaxi',
    ]);

    expect($provider->getMasks())->toMatchArray([
        'default_mask' => 'TheTaxi',
        'masks' => [['mask' => 'TheTaxi', 'is_default' => true]],
        'source' => 'configured',
    ]);

    Http::assertNothingSent();
});

it('tests login API key and URL Message Key without sending an SMS', function () {
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/v2/user/login')) {
            return Http::response([
                'status' => 'success',
                'token' => 'fresh-token',
                'expiration' => 43200,
            ]);
        }

        if (str_contains($request->url(), '/v2/sms/check-transaction')) {
            return Http::response([
                'status' => 'failed',
                'comment' => 'Unable to find campaign',
                'errCode' => 103,
            ]);
        }

        if (str_contains($request->url(), '/v1/message-via-url/check/balance')) {
            return Http::response('1|1000');
        }

        return Http::response([], 404);
    });

    $provider = new EsmsProvider([
        'base_url' => 'https://e-sms.dialog.lk/api',
        'username' => 'valid-user',
        'password' => 'valid-password',
        'api_key' => 'configured-api-key',
        'esmsqk' => 'url-message-key',
    ]);

    $result = $provider->testCredentials();

    expect($result['ok'])->toBeTrue()
        ->and($result['checks']['login']['ok'])->toBeTrue()
        ->and($result['checks']['api_key']['ok'])->toBeTrue()
        ->and($result['checks']['url_message_key']['ok'])->toBeTrue();

    Http::assertSentCount(3);
    Http::assertNotSent(fn ($request) =>
        str_ends_with($request->url(), '/v2/sms')
    );
});
