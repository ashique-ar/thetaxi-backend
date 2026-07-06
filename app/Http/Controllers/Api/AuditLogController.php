<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:system.view')->only(['index', 'show']);
    }

    public function index(Request $request): JsonResponse
    {
        $auditLogs = DB::table('audit_logs')->selectRaw("
            id::text as id,
            user_id::text as user_id,
            action,
            entity,
            entity_id::text as entity_id,
            timestamp,
            details::text as details,
            created_at
        ");
        $activityLogs = DB::table(config('activitylog.table_name', 'activity_log'))->selectRaw("
            id::text as id,
            causer_id::text as user_id,
            COALESCE(event, description) as action,
            subject_type as entity,
            subject_id::text as entity_id,
            created_at as timestamp,
            properties::text as details,
            created_at
        ");
        $allLogs = $auditLogs->unionAll($activityLogs);
        $query = DB::query()->fromSub($allLogs, 'all_audit_logs');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($builder) use ($search) {
                $matchingUserIds = User::query()
                    ->whereLikeInsensitive('first_name', $search)
                    ->orWhereLikeInsensitive('last_name', $search)
                    ->orWhereLikeInsensitive('email', $search)
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id);

                $builder->whereRaw('LOWER(action) LIKE ?', ['%'.Str::lower($search).'%'])
                    ->orWhereRaw('LOWER(entity) LIKE ?', ['%'.Str::lower($search).'%'])
                    ->orWhereIn('user_id', $matchingUserIds);

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
            $query->where('entity', $entity);
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

        $optionsQuery = DB::query()->fromSub(
            DB::table('audit_logs')->selectRaw('entity, user_id::text as user_id')
                ->unionAll(
                    DB::table(config('activitylog.table_name', 'activity_log'))
                        ->selectRaw('subject_type as entity, causer_id::text as user_id')
                ),
            'audit_options'
        );
        $entityOptions = (clone $optionsQuery)
            ->whereNotNull('entity')
            ->distinct()
            ->orderBy('entity')
            ->pluck('entity')
            ->map(fn (string $entity) => [
                'id' => $entity,
                'name' => Str::headline(class_basename($entity)),
            ])
            ->values();
        $auditedUserIds = (clone $optionsQuery)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');
        $userOptions = User::query()
            ->whereIn('id', $auditedUserIds)
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
            'data' => $this->serializeUnifiedLogs($logs->getCollection()),
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

    private function serializeUnifiedLogs($logs)
    {
        $users = User::query()
            ->whereIn('id', $logs->pluck('user_id')->filter()->unique())
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->keyBy(fn (User $user) => (string) $user->id);

        return $logs->map(function ($log) use ($users) {
            $user = $log->user_id ? $users->get((string) $log->user_id) : null;

            return [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'action' => $log->action,
                'entity' => class_basename($log->entity),
                'entity_id' => $log->entity_id,
                'timestamp' => Carbon::parse($log->timestamp)->toISOString(),
                'details' => $log->details ? json_decode($log->details, true) : null,
                'created_at' => Carbon::parse($log->created_at)->toISOString(),
                'user' => $user ? [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                ] : null,
            ];
        })->values();
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
