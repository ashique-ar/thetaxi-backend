<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Models\DriverAssignment;
use App\Services\Driver\DriverAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HireRecoveryController extends Controller
{
    public function store(Request $request, DriverAuthService $authService): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'assignment_id' => ['required', 'uuid'],
            'event_type' => ['required', Rule::in(['recovery_attempt', 'recovery_outcome'])],
            'trip_mode' => ['required', Rule::in(['fixed_route', 'open_package'])],
            'reason' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'outcome' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'prior_state' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'new_state' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'cause' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'error_code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'connection_state' => ['nullable', Rule::in(['connected', 'weak', 'disconnected', 'unknown'])],
            'device_uuid' => ['nullable', 'uuid'],
            'app_version' => ['nullable', 'string', 'max:100'],
            'app_build' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['required', 'date'],
        ]);

        $driver = $authService->getDriver($request->user());
        abort_unless($driver, 403, 'User is not registered as a driver');
        $assignment = DriverAssignment::query()
            ->whereKey($data['assignment_id'])
            ->where('driver_id', $driver->id)
            ->firstOrFail();

        DB::table('driver_hire_recovery_events')->insertOrIgnore([
            'event_id' => $data['event_id'],
            'assignment_id' => $assignment->id,
            'driver_id' => $driver->id,
            'event_type' => $data['event_type'],
            'trip_mode' => $data['trip_mode'],
            'reason' => $data['reason'],
            'outcome' => $data['outcome'],
            'prior_state' => $data['prior_state'] ?? null,
            'new_state' => $data['new_state'] ?? null,
            'cause' => $data['cause'] ?? null,
            'error_code' => $data['error_code'] ?? null,
            'connection_state' => $data['connection_state'] ?? 'unknown',
            'device_uuid' => $data['device_uuid'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'app_build' => $data['app_build'] ?? null,
            'correlation_id' => $data['event_id'],
            'occurred_at' => $data['occurred_at'],
            'received_at' => now('UTC'),
        ]);

        return response()->json(['status' => 'success'], 202);
    }
}
