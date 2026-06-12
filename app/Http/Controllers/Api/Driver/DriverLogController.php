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
        $this->middleware('permission:driver-logs.view')->only(['index', 'show']);
        $this->middleware('permission:driver-logs.create')->only(['store']);
        $this->middleware('permission:driver-logs.edit')->only(['update']);
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

        $q->orderByDesc('created_at');

        return DriverLogResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateDriverLogRequest $request): JsonResponse
    {
        $data = $this->normalizeLogPayload($request->validated());
        $data['created_user_id'] = $request->user()->id;
        $log = DriverLog::create($data);
        return response()->json([
            'status' => 'success',
            'message' => 'Driver log created',
            'data' => ['log' => new DriverLogResource($log)]
        ], 201);
    }

    public function show(DriverLog $driverLog): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['log' => new DriverLogResource($driverLog)]
        ]);
    }

    public function update(UpdateDriverLogRequest $request, DriverLog $driverLog): JsonResponse
    {
        $data = $this->normalizeLogPayload($request->validated(), $driverLog);
        $data['updated_user_id'] = $request->user()->id;
        $driverLog->update($data);
        return response()->json([
            'status' => 'success',
            'message' => 'Driver log updated',
            'data' => ['log' => new DriverLogResource($driverLog)]
        ]);
    }

    public function destroy(DriverLog $driverLog): JsonResponse
    {
        $driverLog->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Driver log deleted'
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
