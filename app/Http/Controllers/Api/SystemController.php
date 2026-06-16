<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $database = 'error';
        }

        return response()->json([
            'status' => $database === 'ok' ? 'success' : 'error',
            'data' => [
                'app' => config('app.name'),
                'environment' => config('app.env'),
                'database' => $database,
                'timestamp' => now()->toIso8601String(),
            ],
        ], $database === 'ok' ? 200 : 503);
    }

    public function info(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'app' => config('app.name'),
                'environment' => config('app.env'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'timezone' => config('app.timezone'),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'period' => $request->get('period', 'daily'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function performance(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function clearCache(): JsonResponse
    {
        Artisan::call('optimize:clear');

        return response()->json([
            'status' => 'success',
            'message' => 'Application cache cleared',
        ]);
    }

    public function optimizeDatabase(): JsonResponse
    {
        Artisan::call('optimize:clear');

        return response()->json([
            'status' => 'success',
            'message' => 'Optimization completed',
        ]);
    }
}
