<?php

namespace App\Services\Sms\Providers;

use App\Contracts\Sms\SmsProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EsmsProvider implements SmsProviderInterface
{
    /**
     * Error codes shared by the v2 login and SMS (POST) APIs.
     * @see eSMS API Document v2.9 section 3.1.5
     */
    private const POST_ERROR_CODES = [
        '100' => 'Invalid token (token expired)',
        '101' => 'Invalid request parameters',
        '102' => 'User account not found or not a valid account',
        '103' => 'Unable to find a campaign for the specified transaction ID',
        '104' => 'Transaction ID is already used',
        '105' => 'Invalid token signature',
        '106' => 'Token not found in the header',
        '107' => 'One or more mandatory parameters in the request is either missing or invalid',
        '108' => 'User does not have an active mask eligible to send messages',
        '109' => 'No valid mobile number left after removing invalid, duplicate and mask-blocked numbers',
        '110' => 'Not eligible to consume packaging',
        '111' => 'Package payments can only be used for campaigns scheduled for this month',
        '112' => 'Number of messages left in the package is less than the campaign messages',
        '113' => 'Package maintenance downtime',
        '114' => 'Not enough wallet balance to run the campaign',
        '115' => 'Username or password invalid',
        '116' => 'Account locked',
        '117' => 'Too many requests',
        '118' => 'Campaigns cannot be created during the system blackout period (generally 08:00 PM to 08:00 AM)',
        '999' => 'Internal server error',
    ];

    /**
     * Error/response codes for the URL (GET) based SMS and balance APIs.
     * @see eSMS API Document v2.9 sections 3.2.3 and 3.2.2
     */
    private const GET_ERROR_CODES = [
        '1' => 'Success',
        '2001' => 'Error occurred during campaign creation',
        '2002' => 'Bad request',
        '2003' => 'Empty number list',
        '2004' => 'Empty message body',
        '2005' => 'Invalid number list format',
        '2006' => 'Not eligible to send messages via GET requests (admin has not granted access)',
        '2007' => 'Invalid key (esmsqk parameter is invalid)',
        '2008' => 'Not enough money in the wallet or not enough messages left in the package',
        '2009' => 'No valid numbers found after removing mask-blocked numbers',
        '2010' => 'Not eligible to consume packaging',
        '2011' => 'Transactional error',
        '2012' => 'Does not have access for this mask',
        '2013' => 'Campaigns cannot be created during the system blackout period (generally 08:00 PM to 08:00 AM)',
        '2020' => 'Too many requests',
    ];

    private const TOKEN_EXPIRED_ERROR_CODE = '100';

    /** Safety margin (seconds) subtracted from the token expiration before it is treated as stale. */
    private const TOKEN_EXPIRY_BUFFER = 300;

    private ?string $resolvedApiKey = null;
    private ?array $resolvedLoginPayload = null;

    public function __construct(
        private array $config
    ) {}

    public function identifier(): string
    {
        return 'esms';
    }

    /**
     * Validate configured credentials without creating an SMS campaign.
     */
    public function testCredentials(): array
    {
        $checks = [];

        $username = trim((string) ($this->config['username'] ?? ''));
        $password = trim((string) ($this->config['password'] ?? ''));
        if ($username !== '' || $password !== '') {
            try {
                $payload = $this->login(true);
                $checks['login'] = [
                    'configured' => true,
                    'ok' => strtolower((string) ($this->extractValue($payload, ['status']) ?? '')) === 'success',
                    'message' => (string) ($this->extractValue($payload, ['comment']) ?? 'Username and password accepted by eSMS'),
                    'token_expires_in' => $this->extractValue($payload, ['expiration']),
                ];
            } catch (\Throwable $exception) {
                $checks['login'] = [
                    'configured' => true,
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey !== '') {
            try {
                $response = $this->http()
                    ->withToken($apiKey)
                    ->post($this->endpointUrl('v2/sms/check-transaction'), [
                        'transaction_id' => '999999999999999999',
                    ]);
                $payload = $response->json();
                $payload = is_array($payload) ? $payload : [];
                $errCode = (string) ($this->extractValue($payload, ['errCode']) ?? '');
                $authenticationErrors = ['100', '105', '106', '115', '116'];
                $ok = !in_array($errCode, $authenticationErrors, true)
                    && !in_array($response->status(), [401, 403], true);

                $checks['api_key'] = [
                    'configured' => true,
                    'ok' => $ok,
                    'message' => $ok
                        ? 'API key accepted by eSMS'
                        : $this->describeFailure($payload, self::POST_ERROR_CODES),
                    'http_status' => $response->status(),
                    'err_code' => $errCode !== '' ? $errCode : null,
                ];
            } catch (\Throwable $exception) {
                $checks['api_key'] = [
                    'configured' => true,
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $esmsqk = trim((string) ($this->config['esmsqk'] ?? ''));
        if ($esmsqk !== '') {
            try {
                $balance = $this->getBalanceViaUrlKey($esmsqk);
                $checks['url_message_key'] = [
                    'configured' => true,
                    'ok' => (bool) ($balance['balance_available'] ?? false),
                    'message' => $balance['balance_available']
                        ? 'URL Message Key accepted by eSMS'
                        : ($balance['comment'] ?? 'URL Message Key validation failed'),
                    'balance' => $balance['balance'] ?? null,
                ];
            } catch (\Throwable $exception) {
                $checks['url_message_key'] = [
                    'configured' => true,
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $configuredChecks = array_values($checks);

        return [
            'provider' => $this->identifier(),
            'ok' => $configuredChecks !== []
                && collect($configuredChecks)->every(fn (array $check) => $check['ok'] === true),
            'checks' => $checks,
        ];
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
        $recipients = array_values(array_filter(array_map(
            fn($recipient) => trim((string) $recipient),
            $payload['recipients'] ?? []
        )));

        if ($recipients === []) {
            throw new RuntimeException('No recipients provided for SMS send');
        }

        $transactionId = $this->resolveTransactionId($payload['meta']['transaction_id'] ?? null);

        $requestBody = [
            'transaction_id' => $transactionId,
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

        $pushNotificationUrl = trim((string) ($payload['push_notification_url'] ?? $this->config['delivery_callback_url'] ?? ''));
        if ($pushNotificationUrl !== '') {
            $requestBody['push_notification_url'] = $pushNotificationUrl;
        }

        $response = $this->requestWithTokenRetry(
            fn (string $token) => $this->http()
                ->withToken($token)
                ->post($this->endpointUrl('v2/sms'), $requestBody)
                ->throw()
                ->json()
        );

        $status = strtolower((string) ($this->extractValue($response, ['status']) ?? ''));
        if ($status !== 'success') {
            throw new RuntimeException($this->describeFailure($response, self::POST_ERROR_CODES));
        }

        return [
            'ok' => true,
            'provider' => $this->identifier(),
            'transaction_id' => $transactionId,
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

    /**
     * Check the delivery/creation status of a previously sent campaign via its transaction id.
     * @see eSMS API Document v2.9 section 3.1.3
     */
    public function checkTransactionStatus(string $transactionId): array
    {
        $response = $this->requestWithTokenRetry(
            fn (string $token) => $this->http()
                ->withToken($token)
                ->post($this->endpointUrl('v2/sms/check-transaction'), [
                    'transaction_id' => $transactionId,
                ])
                ->throw()
                ->json()
        );

        $status = strtolower((string) ($this->extractValue($response, ['status']) ?? ''));
        if ($status !== 'success') {
            throw new RuntimeException($this->describeFailure($response, self::POST_ERROR_CODES));
        }

        return [
            'transaction_id' => $transactionId,
            'campaign_status' => $this->extractValue($response, ['campaign status', 'campaign_status']),
            'comment' => $this->extractValue($response, ['comment']),
            'raw' => $response,
        ];
    }

    /**
     * List every mask (default + additional) available on the account, so the UI never has
     * to make the user free-type a sender id.
     */
    public function getMasks(bool $forceRefresh = false): array
    {
        $apiKey = trim((string) ($this->config['api_key'] ?? ''));
        if ($apiKey !== '') {
            $defaultMask = trim((string) ($this->config['default_sender_mask'] ?? ''));

            return [
                'provider' => $this->identifier(),
                'default_mask' => $defaultMask !== '' ? $defaultMask : null,
                'masks' => $defaultMask !== ''
                    ? [['mask' => $defaultMask, 'is_default' => true]]
                    : [],
                'source' => 'configured',
            ];
        }

        $loginPayload = $this->login($forceRefresh);
        $userData = $this->userData($loginPayload);

        $defaultMask = trim((string) ($userData['defaultMask'] ?? ''));
        $masks = [];

        if ($defaultMask !== '') {
            $masks[] = ['mask' => $defaultMask, 'is_default' => true];
        }

        foreach ((array) ($userData['additional_mask'] ?? []) as $entry) {
            $mask = trim((string) (is_array($entry) ? ($entry['mask'] ?? '') : $entry));
            if ($mask === '' || $mask === $defaultMask) {
                continue;
            }
            $masks[] = ['mask' => $mask, 'is_default' => false];
        }

        return [
            'provider' => $this->identifier(),
            'default_mask' => $defaultMask !== '' ? $defaultMask : null,
            'masks' => $masks,
        ];
    }

    public function getBalance(bool $forceRefresh = false): array
    {
        $esmsqk = trim((string) ($this->config['esmsqk'] ?? ''));
        if ($esmsqk !== '') {
            return $this->getBalanceViaUrlKey($esmsqk);
        }

        $loginPayload = $this->login($forceRefresh);
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
            'source' => 'login',
        ];
    }

    /**
     * Dedicated, lightweight balance check via the URL Message Key (esmsqk).
     * Unlike the login response this does not require re-authenticating and reflects the
     * live wallet balance at call time.
     * @see eSMS API Document v2.9 section 3.2.2
     */
    private function getBalanceViaUrlKey(string $esmsqk): array
    {
        $raw = trim((string) $this->http()
            ->get($this->endpointUrl('v1/message-via-url/check/balance'), [
                'esmsqk' => $esmsqk,
            ])
            ->throw()
            ->body());

        [$code, $balance] = array_pad(explode('|', $raw, 2), 2, null);
        $code = trim((string) $code);
        $success = $code === '1';

        return [
            'provider' => $this->identifier(),
            'balance' => $success ? $balance : null,
            'connected' => true,
            'balance_available' => $success && $balance !== null && $balance !== '',
            'comment' => $success ? null : ($this->describeGetErrorCode($code) ?? "eSMS balance check failed (code {$code})"),
            'token_expires_in' => null,
            'source' => 'url_key',
        ];
    }

    private function login(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget($this->tokenCacheKey());
            $this->resolvedLoginPayload = null;
            $this->resolvedApiKey = null;
        }

        return $this->resolveLoginPayload();
    }

    private function resolveApiKey(bool $forceRefresh = false): string
    {
        if (!$forceRefresh && $this->resolvedApiKey) {
            return $this->resolvedApiKey;
        }

        $configuredApiKey = trim((string) ($this->config['api_key'] ?? ''));
        if (!$forceRefresh && $configuredApiKey !== '') {
            return $this->resolvedApiKey = $configuredApiKey;
        }

        $response = $this->resolveLoginPayload($forceRefresh);

        $apiKey = $this->extractValue(
            $response,
            ['esmsqk', 'token', 'api_key', 'key', 'access_token']
        );

        if ($apiKey) {
            return $this->resolvedApiKey = (string) $apiKey;
        }

        throw new RuntimeException('Unable to resolve eSMS API key from login response');
    }

    /**
     * Resolves the login payload, reusing a cached token for its documented lifetime
     * (~12 hours) instead of calling v2/user/login on every request. Per the eSMS docs
     * this endpoint "should be called only on initial request and on access token expiration".
     */
    private function resolveLoginPayload(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && $this->resolvedLoginPayload !== null) {
            return $this->resolvedLoginPayload;
        }

        if (!empty($this->config['api_key']) && empty($this->config['username'])) {
            return $this->resolvedLoginPayload = [
                'status' => 'success',
                'comment' => 'Balance and masks unavailable when only an API key/token is configured',
                'token' => (string) $this->config['api_key'],
            ];
        }

        $cacheKey = $this->tokenCacheKey();

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $this->resolvedLoginPayload = $cached;
            }
        }

        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new RuntimeException('eSMS credentials are not configured');
        }

        $response = $this->http()
            ->post($this->endpointUrl('v2/user/login'), [
                'username' => $username,
                'password' => $password,
            ])
            ->throw()
            ->json();

        $response = is_array($response) ? $response : [];

        $status = strtolower((string) ($this->extractValue($response, ['status']) ?? ''));
        if ($status !== 'success') {
            throw new RuntimeException($this->describeFailure($response, self::POST_ERROR_CODES));
        }

        $expiration = (int) ($this->extractValue($response, ['expiration']) ?? 43200);
        $ttl = max(60, $expiration - self::TOKEN_EXPIRY_BUFFER);
        Cache::put($cacheKey, $response, $ttl);

        return $this->resolvedLoginPayload = $response;
    }

    /**
     * Runs an authenticated request, transparently forcing a fresh login and retrying once
     * if the cached token turned out to be expired/invalid (error code 100).
     */
    private function requestWithTokenRetry(callable $request): array
    {
        $token = $this->resolveApiKey();

        try {
            $response = $request($token);
        } catch (\Illuminate\Http\Client\RequestException $exception) {
            $errorPayload = $exception->response->json();
            $errCode = is_array($errorPayload)
                ? (string) ($this->extractValue($errorPayload, ['errCode']) ?? '')
                : '';

            if ($errCode !== self::TOKEN_EXPIRED_ERROR_CODE) {
                throw $exception;
            }

            $token = $this->resolveApiKey(true);

            return $request($token);
        }

        $errCode = (string) ($this->extractValue($response, ['errCode']) ?? '');
        $status = strtolower((string) ($this->extractValue($response, ['status']) ?? ''));

        if ($status !== 'success' && $errCode === self::TOKEN_EXPIRED_ERROR_CODE) {
            $token = $this->resolveApiKey(true);
            $response = $request($token);
        }

        return $response;
    }

    private function tokenCacheKey(): string
    {
        $username = (string) ($this->config['username'] ?? '');
        $baseUrl = $this->normalizeBaseUrl((string) ($this->config['base_url'] ?? ''));

        return 'esms:login:' . md5($baseUrl . '|' . $username);
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

        return substr($candidate, 0, 18);
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

    private function userData(array $loginPayload): array
    {
        $userData = $this->extractValue($loginPayload, ['userData']);

        return is_array($userData) ? $userData : [];
    }

    private function describeFailure(array $response, array $errorCodes): string
    {
        $comment = $this->extractValue($response, ['comment']);
        $errCode = $this->extractValue($response, ['errCode']);
        $errCodeKey = $errCode !== null ? (string) $errCode : null;

        $description = $errCodeKey !== null ? ($errorCodes[$errCodeKey] ?? null) : null;

        if (is_string($comment) && $comment !== '') {
            return $description ? "{$comment} (errCode {$errCodeKey}: {$description})" : $comment;
        }

        if ($description) {
            return "eSMS request failed (errCode {$errCodeKey}: {$description})";
        }

        return 'eSMS request failed for an unknown reason';
    }

    private function describeGetErrorCode(string $code): ?string
    {
        return self::GET_ERROR_CODES[$code] ?? null;
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
