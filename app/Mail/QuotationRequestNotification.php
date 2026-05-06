<?php

namespace App\Mail;

use App\Models\Inquiry;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuotationRequestNotification extends Mailable
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
            subject: "New Quotation Request {$reference} - {$this->vehicleGroup->name}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.quotation-request-notification',
            with: [
                'inquiry' => $this->inquiry,
                'requestData' => $this->requestData,
                'vehicleGroup' => $this->vehicleGroup,
                'customerName' => $this->requestData['customer_name'] ?? 'Unknown',
                'customerEmail' => $this->requestData['customer_email'] ?? '',
                'customerPhone' => $this->requestData['customer_phone'] ?? '',
                'companyName' => $this->requestData['company_name'] ?? null,
                'serviceType' => $this->requestData['service_type'] ?? 'Unknown',
                'travelDate' => $this->requestData['travel_date'] ?? null,
                'travelTime' => $this->requestData['travel_time'] ?? null,
                'pickupLocation' => $this->requestData['pickup_location'] ?? null,
                'dropoffLocation' => $this->requestData['dropoff_location'] ?? null,
                'passengers' => $this->requestData['passengers'] ?? null,
                'specialRequirements' => $this->requestData['special_requirements'] ?? null,
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
