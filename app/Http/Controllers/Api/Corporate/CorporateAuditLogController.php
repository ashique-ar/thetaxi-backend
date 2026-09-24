<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking\Booking;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\Corporate\CorporateEmployee;
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

        $items = collect($logs->items());
        $departmentIds = $items->flatMap(fn (AuditLog $log) => [
            data_get($log->details, 'department_id'),
            data_get($log->details, 'corporate_department_id'),
            class_basename($log->entity) === 'CorporateDepartment' ? $log->entity_id : null,
        ])->filter(fn ($id) => is_string($id) && Str::isUuid($id))->unique()->values();
        $divisionIds = $items->flatMap(fn (AuditLog $log) => [
            data_get($log->details, 'division_id'),
            data_get($log->details, 'corporate_division_id'),
            class_basename($log->entity) === 'CorporateDivision' ? $log->entity_id : null,
        ])->filter(fn ($id) => is_string($id) && Str::isUuid($id))->unique()->values();
        $employeeIds = $items->filter(fn (AuditLog $log) => class_basename($log->entity) === 'CorporateEmployee')
            ->pluck('entity_id')->filter(fn ($id) => is_string($id) && Str::isUuid($id))->unique()->values();
        $bookingIds = $items->flatMap(fn (AuditLog $log) => [
            data_get($log->details, 'booking_id'),
            class_basename($log->entity) === 'Booking' ? $log->entity_id : null,
        ])->filter(fn ($id) => is_string($id) && Str::isUuid($id))->unique()->values();

        $departments = CorporateDepartment::query()
            ->where('corporate_id', $corporateId)
            ->whereIn('id', $departmentIds)
            ->pluck('name', 'id');
        $divisions = CorporateDivision::query()
            ->whereIn('id', $divisionIds)
            ->whereHas('department', fn ($builder) => $builder->where('corporate_id', $corporateId))
            ->pluck('name', 'id');
        $employees = CorporateEmployee::query()
            ->where('corporate_id', $corporateId)
            ->whereIn('id', $employeeIds)
            ->with('user:id,first_name,last_name,email')
            ->get(['id', 'user_id', 'employee_code'])
            ->keyBy('id');
        $bookings = Booking::query()
            ->where('corporate_account_id', $corporateId)
            ->whereIn('id', $bookingIds)
            ->pluck('booking_number', 'id');

        $data = $items->map(function (AuditLog $log) use ($departments, $divisions, $employees, $bookings) {
            $details = is_array($log->details) ? $log->details : [];
            $departmentId = $details['department_id'] ?? $details['corporate_department_id'] ?? null;
            $divisionId = $details['division_id'] ?? $details['corporate_division_id'] ?? null;
            $bookingId = $details['booking_id'] ?? null;
            unset($details['department_id'], $details['corporate_department_id']);
            unset($details['division_id'], $details['corporate_division_id']);
            unset($details['booking_id']);

            if ($departmentId && $departments->has($departmentId)) {
                $details['department_name'] = $departments->get($departmentId);
            }
            if ($divisionId && $divisions->has($divisionId)) {
                $details['division_name'] = $divisions->get($divisionId);
            }
            if ($bookingId && $bookings->has($bookingId) && empty($details['booking_number'])) {
                $details['booking_number'] = $bookings->get($bookingId);
            }
            if (!empty($details['role'])) {
                $details['role'] = $this->roleDisplayName((string) $details['role']);
            }

            $entityType = class_basename($log->entity);
            $snapshotName = trim(implode(' ', array_filter([
                $details['first_name'] ?? null,
                $details['last_name'] ?? null,
            ])));
            $entityDisplay = match ($entityType) {
                'CorporateEmployee' => $snapshotName
                    ?: trim((string) ($employees->get($log->entity_id)?->user?->first_name ?? '').' '.(string) ($employees->get($log->entity_id)?->user?->last_name ?? ''))
                    ?: ($employees->get($log->entity_id)?->employee_code ?: ($details['email'] ?? null)),
                'CorporateDepartment' => $departments->get($log->entity_id) ?: ($details['name'] ?? null),
                'CorporateDivision' => $divisions->get($log->entity_id) ?: ($details['name'] ?? null),
                'Booking' => $details['booking_number'] ?? $bookings->get($log->entity_id),
                'Corporate' => $details['name'] ?? null,
                default => $details['name'] ?? $details['booking_number'] ?? null,
            };

            return [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'action' => $log->action,
                'entity' => $log->entity,
                'entity_display' => $entityDisplay,
                'timestamp' => $log->timestamp,
                'details' => $details,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'first_name' => $log->user->first_name,
                    'last_name' => $log->user->last_name,
                ] : null,
            ];
        });

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
            'data' => $data,
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

    private function roleDisplayName(string $role): string
    {
        return match ($role) {
            'Corporate_Master_Admin' => 'Company Administrator',
            'Corporate_Employee' => 'Employee',
            'Transport_Coordinator' => 'Travel Coordinator',
            'Approval_Manager' => 'Approval Manager',
            default => Str::headline(str_ireplace('corporate', 'company', $role)),
        };
    }
}
