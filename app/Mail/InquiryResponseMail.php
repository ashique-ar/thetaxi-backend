<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InquiryResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Inquiry $inquiry, public string $responseMessage)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Response to your inquiry ' . $this->inquiry->inquiry_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.inquiry-response', with: [
            'inquiry' => $this->inquiry,
            'responseMessage' => $this->responseMessage,
            'contactName' => $this->inquiry->name ?: 'Customer',
        ]);
    }
}
