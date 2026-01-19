<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InquiryConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public Inquiry $inquiry;
    public string $typeLabel;
    public string $intro;

    /**
     * Create a new message instance.
     */
    public function __construct(Inquiry $inquiry, string $typeLabel, string $intro)
    {
        $this->inquiry = $inquiry;
        $this->typeLabel = $typeLabel;
        $this->intro = $intro;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->typeLabel} Received - Reference {$this->inquiry->inquiry_number}"
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.inquiry-confirmation',
            with: [
                'inquiry' => $this->inquiry,
                'typeLabel' => $this->typeLabel,
                'intro' => $this->intro,
                'contactName' => $this->inquiry->name ?? 'Valued Customer',
                'supportEmail' => config('mail.from.address', 'info@thetaxi.lk'),
                'supportPhone' => config('app.support_phone', '+94 71 1 615 615'),
            ]
        );
    }
}
