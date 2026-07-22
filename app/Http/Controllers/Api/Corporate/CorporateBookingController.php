<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreCorporateBookingRequest;
use App\Models\Booking\Booking;
use App\Models\Corporate\CorporateEmployee;
use App\Models\Corporate\CorporateDepartment;
use App\Models\Corporate\CorporateDivision;
use App\Models\AuditLog;
use App\Models\DriverAssignment;
use App\Services\CorporateBookingService;
use App\Services\BookingObservabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorporateBookingController extends Controller
{
    protected CorporateBookingService $bookingService;

    public function __construct(
        CorporateBookingService $bookingService,
        private readonly BookingObservabilityService $observability
    )
    {
        $this->bookingService = $bookingService;

        $this->middleware('permission:view_all_bookings')->only(['index', 'export']);
        $this->middleware('permission:create_bookings')->only(['store']);
        $this->middleware('permission:create_bookings_for_others')->only(['storeForEmployee']);
        $this->middleware('permission:view_reports')->only(['stats']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'department_id', 'division_id', 'date_from', 'date_to', 'search', 'page', 'per_page']);
        $this->validateCorporateFilters($request, $filters);
        $filters['can_view_payments'] = $this->canViewPayments($request);

        $bookings = $this->bookingService->getBookingsForCorporate(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => $bookings->items(),
            'meta'   => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
            ],
        ]);
    }

    public function store(StoreCorporateBookingRequest $request): JsonResponse
    {
        $employee = $request->attributes->get('corporate_employee');
        $selectedEmployeeId = $request->validated('employee_id');

        if (
            $selectedEmployeeId
            && $selectedEmployeeId !== $employee->id
            && !$request->user()->can('create_bookings_for_others')
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only create bookings for yourself.',
            ], 403);
        }

        $booking = $this->bookingService->createBooking($employee, $request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Booking created successfully',
            'data'    => $this->bookingService->getCorporateBookingDetails($booking, $this->canViewPayments($request), $this->bookingScope($request)),
        ], 201);
    }

    public function storeForEmployee(StoreCorporateBookingRequest $request): JsonResponse
    {
        $request->validate([
            'employee_id' => ['required', 'uuid'],
        ]);

        $coordinator = $request->attributes->get('corporate_employee');

        $targetEmployee = CorporateEmployee::where('corporate_id', $request->corporate_id)
            ->where('id', $request->employee_id)
            ->firstOrFail();

        $booking = $this->bookingService->createBookingForEmployee(
            $coordinator,
            $targetEmployee,
            $request->validated()
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Booking created for employee successfully',
            'data'    => $this->bookingService->getCorporateBookingDetails($booking, $this->canViewPayments($request), $this->bookingScope($request)),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $booking = Booking::where('corporate_account_id', $request->corporate_id)
            ->with(['customer', 'corporateDepartment', 'corporateDivision'])
            ->findOrFail($id);

        // Scope check: employees can only see their own bookings unless they have view_all_bookings
        $employee = $request->attributes->get('corporate_employee');
        $user = $request->user();

        if (!$user->can('view_all_bookings') && $booking->employee_id !== $employee->user_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'You do not have permission to view this booking.',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $this->bookingService->getCorporateBookingDetails($booking, $this->canViewPayments($request), $this->bookingScope($request)),
        ]);
    }

    public function liveProgress(Request $request, string $id): JsonResponse
    {
        $booking = $this->authorizedBooking($request, $id);
        $validated = $request->validate([
            'booking_item_id' => ['nullable', 'uuid'],
        ]);
        $bookingItems = $booking->bookingItems()->orderBy('trip_number')->orderBy('created_at')->get();
        $itemId = $validated['booking_item_id'] ?? null;
        if (! $itemId && $bookingItems->count() > 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'Select a trip before viewing live progress.',
            ], 422);
        }
        $selectedItem = $itemId
            ? $bookingItems->firstWhere('id', $itemId)
            : $bookingItems->first();
        if (! $selectedItem) {
            return response()->json([
                'status' => 'error',
                'message' => $itemId
                    ? 'Selected booking item does not belong to this booking.'
                    : 'This booking has no trip to track.',
            ], 422);
        }

        $tracking = $this->observability->trackingSummary($booking, (string) $selectedItem->id);
        $assignment = DriverAssignment::query()
            ->whereKey($tracking['assignment_id'] ?? null)
            ->first();

        if (! $assignment) {
            return response()->json(['status' => 'success', 'data' => [
                'booking_id' => $booking->id,
                'booking_item_id' => (string) $selectedItem->id,
                'available' => false,
                'reason' => 'not_assigned',
                'raw_tracking' => false,
                'customer_tracking_link' => 'unavailable_policy_not_configured',
            ]]);
        }

        $windowStart = Carbon::parse($assignment->confirmed_at ?? $assignment->assigned_from ?? $assignment->created_at)->subHours(2);
        $windowEnd = Carbon::parse(
            $assignment->trip_completed_at
                ?? $assignment->assigned_to
                ?? Carbon::parse($assignment->confirmed_at ?? $assignment->created_at)->addHours(48)
        )->addHours(2);
        $withinWindow = now()->between($windowStart, $windowEnd);
        $positionFresh = in_array($tracking['freshness'], ['live', 'delayed'], true)
            && is_array($tracking['active_position']);

        return response()->json(['status' => 'success', 'data' => [
            'booking_id' => $booking->id,
            'booking_item_id' => (string) $selectedItem->id,
            'available' => $withinWindow,
            'trip_phase' => $tracking['trip_phase'],
            'status' => $assignment->status,
            'position' => $withinWindow && $positionFresh ? [
                'latitude' => round((float) $tracking['active_position']['latitude'], 5),
                'longitude' => round((float) $tracking['active_position']['longitude'], 5),
                'updated_at' => $tracking['active_position']['recorded_at'],
            ] : null,
            'position_status' => ! $withinWindow ? 'outside_lifecycle_window' : ($positionFresh ? 'current' : 'unavailable_or_stale'),
            'expires_at' => $windowEnd->toIso8601String(),
            'raw_tracking' => false,
            'customer_tracking_link' => 'unavailable_policy_not_configured',
        ]]);
    }

    public function overrideContractualDistance(Request $request, string $id): JsonResponse
    {
        if (! $this->canViewPayments($request)) {
            return response()->json(['status' => 'error', 'message' => 'Pricing permission is required.'], 403);
        }

        $validated = $request->validate([
            'booking_item_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'origin_to_pickup_distance' => ['required', 'numeric', 'min:0'],
            'journey_distance' => ['required', 'numeric', 'min:0'],
            'dropoff_to_return_distance' => ['required', 'numeric', 'min:0'],
            'total_billable_distance' => ['required', 'numeric', 'min:0'],
            'movement_charge' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $expectedTotal = (float) $validated['origin_to_pickup_distance']
            + (float) $validated['journey_distance']
            + (float) $validated['dropoff_to_return_distance'];
        if (abs($expectedTotal - (float) $validated['total_billable_distance']) > 0.01) {
            return response()->json(['status' => 'error', 'message' => 'Total billable distance must equal the three contractual legs.'], 422);
        }

        $booking = $this->authorizedBooking($request, $id);
        if (! in_array((string) $booking->status, ['pending_approval', 'approved', 'confirmed', 'allocated'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Contractual pricing can no longer be overridden at this lifecycle stage.'], 422);
        }
        $item = $validated['booking_item_id']
            ? $booking->bookingItems()->whereKey($validated['booking_item_id'])->firstOrFail()
            : $booking->bookingItems()->orderBy('created_at')->first();
        $before = $item?->pricing_breakdown ?: $booking->pricing_snapshot;
        if (! is_array($before) || data_get($before, 'base_pricing.distance_policy.coordinate_source', data_get($before, 'distance_policy.coordinate_source')) !== 'corporate_distance_policy') {
            return response()->json(['status' => 'error', 'message' => 'No contractual distance snapshot is available for review.'], 422);
        }

        $after = $this->applyContractualDistanceOverrideToSnapshot($before, $validated, (string) $request->user()->id);
        DB::transaction(function () use ($booking, $item, $before, $after, $validated, $request) {
            if ($item) {
                $item->pricing_breakdown = $after;
                if (isset($validated['total_amount'])) {
                    $item->total_price = $validated['total_amount'];
                }
                $item->save();
            }
            $booking->pricing_snapshot = $after;
            if (isset($validated['total_amount'])) {
                $booking->total_estimated = $validated['total_amount'];
            }
            $booking->save();
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'corporate_contractual_distance_overridden',
                'entity' => 'Booking',
                'entity_id' => $booking->id,
                'timestamp' => now(),
                'details' => [
                    'corporate_id' => $booking->corporate_account_id,
                    'booking_item_id' => $item?->id,
                    'reason' => $validated['reason'],
                    'before' => data_get($before, 'base_pricing.distance_details', data_get($before, 'distance_details')),
                    'after' => data_get($after, 'base_pricing.distance_details', data_get($after, 'distance_details')),
                ],
            ]);
        });

        return response()->json(['status' => 'success', 'message' => 'Contractual pricing decision recorded.', 'data' => [
            'contractual_distance_breakdown' => app(\App\Services\ContractualDistanceSnapshotProjector::class)->project($after),
        ]]);
    }

    private function applyContractualDistanceOverrideToSnapshot(array $snapshot, array $values, string $actorId): array
    {
        $prefix = isset($snapshot['base_pricing']) ? 'base_pricing.' : '';
        $before = data_get($snapshot, $prefix.'distance_details', []);
        $distances = [
            'origin_to_pickup_distance' => (float) $values['origin_to_pickup_distance'],
            'journey_distance' => (float) $values['journey_distance'],
            'dropoff_to_return_distance' => (float) $values['dropoff_to_return_distance'],
            'total_billable_distance' => (float) $values['total_billable_distance'],
        ];
        data_set($snapshot, $prefix.'distance_details', array_replace(is_array($before) ? $before : [], $distances));
        data_set($snapshot, $prefix.'contractual_movement_charge', isset($values['movement_charge']) ? (float) $values['movement_charge'] : null);
        $history = data_get($snapshot, $prefix.'manual_override_history', []);
        $history[] = [
            'actor_id' => $actorId,
            'reason' => $values['reason'],
            'overridden_at' => now()->toIso8601String(),
            'before' => $before,
            'after' => $distances,
        ];
        data_set($snapshot, $prefix.'manual_override_history', $history);
        return $snapshot;
    }

    private function authorizedBooking(Request $request, string $id): Booking
    {
        $booking = Booking::where('corporate_account_id', $request->corporate_id)->findOrFail($id);
        $employee = $request->attributes->get('corporate_employee');
        if (! $request->user()->can('view_all_bookings') && $booking->employee_id !== $employee?->user_id) {
            abort(403, 'You do not have permission to view this booking.');
        }
        return $booking;
    }

    public function cancelRecurring(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'in:single,future'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $booking = Booking::where('corporate_account_id', $request->corporate_id)
            ->findOrFail($id);

        $employee = $request->attributes->get('corporate_employee');
        $user = $request->user();

        if (!$user->can('view_all_bookings') && $booking->employee_id !== $employee->user_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'You do not have permission to cancel this booking.',
            ], 403);
        }

        $result = $this->bookingService->cancelRecurringBooking(
            $booking,
            $validated['scope'],
            (string) $user->id,
            $validated['reason'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'message' => $validated['scope'] === 'future'
                ? 'Future recurring bookings cancelled successfully'
                : 'Recurring occurrence cancelled successfully',
            'data' => $result,
        ]);
    }

    public function myBookings(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('corporate_employee');
        $filters = $request->only(['status', 'date_from', 'date_to', 'search', 'page', 'per_page']);
        $filters['can_view_payments'] = $this->canViewPayments($request);

        $bookings = $this->bookingService->getBookingsForEmployee(
            $employee->user_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => $bookings->items(),
            'meta'   => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
            ],
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'department_id', 'division_id', 'date_from', 'date_to']);

        $filePath = $this->bookingService->exportBookings(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => ['file_path' => $filePath],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $filters = $request->only(['date_from', 'date_to']);

        $stats = $this->bookingService->getBookingSummaryStats(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => ['stats' => $stats],
        ]);
    }

    private function canViewPayments(Request $request): bool
    {
        $user = $request->user();
        $employee = $request->attributes->get('corporate_employee');
        $corporate = $employee?->corporate;

        return $user->can('view_payments')
            || ($user->can('create_bookings_for_others') && (bool) $corporate?->coordinator_can_view_payments);
    }

    private function bookingScope(Request $request): string
    {
        return $request->user()->can('view_all_bookings') ? 'company' : 'employee';
    }

    private function validateCorporateFilters(Request $request, array $filters): void
    {
        if (!empty($filters['department_id'])) {
            CorporateDepartment::where('corporate_id', $request->corporate_id)
                ->findOrFail($filters['department_id']);
        }

        if (!empty($filters['division_id'])) {
            CorporateDivision::whereHas(
                'department',
                fn ($query) => $query->where('corporate_id', $request->corporate_id)
            )->findOrFail($filters['division_id']);
        }
    }
}
