<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CorporateAuditLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_audit_log');
    }

    public function index(Request $request): JsonResponse
    {
        $corporateId = $request->corporate_id;

        $baseQuery = AuditLog::where(function ($q) use ($corporateId) {
            // Audit logs related to this corporate's entities
            $q->where(function ($inner) use ($corporateId) {
                $inner->where('entity', 'Corporate')
                      ->where('entity_id', $corporateId);
            })
            ->orWhere(function ($inner) use ($corporateId) {
                $inner->whereIn('entity', [
                    'CorporateDepartment',
                    'CorporateDivision',
                    'CorporateEmployee',
                ])
                ->where('details->corporate_id', $corporateId);
            })
            ->orWhere(function ($inner) use ($corporateId) {
                $inner->whereIn('entity', ['Booking'])
                      ->where('details->corporate_id', $corporateId);
            });
        });
        $query = clone $baseQuery;

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('entity')) {
            $query->whereLikeInsensitive('entity', $request->entity);
        }

        if ($request->filled('date_from')) {
            $query->where('timestamp', '>=', Carbon::parse($request->date_from)->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('timestamp', '<=', Carbon::parse($request->date_to)->endOfDay());
        }

        $perPage = min(max((int) $request->get('per_page', 5), 5), 200);
        $logs = $query->with('user')
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->paginate($perPage);

        $entityOptions = (clone $baseQuery)
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
            ->whereIn('id', (clone $baseQuery)->whereNotNull('user_id')->select('user_id'))
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
            'data' => $logs->items(),
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
}
