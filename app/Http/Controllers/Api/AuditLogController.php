<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($builder) use ($search) {
                $builder->whereLikeInsensitive('action', $search)
                    ->orWhereLikeInsensitive('entity', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->whereLikeInsensitive('first_name', $search)
                            ->orWhereLikeInsensitive('last_name', $search)
                            ->orWhereLikeInsensitive('email', $search);
                    });

                if (Str::isUuid($search)) {
                    $builder->orWhere('entity_id', $search);
                }
            });
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $entity = $request->query('entity', $request->query('model'));
        if ($entity) {
            $query->whereLikeInsensitive('entity', $entity);
        }

        if ($request->filled('date_from')) {
            $query->where('timestamp', '>=', Carbon::parse($request->date_from)->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('timestamp', '<=', Carbon::parse($request->date_to)->endOfDay());
        }

        $perPage = min(max((int) $request->get('per_page', 5), 5), 200);
        $logs = $query
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->paginate($perPage);

        $entityOptions = AuditLog::query()
            ->whereNotNull('entity')
            ->distinct()
            ->orderBy('entity')
            ->pluck('entity')
            ->map(fn (string $entity) => [
                'id' => $entity,
                'name' => Str::headline(class_basename($entity)),
            ])
            ->values();
        $userOptions = User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->select('user_id'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name) ?: $user->email,
                'description' => $user->email,
            ]);

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
            'filter_options' => [
                'entities' => $entityOptions,
                'users' => $userOptions,
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
