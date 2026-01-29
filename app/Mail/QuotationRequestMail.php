<?php

namespace App\Mail;

use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $booking;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Quotation Request - Reference: ' . $this->booking->booking_number,
            from: config('mail.from.address'),
            replyTo: [config('mail.from.address')]
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        try {
            $this->booking = \App\Models\Booking\Booking::with([
                'customer.user',
                'bookingItems.vehicleGroup',
                'bookingItems.serviceType',
            ])->find($this->booking->id);
        } catch (\Exception $e) {
            \Log::warning('QuotationRequestMail: failed to reload booking for email', ['booking_id' => $this->booking->id ?? null, 'error' => $e->getMessage()]);
        }

        return new Content(
            view: 'emails.quotation-request',
            with: [
                'booking' => $this->booking,
            ]
        );
    }
}
