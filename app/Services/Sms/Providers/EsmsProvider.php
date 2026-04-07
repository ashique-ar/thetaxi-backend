<?php

namespace App\Services\Sms\Providers;

use App\Contracts\Sms\SmsProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EsmsProvider implements SmsProviderInterface
{
    private ?string $resolvedApiKey = null;
    private ?array $resolvedLoginPayload = null;

    public function __construct(
        private array $config
    ) {}

    public function identifier(): string
    {
        return 'esms';
    }

    public function sendSingle(array $payload): array
    {
        return $this->sendBulk([
            'recipients' => [$payload['recipient']],
            'message' => $payload['message'],
            'sender_mask' => $payload['sender_mask'] ?? null,
            'meta' => $payload['meta'] ?? [],
        ]);
    }

    public function sendBulk(array $payload): array
    {
        $token = $this->resolveApiKey();
        $recipients = array_values(array_filter(array_map(
            fn($recipient) => trim((string) $recipient),
            $payload['recipients'] ?? []
        )));

        if ($recipients === []) {
            throw new RuntimeException('No recipients provided for SMS send');
        }

        $requestBody = [
            'transaction_id' => $this->resolveTransactionId($payload['meta']['transaction_id'] ?? null),
            'message' => $payload['message'],
            'msisdn' => array_map(
                fn($recipient) => ['mobile' => $recipient],
                $recipients
            ),
        ];

        $senderMask = trim((string) ($payload['sender_mask'] ?? ''));
        if ($senderMask !== '') {
            $requestBody['sourceAddress'] = $senderMask;
        }

        $response = $this->http()
            ->withToken($token)
            ->post($this->endpointUrl('v2/sms'), $requestBody)
            ->throw()
            ->json();

        return [
            'ok' => true,
            'provider' => $this->identifier(),
            'provider_campaign_id' => $this->extractValue(
                $response,
                ['campaignId', 'campaign_id', 'campaignCode', 'id']
            ),
            'provider_message_id' => $this->extractValue(
                $response,
                ['messageId', 'message_id', 'id']
            ),
            'raw' => $response,
        ];
    }

    public function getBalance(): array
    {
        $loginPayload = $this->resolveLoginPayload();
        $status = strtolower((string) ($this->extractValue($loginPayload, ['status']) ?? ''));
        $connected = in_array($status, ['success', 'true', '1'], true);
        $comment = $this->extractValue($loginPayload, ['comment']);

        $balance = $this->extractValue(
            $loginPayload,
            [
                'walletBalance',
                'wallet_balance',
                'balance',
                'credit',
                'credits',
                'available_balance',
            ]
        );

        return [
            'provider' => $this->identifier(),
            'balance' => $balance,
            'connected' => $connected,
            'balance_available' => $balance !== null && $balance !== '',
            'comment' => is_string($comment) && $comment !== '' ? $comment : null,
            'token_expires_in' => $this->extractValue($loginPayload, ['expiration']),
        ];
    }

    private function resolveApiKey(): string
    {
        if ($this->resolvedApiKey) {
            return $this->resolvedApiKey;
        }

        $response = $this->resolveLoginPayload();

        $apiKey = $this->extractValue(
            $response,
            ['esmsqk', 'token', 'api_key', 'key', 'access_token']
        );

        if ($apiKey) {
            return $this->resolvedApiKey = (string) $apiKey;
        }

        if (!empty($this->config['api_key'])) {
            return $this->resolvedApiKey = (string) $this->config['api_key'];
        }

        throw new RuntimeException('Unable to resolve eSMS API key from login response');
    }

    private function resolveLoginPayload(): array
    {
        if ($this->resolvedLoginPayload !== null) {
            return $this->resolvedLoginPayload;
        }

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username === '' || $password === '') {
            if (!empty($this->config['api_key'])) {
                return $this->resolvedLoginPayload = [
                    'status' => true,
                    'comment' => 'Balance unavailable when only API key/token is configured',
                    'token' => (string) $this->config['api_key'],
                ];
            }

            throw new RuntimeException('eSMS credentials are not configured');
        }

        $response = $this->http()
            ->post($this->endpointUrl('v1/login'), [
                'username' => $username,
                'password' => $password,
            ])
            ->throw()
            ->json();

        return $this->resolvedLoginPayload = is_array($response) ? $response : [];
    }

    private function http()
    {
        return Http::timeout(30)
            ->acceptJson()
            ->asJson();
    }

    private function endpointUrl(string $path): string
    {
        return $this->normalizeBaseUrl((string) ($this->config['base_url'] ?? ''))
            . '/'
            . ltrim($path, '/');
    }

    private function resolveTransactionId(mixed $transactionId): string
    {
        $candidate = preg_replace('/\D+/', '', (string) ($transactionId ?? ''));
        if ($candidate === null || strlen($candidate) < 10) {
            $candidate = (string) random_int(1000000000, 2147483647);
        }

        return substr($candidate, 0, 10);
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');

        if ($normalized === '') {
            $normalized = 'https://e-sms.dialog.lk/api';
        }

        if (!str_ends_with(strtolower($normalized), '/api')) {
            $normalized .= '/api';
        }

        return $normalized;
    }

    private function extractValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }

            if (
                isset($payload['data']) &&
                is_array($payload['data']) &&
                array_key_exists($key, $payload['data'])
            ) {
                return $payload['data'][$key];
            }

             if (
                isset($payload['userData']) &&
                is_array($payload['userData']) &&
                array_key_exists($key, $payload['userData'])
            ) {
                return $payload['userData'][$key];
            }
        }

        return null;
    }
}
