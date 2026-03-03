<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreCorporateBookingRequest;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Services\CorporateBookingService;
use Illuminate\Http\JsonResponse;

class AdminCorporateBookingController extends Controller
{
    protected CorporateBookingService $bookingService;

    public function __construct(CorporateBookingService $bookingService)
    {
        $this->bookingService = $bookingService;
        $this->middleware('permission:bookings.create|corporates.manage');
    }

    public function storeForEmployee(StoreCorporateBookingRequest $request, Corporate $corporate): JsonResponse
    {
        $request->validate([
            'employee_id' => ['required', 'uuid'],
        ]);

        $targetEmployee = CorporateEmployee::where('corporate_id', $corporate->id)
            ->where('id', $request->employee_id)
            ->firstOrFail();

        // Get the admin user's CorporateEmployee record for this corporate
        $adminCoordinator = CorporateEmployee::where('corporate_id', $corporate->id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $booking = $this->bookingService->createBookingForEmployee(
            $adminCoordinator,
            $targetEmployee,
            $request->validated()
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Booking created for employee successfully',
            'data'    => ['booking' => $booking],
        ], 201);
    }
}
