<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        $start = microtime(true);
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
        ];

        $healthy = collect($checks)->every(fn ($check) => $check['ok']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'status' => $healthy ? 'ok' : 'degraded',
                'timestamp' => now()->toIso8601String(),
                'response_time_ms' => round((microtime(true) - $start) * 1000, 2),
                'checks' => $checks,
            ],
        ], $healthy ? 200 : 503);
    }

    public function info(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'app_name' => config('app.name'),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'timezone' => config('app.timezone'),
                'cache_driver' => config('cache.default'),
                'queue_connection' => config('queue.default'),
                'filesystem_disk' => config('filesystems.default'),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $performance = $this->buildPerformanceMetrics();

        return response()->json([
            'status' => 'success',
            'data' => [
                'health' => [
                    'status' => $this->isDatabaseAvailable() ? 'ok' : 'degraded',
                    'database_performance' => $performance['database_query_time'] <= 100 ? 95 : max(10, 100 - $performance['database_query_time']),
                    'api_response_time_ms' => $performance['average_response_time'],
                    'api_response_score' => $this->scoreResponseTime($performance['average_response_time']),
                    'uptime_percentage' => null,
                ],
                'performance' => $performance,
                'cache' => $this->buildCacheStats(),
            ],
        ]);
    }

    public function performance(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'metrics' => $this->buildPerformanceMetrics(),
                'cache' => $this->buildCacheStats(),
                'recommendations' => $this->buildRecommendations(),
            ],
        ]);
    }

    public function clearCache(): JsonResponse
    {
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        return response()->json([
            'status' => 'success',
            'message' => 'Application caches cleared successfully.',
        ]);
    }

    public function optimizeDatabase(): JsonResponse
    {
        DB::statement('SELECT 1');

        return response()->json([
            'status' => 'success',
            'message' => 'Database connection verified successfully.',
        ]);
    }

    private function buildPerformanceMetrics(): array
    {
        $queryStart = microtime(true);
        DB::select('SELECT 1');
        $databaseQueryTime = round((microtime(true) - $queryStart) * 1000, 2);

        $storage = $this->getStorageUsage();
        $memoryLimit = ini_get('memory_limit') ?: '128M';

        return [
            'cache_hit_rate' => null,
            'average_response_time' => $databaseQueryTime,
            'page_load_times' => [
                'api_health' => round($databaseQueryTime / 1000, 3),
            ],
            'database_query_time' => $databaseQueryTime,
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => $memoryLimit,
            ],
            'storage_usage' => $storage,
        ];
    }

    private function buildCacheStats(): array
    {
        return [
            'total_keys' => null,
            'total_size' => 'Unavailable',
            'hit_rate' => null,
            'miss_rate' => null,
            'top_keys' => [],
            'driver' => config('cache.default'),
        ];
    }

    private function buildRecommendations(): array
    {
        $recommendations = [];

        if ((bool) config('app.debug')) {
            $recommendations[] = [
                'type' => 'code',
                'priority' => 'high',
                'title' => 'Disable debug mode outside development',
                'description' => 'APP_DEBUG is enabled. Disable it in staging and production to reduce information exposure and overhead.',
                'impact' => 'Improves security and runtime behavior',
                'action' => 'Set APP_DEBUG=false',
            ];
        }

        if (config('cache.default') === 'array') {
            $recommendations[] = [
                'type' => 'cache',
                'priority' => 'medium',
                'title' => 'Use a persistent cache driver',
                'description' => 'The array cache driver does not persist between requests.',
                'impact' => 'Improves repeated API and page response times',
                'action' => 'Configure Redis, database, or file cache',
            ];
        }

        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'query',
                'priority' => 'low',
                'title' => 'Monitor slow queries',
                'description' => 'Database connectivity is healthy. Continue monitoring slow query logs as data volume grows.',
                'impact' => 'Keeps report and dashboard screens responsive',
                'action' => 'Review database slow query logs',
            ];
        }

        return $recommendations;
    }

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            return ['ok' => true, 'response_time_ms' => round((microtime(true) - $start) * 1000, 2)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'system:health:' . uniqid('', true);
            Cache::put($key, 1, 5);
            $ok = Cache::get($key) === 1;
            Cache::forget($key);
            return ['ok' => $ok, 'driver' => config('cache.default')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        try {
            $path = 'system-health.tmp';
            Storage::put($path, '1');
            $ok = Storage::exists($path);
            Storage::delete($path);
            return ['ok' => $ok, 'disk' => config('filesystems.default')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            return ['ok' => true, 'pending' => Queue::size(), 'connection' => config('queue.default')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function isDatabaseAvailable(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function scoreResponseTime(float $time): int
    {
        if ($time <= 200) {
            return 95;
        }

        if ($time <= 500) {
            return 75;
        }

        return max(10, 100 - (int) min(90, $time / 20));
    }

    private function getStorageUsage(): array
    {
        $path = storage_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        $used = $total && $free ? $total - $free : null;

        return [
            'total' => $total ? $this->formatBytes($total) : 'Unavailable',
            'used' => $used ? $this->formatBytes($used) : 'Unavailable',
            'free' => $free ? $this->formatBytes($free) : 'Unavailable',
            'percentage' => $total && $used ? round(($used / $total) * 100, 2) : 0,
        ];
    }

    private function formatBytes(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2) . ' ' . $units[$index];
    }
}
