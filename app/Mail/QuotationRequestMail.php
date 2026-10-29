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
    protected bool $staffRequested;
    protected ?string $checkoutLink;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, bool $staffRequested = false, ?string $checkoutLink = null)
    {
        $this->booking = $booking;
        $this->staffRequested = $staffRequested;
        $this->checkoutLink = $checkoutLink;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->staffRequested ? 'Your quotation is ready - ' : 'We received your quotation request - ') . $this->booking->booking_number,
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
                'bookingAddons.addon',
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
                'staffRequested' => $this->staffRequested,
                'checkoutLink' => $this->checkoutLink,
            ]
        );
    }
}
