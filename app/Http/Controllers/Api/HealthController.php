<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache'    => $this->checkCache(),
            'storage'  => $this->checkStorage(),
            'queue'    => $this->checkQueue(),
        ];

        $healthy = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json([
            'status'    => $healthy ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks'    => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('SELECT 1');
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health:ping:' . time();
            Cache::put($key, 1, 5);
            $hit = Cache::get($key) === 1;
            Cache::forget($key);
            return ['ok' => $hit];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        try {
            $path = 'health-check.tmp';
            Storage::put($path, '1');
            $ok = Storage::exists($path);
            Storage::delete($path);
            return ['ok' => $ok];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            $size = Queue::size();
            return ['ok' => true, 'pending' => $size];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
