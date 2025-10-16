<?php
// app/Http/Controllers/Api/BookingChannelController.php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking\BookingChannel;
use App\Http\Requests\Booking\CreateBookingChannelRequest;
use App\Http\Requests\Booking\UpdateBookingChannelRequest;
use App\Http\Resources\BookingChannelResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingChannelController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:booking-channels.view')->only(['index', 'show']);
        $this->middleware('permission:booking-channels.create')->only(['store']);
        $this->middleware('permission:booking-channels.edit')->only(['update']);
        $this->middleware('permission:booking-channels.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = BookingChannel::query();
        return BookingChannelResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateBookingChannelRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $chan = BookingChannel::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Booking channel created',
            'data' => ['channel' => new BookingChannelResource($chan)]
        ], 201);
    }

    public function show(BookingChannel $bookingChannel): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['channel' => new BookingChannelResource($bookingChannel)]
        ]);
    }

    public function update(UpdateBookingChannelRequest $request, BookingChannel $bookingChannel): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $bookingChannel->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Booking channel updated',
            'data' => ['channel' => new BookingChannelResource($bookingChannel)]
        ]);
    }

    public function destroy(BookingChannel $bookingChannel): JsonResponse
    {
        $bookingChannel->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Booking channel deleted'
        ]);
    }
}
