<?php

namespace App\Http\Controllers;

use App\Models\Booking\Booking;
use App\Services\BookingLifecycleService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerBookingStatusController extends Controller
{
    public function __construct(private readonly BookingLifecycleService $lifecycleService)
    {
    }

    public function show(): View
    {
        return view('booking.status');
    }

    public function lookup(Request $request): View
    {
        $validated = $request->validate([
            'booking_reference' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:255'],
        ]);

        $booking = Booking::query()
            ->with(['customer.user'])
            ->where(function ($query) use ($validated): void {
                $query->where('booking_number', $validated['booking_reference'])
                    ->orWhere('confirmation_number', $validated['booking_reference']);
            })
            ->first();

        if (!$booking || !$this->contactMatches($booking, $validated['contact'])) {
            return view('booking.status')->withErrors([
                'booking_reference' => 'We could not verify a booking with those details.',
            ])->withInput();
        }

        $contract = $this->lifecycleService->getLifecycleContract($booking);
        $status = $this->customerStatus($contract);

        return view('booking.status', [
            'bookingStatus' => [
                'reference' => $booking->booking_number ?: $booking->confirmation_number,
                'label' => $status['label'],
                'message' => $status['message'],
                'stage' => $status['stage'],
                'payment_status' => $this->paymentLabel($contract['payment_collection_status'] ?? null),
                'updated_at' => $booking->updated_at,
            ],
        ]);
    }

    private function contactMatches(Booking $booking, string $contact): bool
    {
        $expected = strtolower(trim($contact));
        $user = $booking->customer?->user;

        return $expected !== '' && in_array($expected, array_filter([
            strtolower(trim((string) ($user?->email ?? ''))),
            strtolower(trim((string) ($user?->phone ?? ''))),
            strtolower(trim((string) ($user?->mobile ?? ''))),
        ]), true);
    }

    private function customerStatus(array $contract): array
    {
        $lifecycle = strtolower((string) ($contract['lifecycle_status'] ?? ''));
        $booking = strtolower((string) ($contract['booking_status'] ?? ''));
        $approval = strtolower((string) ($contract['approval_status'] ?? ''));

        if (in_array($booking, ['cancelled', 'canceled', 'rejected'], true) || $approval === 'rejected') {
            return ['label' => 'Cancelled', 'message' => 'This booking is no longer scheduled.', 'stage' => 0];
        }
        if ($approval === 'pending' || $booking === 'pending_approval') {
            return ['label' => 'Awaiting approval', 'message' => 'Your request is waiting for approval.', 'stage' => 1];
        }
        if ($lifecycle === 'completed' || $booking === 'completed') {
            return ['label' => 'Completed', 'message' => 'Your booking has been completed.', 'stage' => 5];
        }
        if (str_contains($lifecycle, 'return') || str_starts_with($lifecycle, 'qc_') || str_contains($lifecycle, 'repair')) {
            return ['label' => 'Vehicle return required', 'message' => 'The trip is complete and return checks are in progress.', 'stage' => 4];
        }
        if (str_contains($lifecycle, 'ongoing') || str_contains($lifecycle, 'trip_')) {
            return ['label' => 'Trip in progress', 'message' => 'Your trip is currently in progress.', 'stage' => 3];
        }
        if (!empty($contract['driver_trip_phase'])) {
            return ['label' => 'Driver assigned', 'message' => 'A driver has been assigned to your booking.', 'stage' => 2];
        }
        if (!empty($contract['dispatch_status']) || str_contains($lifecycle, 'dispatch')) {
            return ['label' => 'Vehicle assigned', 'message' => 'A vehicle has been assigned and is being prepared.', 'stage' => 2];
        }

        return ['label' => 'Confirmed', 'message' => 'Your booking is confirmed and scheduled.', 'stage' => 1];
    }

    private function paymentLabel(?string $status): string
    {
        return in_array(strtolower((string) $status), ['paid', 'collected', 'completed'], true)
            ? 'Paid'
            : 'Pending';
    }
}
