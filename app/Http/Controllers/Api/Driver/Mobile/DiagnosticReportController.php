<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Support\ObservabilitySanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DiagnosticReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 262144, 413);

        if ($request->input('type') === 'app_logs') {
            return $this->storeAppLogs($request);
        }

        $data = $request->validate([
            'report_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            'severity' => ['required', Rule::in(['fatal', 'severe', 'recoverable'])],
            'category' => ['required', Rule::in(['flutter', 'dart', 'platform', 'network', 'storage', 'tracking', 'trip_state'])],
            'fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
            'occurrence_count' => ['required', 'integer', 'between:1,1000000'],
            'app' => ['required', 'array:version,build'],
            'app.version' => ['required', 'string', 'max:100'],
            'app.build' => ['required', 'string', 'max:100'],
            'device' => ['required', 'array:platform,os_version,model'],
            'device.platform' => ['required', 'string', 'max:32'],
            'device.os_version' => ['required', 'string', 'max:100'],
            'device.model' => ['required', 'string', 'max:100'],
            'context' => ['required', 'array', 'max:30'],
            'context.*' => ['string', 'max:256'],
            'exception' => ['required', 'array:type,message,stack'],
            'exception.type' => ['required', 'string', 'max:100'],
            'exception.message' => ['required', 'string', 'max:2000'],
            'exception.stack' => ['required', 'string', 'max:32000'],
            'events' => ['required', 'array', 'max:200'],
            'events.*' => ['required', 'array:at,tag,message'],
            'events.*.at' => ['required', 'date'],
            'events.*.tag' => ['required', 'string', 'max:64', 'regex:/^(?:\[[A-Z0-9_]+\])+$/i'],
            'events.*.message' => ['required', 'string', 'max:1000'],
        ]);

        $data = ObservabilitySanitizer::values($data);
        $level = match ($data['severity']) {
            'fatal' => 'critical',
            'severe' => 'error',
            default => 'warning',
        };

        Log::channel('loki_mobile')->log($level, 'Driver mobile diagnostic report', $data);

        return response()->json(null, 202);
    }

    private function storeAppLogs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['app_logs'])],
            'batch_id' => ['required', 'uuid'],
            'app' => ['required', 'array:version,build'],
            'app.version' => ['required', 'string', 'max:100'],
            'app.build' => ['required', 'string', 'max:100'],
            'device' => ['required', 'array:platform,os_version,model'],
            'device.platform' => ['required', 'string', 'max:32'],
            'device.os_version' => ['required', 'string', 'max:100'],
            'device.model' => ['required', 'string', 'max:100'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*' => ['required', 'array:at,level,tag,message'],
            'events.*.at' => ['required', 'date'],
            'events.*.level' => ['required', Rule::in(['debug', 'info', 'warning', 'error'])],
            'events.*.tag' => ['required', 'string', 'max:64'],
            'events.*.message' => ['required', 'string', 'max:1000'],
        ]);
        $data = ObservabilitySanitizer::values($data);
        $priorities = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        $level = 'debug';
        foreach ($data['events'] as $event) {
            if ($priorities[$event['level']] > $priorities[$level]) {
                $level = $event['level'];
            }
        }

        Log::channel('loki_mobile')->log($level, 'Driver mobile app log batch', $data);

        return response()->json(null, 202);
    }
}
