<?php

namespace App\Mail;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentInitiatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public float $amount;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, float $amount)
    {
        $this->booking = $booking;
        $this->amount = $amount;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Initiated - Reference: ' . $this->booking->booking_number
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $settings = app(\App\Services\WebsiteSettingsService::class);

        try {
            $this->booking = \App\Models\Booking\Booking::with([
                'customer.user',
                'bookingAddons.addon',
                'bookingItems.vehicleGroup',
                'bookingItems.serviceType',
            ])->find($this->booking->id);
        } catch (\Exception $e) {
            \Log::warning('PaymentInitiatedMail: failed to reload booking for email', ['booking_id' => $this->booking->id, 'error' => $e->getMessage()]);
        }

        return new Content(
            view: 'emails.payment-initiated',
            with: [
                'booking' => $this->booking,
                'amount' => $this->amount,
                'supportEmail' => $settings->get('company_email', config('mail.from.address', '')),
                'supportPhone' => $settings->get('company_phone', ''),
            ]
        );
    }
}
