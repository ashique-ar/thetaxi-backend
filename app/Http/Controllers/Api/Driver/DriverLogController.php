<?php
// app/Http/Controllers/Api/DriverLogController.php
namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Driver\DriverLog;
use App\Http\Requests\Driver\DriverLog\CreateDriverLogRequest;
use App\Http\Requests\Driver\DriverLog\UpdateDriverLogRequest;
use App\Http\Resources\Driver\DriverLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DriverLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:driver-logs.view')->only(['index', 'show', 'stats']);
        $this->middleware('permission:driver-logs.create')->only(['store']);
        $this->middleware('permission:driver-logs.edit')->only(['update', 'assign', 'submit', 'verify']);
        $this->middleware('permission:driver-logs.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = DriverLog::with(['driver', 'booking', 'createdBy']);

        if ($request->filled('driver_id')) {
            $q->where('driver_id', $request->driver_id);
        }

        if ($request->filled('booking_id')) {
            $q->where('booking_id', $request->booking_id);
        }

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = '%' . mb_strtolower(trim((string) $request->search)) . '%';
            $q->where(function ($query) use ($search): void {
                $query->whereRaw('LOWER(CAST(log_code AS TEXT)) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(particulars) LIKE ?', [$search]);
            });
        }

        $q->orderByDesc('created_at');

        return DriverLogResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateDriverLogRequest $request): JsonResponse
    {
        $data = $this->normalizeLogPayload($request->validated());
        $data['status'] = 'draft';
        $data['created_user_id'] = $request->user()->id;
        $data['assigned_by'] = $request->user()->id;
        $data['assigned_at'] = now();
        $log = DriverLog::create($data);
        return response()->json([
            'status' => 'success',
            'message' => 'Driver log created',
            'data' => new DriverLogResource($log)
        ], 201);
    }

    public function show(DriverLog $driverLog): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new DriverLogResource($driverLog)
        ]);
    }

    public function update(UpdateDriverLogRequest $request, DriverLog $driverLog): JsonResponse
    {
        abort_unless(in_array($driverLog->status, ['draft', 'rejected'], true), 409, 'Submitted or approved log sheets are locked.');
        $data = $this->normalizeLogPayload($request->validated(), $driverLog);
        $data['updated_user_id'] = $request->user()->id;
        $driverLog->update($data);
        return response()->json([
            'status' => 'success',
            'message' => 'Driver log updated',
            'data' => new DriverLogResource($driverLog)
        ]);
    }

    public function destroy(DriverLog $driverLog): JsonResponse
    {
        abort_unless(in_array($driverLog->status, ['draft', 'rejected'], true), 409, 'Submitted or approved log sheets are locked.');
        $driverLog->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Driver log deleted'
        ]);
    }

    public function assign(Request $request, DriverLog $driverLog): JsonResponse
    {
        abort_unless(in_array($driverLog->status, ['draft', 'rejected'], true), 409, 'Submitted or approved log sheets are locked.');
        $data = $request->validate([
            'driver_id' => 'required|exists:drivers,id',
        ]);

        $driverLog->update([
            'driver_id' => $data['driver_id'],
            'assigned_by' => $request->user()?->id,
            'assigned_at' => now(),
            'updated_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Driver assigned to log sheet',
            'data' => new DriverLogResource($driverLog->fresh(['driver', 'booking', 'createdBy'])),
        ]);
    }

    public function submit(Request $request, DriverLog $driverLog): JsonResponse
    {
        abort_unless(in_array($driverLog->status, ['draft', 'rejected'], true), 409, 'Only draft or rejected log sheets can be submitted.');
        $data = $request->validate([
            'correction_notes' => $driverLog->status === 'rejected'
                ? 'required|string|min:10|max:1000'
                : 'nullable|string|max:1000',
        ]);

        $driverLog->update([
            'status' => 'pending',
            'submitted_by' => $request->user()?->id,
            'submitted_at' => now(),
            'correction_notes' => $data['correction_notes'] ?? null,
            'revision_number' => $driverLog->revision_number + 1,
            'verified_by' => null,
            'verified_at' => null,
            'updated_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Log sheet submitted for review',
            'data' => new DriverLogResource($driverLog->fresh(['driver', 'booking', 'createdBy'])),
        ]);
    }

    public function verify(Request $request, DriverLog $driverLog): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:approved,rejected',
            'verification_status' => 'nullable|in:approved,rejected',
            'verification_notes' => 'nullable|string|max:1000',
        ]);

        abort_unless($driverLog->status === 'pending', 409, 'Only pending log sheets can be reviewed.');
        $status = $data['status'] ?? $data['verification_status'] ?? 'approved';
        if ($status === 'rejected') {
            validator($data, [
                'verification_notes' => 'required|string|min:10|max:1000',
            ])->validate();
        }

        $driverLog->update([
            'status' => $status,
            'verification_notes' => $data['verification_notes'] ?? null,
            'verified_by' => $request->user()?->id,
            'verified_at' => now(),
            'updated_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $driverLog->status === 'approved' ? 'Log sheet verified' : 'Log sheet rejected',
            'data' => new DriverLogResource($driverLog->fresh(['driver', 'booking', 'createdBy'])),
        ]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_sheets' => DriverLog::count(),
                'pending_review' => DriverLog::where('status', 'pending')->count(),
                'approved_today' => DriverLog::where('status', 'approved')
                    ->whereDate('updated_at', now()->toDateString())
                    ->count(),
                'total_allowances_paid' => 0,
                'average_km_per_trip' => round((float) DriverLog::whereNotNull('total_km')->avg('total_km'), 2),
                'average_hours_per_trip' => 0,
            ],
        ]);
    }

    private function normalizeLogPayload(array $data, ?DriverLog $existing = null): array
    {
        $startKm = $data['start_km'] ?? $existing?->start_km;
        $endKm = $data['end_km'] ?? $existing?->end_km;

        if (!array_key_exists('total_km', $data) && $startKm !== null && $endKm !== null) {
            $data['total_km'] = max((int) $endKm - (int) $startKm, 0);
        }

        $data['entry_source'] = $data['entry_source'] ?? $existing?->entry_source ?? 'paper_entry';

        return $data;
    }
}
