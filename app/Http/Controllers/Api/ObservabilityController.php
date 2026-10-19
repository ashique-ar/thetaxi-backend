<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use App\Support\ObservabilitySanitizer;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverMobileDiagnosticReport;
use Illuminate\Support\Str;

class ObservabilityController extends Controller
{
    public function frontend(Request $request): JsonResponse
    {
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > 32768, 413);

        $data = $request->validate([
            'level' => ['required', Rule::in(['error', 'warning'])],
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:12000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'route' => ['nullable', 'string', 'max:1000'],
            'timestamp' => ['required', 'date'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
            'application_version' => ['nullable', 'string', 'max:100'],
            'http_status' => ['nullable', 'integer', 'between:0,599'],
            'failed_api_path' => ['nullable', 'string', 'max:2000'],
            'request_id' => ['nullable', 'string', 'max:100'],
        ]);
        $data = ObservabilitySanitizer::strings($data);
        $data['client_timestamp'] = $data['timestamp'];
        unset($data['timestamp']);

        Log::channel('loki_frontend')->log($data['level'], $data['message'], [
            ...$data,
            'project' => config('app.project_slug'),
            'project_display_name' => config('app.project_display_name'),
            'authenticated_user_id' => $request->user()?->id,
        ]);

        return response()->json(null, 202);
    }

    public function mobileDiagnostic(Request $request): JsonResponse
    {
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > 262144, 413);

        $driver = Driver::query()->where('user_id', $request->user()->id)->first();
        abort_unless($driver, 403, 'User is not registered as a driver.');

        $data = $request->validate([
            'report_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            'severity' => ['required', Rule::in(['fatal', 'severe', 'recoverable'])],
            'category' => ['required', Rule::in(['flutter', 'dart', 'platform', 'network', 'storage', 'tracking', 'trip_state'])],
            'fingerprint' => ['required', 'string', 'max:64'],
            'occurrence_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'app' => ['required', 'array'],
            'app.version' => ['nullable', 'string', 'max:100'],
            'app.build' => ['nullable', 'string', 'max:100'],
            'device' => ['required', 'array'],
            'device.platform' => ['nullable', 'string', 'max:40'],
            'device.os_version' => ['nullable', 'string', 'max:100'],
            'device.model' => ['nullable', 'string', 'max:120'],
            'context' => ['required', 'array', 'max:40'],
            'context.*' => ['nullable', 'string', 'max:100'],
            'exception' => ['required', 'array'],
            'exception.type' => ['required', 'string', 'max:200'],
            'exception.message' => ['required', 'string', 'max:2000'],
            'exception.stack' => ['nullable', 'string', 'max:32000'],
            'events' => ['present', 'array', 'max:200'],
            'events.*.at' => ['required', 'date'],
            'events.*.tag' => ['required', 'string', 'max:64'],
            'events.*.message' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = ObservabilitySanitizer::values($data);
        $exception = $data['exception'];
        $report = DriverMobileDiagnosticReport::query()->firstOrCreate(
            ['report_id' => $data['report_id']],
            [
                'driver_id' => $driver->id,
                'severity' => $data['severity'],
                'category' => $data['category'],
                'source' => $data['context']['route'] ?? null,
                'fingerprint' => $data['fingerprint'],
                'error_type' => $exception['type'],
                'error_summary' => $exception['message'],
                'context' => $data['context'],
                'events' => $data['events'],
                'occurred_at' => $data['occurred_at'],
            ],
        );

        if ($report->wasRecentlyCreated) {
            $level = $data['severity'] === 'recoverable' ? 'warning' : 'error';
            Log::channel('loki_mobile')->log($level, 'Driver mobile diagnostic report', [
                'event_type' => 'driver_mobile_diagnostic',
                'driver_id' => (string) $driver->id,
                'report_id' => $report->report_id,
                'severity' => $report->severity,
                'category' => $report->category,
                'fingerprint' => $report->fingerprint,
                'error_type' => $report->error_type,
                'error_summary' => $report->error_summary,
                'context' => $report->context,
                'events' => $report->events,
                'app' => $data['app'],
                'device' => $data['device'],
                'exception_stack' => $exception['stack'] ?? null,
                'occurred_at' => $report->occurred_at?->toIso8601String(),
            ]);
        }

        return response()->json(['status' => 'success'], 202);
    }
}
