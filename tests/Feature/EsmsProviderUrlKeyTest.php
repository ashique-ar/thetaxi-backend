<?php

use App\Services\Sms\Providers\EsmsProvider;
use Illuminate\Support\Facades\Http;

it('sends through the URL Message Key API without logging in', function () {
    Http::fake([
        'https://e-sms.dialog.lk/api/v1/message-via-url/create/url-campaign*' => Http::response('1'),
    ]);

    $provider = new EsmsProvider([
        'base_url' => 'https://e-sms.dialog.lk/api',
        'username' => 'locked-user',
        'password' => 'locked-password',
        'esmsqk' => 'url-message-key',
        'delivery_callback_url' => 'https://example.test/api/sms/webhooks/delivery-report',
    ]);

    $result = $provider->sendSingle([
        'recipient' => '94767706768',
        'message' => 'Company SMS test message',
        'sender_mask' => 'Company',
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['source'])->toBe('url_key');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) =>
        $request->method() === 'GET'
        && str_contains($request->url(), '/v1/message-via-url/create/url-campaign')
        && $request['esmsqk'] === 'url-message-key'
        && $request['list'] === '94767706768'
        && $request['source_address'] === 'Company'
        && $request['message'] === 'Company SMS test message'
    );
});

it('reports URL Message Key API error codes', function () {
    Http::fake(['*' => Http::response('2007')]);

    $provider = new EsmsProvider([
        'base_url' => 'https://e-sms.dialog.lk/api',
        'esmsqk' => 'invalid-key',
    ]);

    expect(fn () => $provider->sendSingle([
        'recipient' => '94767706768',
        'message' => 'Test',
    ]))->toThrow(RuntimeException::class, 'Invalid key');
});
