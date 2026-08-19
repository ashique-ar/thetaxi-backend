<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PricingResolutionFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $details;

    /**
     * Create a new message instance.
     */
    public function __construct(array $details)
    {
        $this->details = $details;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = 'Final pricing pending manual review for booking '
            . ($this->details['booking_number'] ?? $this->details['booking_id'] ?? 'unknown');

        return $this->subject($subject)
            ->view('emails.pricing_resolution_failed')
            ->with(['details' => $this->details]);
    }
}
