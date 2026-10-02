<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\User;

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

    public function manageCacheClear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clear_laravel' => ['required', 'boolean'],
            'clear_portal' => ['required', 'boolean'],
            'scope' => ['required', 'in:all,selected'],
            'user_ids' => ['required_if:scope,selected', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['uuid', 'exists:users,id'],
            'clear_storage' => ['sometimes', 'boolean'],
        ]);

        abort_unless($data['clear_laravel'] || $data['clear_portal'], 422, 'Select at least one platform.');
        abort_if(($data['clear_storage'] ?? false) && !$data['clear_portal'], 422, 'Storage clearing is available for portal clients only.');

        if ($data['clear_laravel']) {
            Artisan::call('optimize:clear');
        }

        $requestCount = 0;
        if ($data['clear_portal']) {
            $userIds = $data['scope'] === 'all' ? [null] : array_values(array_unique($data['user_ids']));
            foreach ($userIds as $userId) {
                DB::table('client_cache_clear_requests')->insert([
                    'user_id' => $userId,
                    'clear_storage' => (bool) ($data['clear_storage'] ?? false),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $requestCount++;
            }
        }

        Log::info('Admin requested cache clearing', [
            'actor_id' => $request->user()?->id,
            'clear_laravel' => (bool) $data['clear_laravel'],
            'clear_portal' => (bool) $data['clear_portal'],
            'scope' => $data['scope'],
            'user_count' => $data['scope'] === 'all' ? 'all' : count($data['user_ids'] ?? []),
            'clear_storage' => (bool) ($data['clear_storage'] ?? false),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Selected cache clear has been queued.',
            'data' => ['portal_requests' => $requestCount],
        ]);
    }

    public function cacheClearUserOptions(): JsonResponse
    {
        $users = User::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(500)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (User $user) => [
                'id' => (string) $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                'email' => $user->email,
            ]);

        return response()->json(['status' => 'success', 'data' => $users]);
    }

    public function pendingClientCacheClears(Request $request): JsonResponse
    {
        $globalAfter = max(0, (int) $request->query('global_after', 0));
        $userAfter = max(0, (int) $request->query('user_after', 0));
        $userId = (string) $request->user()->id;

        $clears = DB::table('client_cache_clear_requests')
            ->where(function ($query) use ($globalAfter, $userAfter, $userId) {
                $query->where(fn ($q) => $q->whereNull('user_id')->where('id', '>', $globalAfter))
                    ->orWhere(fn ($q) => $q->where('user_id', $userId)->where('id', '>', $userAfter));
            })
            ->orderBy('id')
            ->get(['id', 'user_id', 'clear_storage']);

        return response()->json(['status' => 'success', 'data' => $clears]);
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
