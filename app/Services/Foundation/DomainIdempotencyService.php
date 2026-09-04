<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DomainIdempotencyService
{
    public function begin(string $domain, string $scope, string $key, string $requestHash, ?int $ttlSeconds = 86400): object
    {
        return DB::transaction(function () use ($domain, $scope, $key, $requestHash, $ttlSeconds) {
            $existing = DB::table('domain_idempotency_keys')
                ->where(compact('domain', 'scope'))
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new RuntimeException('The idempotency key was already used with a different request.');
                }

                return $existing;
            }

            $id = (string) Str::uuid();
            DB::table('domain_idempotency_keys')->insert([
                'id' => $id,
                'domain' => $domain,
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'started_at' => now(),
                'expires_at' => $ttlSeconds ? now()->addSeconds($ttlSeconds) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('domain_idempotency_keys')->where('id', $id)->first();
        });
    }

    public function complete(string $id, int $responseCode, ?string $responseBody = null): void
    {
        DB::table('domain_idempotency_keys')->where('id', $id)->update([
            'status' => 'completed',
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function fail(string $id, int $responseCode, ?string $responseBody = null): void
    {
        DB::table('domain_idempotency_keys')->where('id', $id)->update([
            'status' => 'failed',
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
