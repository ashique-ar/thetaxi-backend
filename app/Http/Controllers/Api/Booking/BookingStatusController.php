<?php
// app/Http/Controllers/Api/BookingStatusController.php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking\BookingStatus;
use App\Http\Requests\Booking\CreateBookingStatusRequest;
use App\Http\Requests\Booking\UpdateBookingStatusRequest;
use App\Http\Resources\Booking\BookingStatusResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingStatusController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:booking-statuses.view')->only(['index','show']);
        $this->middleware('permission:booking-statuses.create')->only(['store']);
        $this->middleware('permission:booking-statuses.edit')->only(['update']);
        $this->middleware('permission:booking-statuses.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = BookingStatus::query();
        return BookingStatusResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateBookingStatusRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $st = BookingStatus::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Booking status recorded',
            'data'=>['status'=>new BookingStatusResource($st)]
        ],201);
    }

    public function show(BookingStatus $bookingStatus): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['status'=>new BookingStatusResource($bookingStatus)]
        ]);
    }

    public function update(UpdateBookingStatusRequest $request, BookingStatus $bookingStatus): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $bookingStatus->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Booking status updated',
            'data'=>['status'=>new BookingStatusResource($bookingStatus)]
        ]);
    }

    public function destroy(BookingStatus $bookingStatus): JsonResponse
    {
        $bookingStatus->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Booking status deleted'
        ]);
    }
}
