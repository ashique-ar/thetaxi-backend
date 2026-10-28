<?php
// app/Http/Controllers/Api/BookingChannelController.php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Models\Booking\BookingChannel;
use App\Http\Requests\Booking\BookingChannel\CreateBookingChannelRequest;
use App\Http\Requests\Booking\BookingChannel\UpdateBookingChannelRequest;
use App\Http\Resources\BookingChannelResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingChannelController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:booking-channels.view|bookings.create')->only(['index']);
        $this->middleware('permission:booking-channels.view')->only(['show']);
        $this->middleware('permission:booking-channels.create')->only(['store']);
        $this->middleware('permission:booking-channels.edit')->only(['update']);
        $this->middleware('permission:booking-channels.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = BookingChannel::query()->when(!$request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true));
        return BookingChannelResource::collection($q->orderBy('name')->paginate($request->per_page ?? 15));
    }

    public function store(CreateBookingChannelRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['is_active'] ?? true) === false) $data['is_default'] = false;
        if (!empty($data['is_default'])) BookingChannel::query()->update(['is_default' => false]);
        $data['created_user_id'] = $request->user()->id;
        $chan = BookingChannel::create($data);
        if (!$chan->is_default && !BookingChannel::where('is_active', true)->where('is_default', true)->exists()) {
            $chan->update(['is_default' => true]);
        }

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
        if (($data['is_active'] ?? $bookingChannel->is_active) === false) $data['is_default'] = false;
        if (!empty($data['is_default'])) BookingChannel::query()->where('id', '<>', $bookingChannel->id)->update(['is_default' => false]);
        $data['updated_user_id'] = $request->user()->id;
        $bookingChannel->update($data);
        if (!BookingChannel::where('is_active', true)->where('is_default', true)->exists()) {
            $fallback = BookingChannel::where('is_active', true)->orderBy('name')->first();
            $fallback?->update(['is_default' => true]);
        }

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
