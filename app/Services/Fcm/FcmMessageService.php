<?php

namespace App\Services\Fcm;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared Firebase Cloud Messaging (HTTP v1) client.
 *
 * Handles OAuth2 service-account authentication and per-token message
 * delivery. Used by both the driver and rider notification services so the
 * credential/token/HTTP handling lives in exactly one place.
 */
class FcmMessageService
{
    private const FIREBASE_MESSAGING_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * Resolve credentials, project id, and a cached OAuth access token, ready
     * for one or more send() calls. Returns null if FCM is not configured or
     * the token could not be obtained (already logged when that happens).
     */
    public function prepareSession(): ?array
    {
        $credentials = $this->getCredentials();
        if (!$credentials) {
            return null;
        }

        $projectId = $this->getProjectId($credentials);
        if (!$projectId) {
            Log::warning('FCM push skipped: FIREBASE_PROJECT_ID could not be resolved from config or credentials');
            return null;
        }

        $accessToken = $this->getAccessToken($credentials);
        if (!$accessToken) {
            return null;
        }

        return [
            'send_url' => $this->getSendUrl($projectId),
            'access_token' => $accessToken,
        ];
    }

    /**
     * Send a single FCM HTTP v1 message to a device token.
     *
     * @param array $session Result of prepareSession()
     * @param string $token Device push token
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Flat string-keyed data payload (see normalizeDataPayload)
     * @return array{success: bool, invalid_token: bool, response: array}
     */
    public function send(array $session, string $token, string $title, string $body, array $data = []): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $session['access_token'],
            'Content-Type' => 'application/json',
        ])->connectTimeout(2)->timeout(5)->post($session['send_url'], [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'content-available' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        if (!$response->successful()) {
            $responseData = $response->json() ?? ['raw' => $response->body()];

            return [
                'success' => false,
                'invalid_token' => $this->shouldInvalidateToken($responseData),
                'response' => $responseData,
            ];
        }

        $responseData = $response->json() ?? [];
        if (!empty($responseData['name'])) {
            return [
                'success' => true,
                'invalid_token' => false,
                'response' => $responseData,
            ];
        }

        return [
            'success' => false,
            'invalid_token' => $this->shouldInvalidateToken($responseData),
            'response' => $responseData,
        ];
    }

    /**
     * Flatten a payload into the string=>string map required by the FCM
     * HTTP v1 "data" field.
     */
    public function normalizeDataPayload(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = json_encode($value);
                continue;
            }
            if (is_bool($value)) {
                $normalized[$key] = $value ? '1' : '0';
                continue;
            }
            $normalized[$key] = $value === null ? '' : (string) $value;
        }

        return $normalized;
    }

    public function shouldInvalidateToken(array $errorPayload): bool
    {
        $errorCode = data_get($errorPayload, 'error.details.0.errorCode');
        $status = data_get($errorPayload, 'error.status');
        $message = (string) data_get($errorPayload, 'error.message', '');

        if (in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return true;
        }

        if ($status === 'NOT_FOUND' && str_contains(strtolower($message), 'registration token')) {
            return true;
        }

        return false;
    }

    private function resolveCredentialsPath(): ?string
    {
        $configuredPath = trim((string) config('services.firebase.credentials', ''));
        if ($configuredPath === '') {
            return null;
        }

        if (is_file($configuredPath)) {
            return $configuredPath;
        }

        $storageRelativePath = storage_path(ltrim(str_replace(['storage\\', 'storage/'], '', $configuredPath), '\\/'));
        if (is_file($storageRelativePath)) {
            return $storageRelativePath;
        }

        $baseRelativePath = base_path($configuredPath);
        if (is_file($baseRelativePath)) {
            return $baseRelativePath;
        }

        return null;
    }

    private function getCredentials(): ?array
    {
        $credentialsPath = $this->resolveCredentialsPath();
        if (!$credentialsPath) {
            Log::warning('FCM push skipped: FIREBASE_CREDENTIALS is not configured or the file was not found');
            return null;
        }

        try {
            $contents = file_get_contents($credentialsPath);
            if ($contents === false) {
                throw new \RuntimeException('Unable to read Firebase credentials file.');
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Firebase credentials JSON is invalid.');
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::warning('FCM push skipped: failed to load Firebase credentials', [
                'path' => $credentialsPath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getProjectId(?array $credentials = null): ?string
    {
        $configuredProjectId = trim((string) config('services.firebase.project_id', ''));
        if ($configuredProjectId !== '') {
            return $configuredProjectId;
        }

        return $credentials['project_id'] ?? null;
    }

    private function getSendUrl(string $projectId): string
    {
        $configuredUrl = trim((string) config('services.firebase.http_v1_url', ''));
        if ($configuredUrl !== '') {
            return $configuredUrl;
        }

        return sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $projectId);
    }

    private function getAccessToken(array $credentials): ?string
    {
        $projectId = $this->getProjectId($credentials);
        $clientEmail = $credentials['client_email'] ?? 'unknown';
        $cacheKey = 'firebase:fcm-access-token:' . md5($clientEmail . '|' . ($projectId ?? ''));

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $serviceAccount = new ServiceAccountCredentials(
                [self::FIREBASE_MESSAGING_SCOPE],
                $credentials
            );

            $tokenData = $serviceAccount->fetchAuthToken();
            $accessToken = $tokenData['access_token'] ?? null;

            if (!is_string($accessToken) || $accessToken === '') {
                Log::warning('Failed to fetch Firebase access token: access token missing', [
                    'token_data' => $tokenData,
                ]);

                return null;
            }

            $expiresIn = (int) ($tokenData['expires_in'] ?? 3600);
            $ttl = max($expiresIn - 120, 300);
            Cache::put($cacheKey, $accessToken, now()->addSeconds($ttl));

            return $accessToken;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch Firebase access token', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
