<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreCorporateBookingRequest;
use App\Models\Booking\Booking;
use App\Models\Corporate\CorporateEmployee;
use App\Services\CorporateBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateBookingController extends Controller
{
    protected CorporateBookingService $bookingService;

    public function __construct(CorporateBookingService $bookingService)
    {
        $this->bookingService = $bookingService;

        $this->middleware('permission:view_all_bookings')->only(['index', 'export']);
        $this->middleware('permission:create_bookings')->only(['store']);
        $this->middleware('permission:create_bookings_for_others')->only(['storeForEmployee']);
        $this->middleware('permission:view_reports')->only(['stats']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'department_id', 'division_id', 'date_from', 'date_to']);

        $bookings = $this->bookingService->getBookingsForCorporate(
            $request->corporate_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => ['bookings' => $bookings],
        ]);
    }

    public function store(StoreCorporateBookingRequest $request): JsonResponse
    {
        $employee = $request->attributes->get('corporate_employee');

        $booking = $this->bookingService->createBooking($employee, $request->validated());

        return response()->json([
            'status'  => 'success',
            'message' => 'Booking created successfully',
            'data'    => ['booking' => $booking],
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
            'data'    => ['booking' => $booking],
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
            'data'   => ['booking' => $booking],
        ]);
    }

    public function myBookings(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('corporate_employee');
        $filters = $request->only(['status', 'date_from', 'date_to']);

        $bookings = $this->bookingService->getBookingsForEmployee(
            $employee->user_id,
            $filters
        );

        return response()->json([
            'status' => 'success',
            'data'   => ['bookings' => $bookings],
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
}
