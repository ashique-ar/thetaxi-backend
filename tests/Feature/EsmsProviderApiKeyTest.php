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
