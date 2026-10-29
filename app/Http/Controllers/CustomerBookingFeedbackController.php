<?php

namespace App\Http\Controllers;

use App\Models\Booking\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerBookingFeedbackController extends Controller
{
    public function show(Booking $booking)
    {
        abort_unless($booking->status === 'completed', 404);
        return view('booking.feedback', ['booking' => $booking]);
    }

    public function store(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = DB::transaction(function () use ($booking, $data): Booking {
            $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            abort_unless($locked->status === 'completed', 404);
            if ($locked->customer_rating === null) {
                $locked->forceFill([
                    'customer_rating' => $data['rating'],
                    'customer_feedback' => $data['comment'] ?? null,
                    'customer_feedback_submitted_at' => now(),
                ])->save();
            }
            return $locked->fresh();
        }, 3);

        return view('booking.feedback', ['booking' => $booking]);
    }
}
