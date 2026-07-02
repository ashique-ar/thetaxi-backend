<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:system.view')->only(['index', 'show']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->with('user');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $entity = $request->query('entity', $request->query('model'));
        if ($entity) {
            $query->whereLikeInsensitive('entity', $entity);
        }

        if ($request->filled('date_from')) {
            $query->where('timestamp', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('timestamp', '<=', $request->date_to);
        }

        $logs = $query
            ->orderByDesc('timestamp')
            ->paginate((int) $request->get('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => $this->serializeLog($log))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'from' => $logs->firstItem(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'to' => $logs->lastItem(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load('user');

        return response()->json([
            'status' => 'success',
            'data' => $this->serializeLog($auditLog),
        ]);
    }

    private function serializeLog(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'action' => $log->action,
            'entity' => $log->entity,
            'entity_id' => $log->entity_id,
            'timestamp' => optional($log->timestamp)->toISOString(),
            'details' => $log->details,
            'created_at' => optional($log->created_at)->toISOString(),
            'updated_at' => optional($log->updated_at)->toISOString(),
            'user' => $log->user ? [
                'id' => $log->user->id,
                'first_name' => $log->user->first_name,
                'last_name' => $log->user->last_name,
                'email' => $log->user->email,
            ] : null,
        ];
    }
}
