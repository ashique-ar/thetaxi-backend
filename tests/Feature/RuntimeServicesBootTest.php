<?php

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

it('round-trips an isolated local storage probe', function (): void {
    $path = 'release-readiness/runtime-storage-probe.txt';
    $contents = 'thetaxi-runtime-storage-ready';

    try {
        expect(Storage::disk('local')->put($path, $contents))->toBeTrue()
            ->and(Storage::disk('local')->get($path))->toBe($contents);
    } finally {
        Storage::disk('local')->delete($path);
    }

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('connects to the explicitly enabled isolated Redis service', function (): void {
    if (getenv('REDIS_RUNTIME_SMOKE') !== 'true') {
        $this->markTestSkipped('Set REDIS_RUNTIME_SMOKE=true only for an isolated local or CI Redis service.');
    }

    $response = Redis::connection()->ping();

    expect($response === true || str_contains(strtolower((string) $response), 'pong'))->toBeTrue();
});
