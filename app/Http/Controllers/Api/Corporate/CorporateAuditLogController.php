<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateAuditLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage_employees');
    }

    public function index(Request $request): JsonResponse
    {
        $corporateId = $request->corporate_id;

        $query = AuditLog::where(function ($q) use ($corporateId) {
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
                ->whereRaw("JSON_EXTRACT(details, '$.corporate_id') = ?", [$corporateId]);
            })
            ->orWhere(function ($inner) use ($corporateId) {
                $inner->whereIn('entity', ['Booking'])
                      ->whereRaw("JSON_EXTRACT(details, '$.corporate_id') = ?", [$corporateId]);
            });
        });

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('entity')) {
            $query->where('entity', $request->entity);
        }

        if ($request->filled('date_from')) {
            $query->where('timestamp', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('timestamp', '<=', $request->date_to);
        }

        $perPage = (int) $request->get('per_page', 15);
        $logs = $query->with('user')
            ->orderByDesc('timestamp')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => ['audit_logs' => $logs],
        ]);
    }
}
