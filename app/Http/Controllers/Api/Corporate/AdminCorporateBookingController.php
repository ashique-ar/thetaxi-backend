<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Corporate\StoreCorporateBookingRequest;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateEmployee;
use App\Services\CorporateBookingService;
use App\Services\CorporateBookingSubmissionService;
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

        $submission = app(CorporateBookingSubmissionService::class)->create(
            (string) $corporate->id,
            (string) $request->user()->id,
            $request->validated(),
            fn (array $data) => $this->bookingService->createBookingForEmployee(
                $adminCoordinator,
                $targetEmployee,
                $data,
            ),
        );
        $booking = $submission['booking'];

        return response()->json([
            'status'  => 'success',
            'message' => $submission['replayed'] ? 'This booking was already created.' : 'Booking created for employee successfully',
            'data'    => ['booking' => $booking],
        ], $submission['replayed'] ? 200 : 201);
    }
}
