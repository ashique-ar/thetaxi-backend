<?php

namespace App\Mail;

use App\Models\Inquiry;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationRequestConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public Inquiry $inquiry;
    public array $requestData;
    public VehicleGroup $vehicleGroup;

    /**
     * Create a new message instance.
     */
    public function __construct(Inquiry $inquiry, array $requestData, VehicleGroup $vehicleGroup)
    {
        $this->inquiry = $inquiry;
        $this->requestData = $requestData;
        $this->vehicleGroup = $vehicleGroup;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $reference = $this->inquiry->inquiry_number ?? $this->inquiry->id;

        return new Envelope(
            subject: "Quotation Request {$reference} Received - {$this->vehicleGroup->name}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-request-confirmation',
            with: [
                'inquiry' => $this->inquiry,
                'requestData' => $this->requestData,
                'vehicleGroup' => $this->vehicleGroup,
                'customerName' => $this->requestData['customer_name'] ?? 'Dear Customer',
                'inquiryNumber' => $this->inquiry->inquiry_number ?? $this->inquiry->id,
                'estimatedResponseTime' => '2 business hours',
                'supportEmail' => config('mail.support_email', 'info@thetaxi.lk'),
                'supportPhone' => config('app.support_phone', '+94 71 1 615 615'),
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
