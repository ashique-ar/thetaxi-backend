<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Booking\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly Booking $booking,
        public readonly ?string $pdfPath = null,
        public readonly array $contractualDistanceBreakdowns = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice ' . $this->invoice->invoice_number . ' — ' . config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'booking' => $this->booking,
                'contractualDistanceBreakdowns' => $this->contractualDistanceBreakdowns,
            ],
        );
    }

    public function attachments(): array
    {
        if ($this->pdfPath && file_exists($this->pdfPath)) {
            return [
                Attachment::fromPath($this->pdfPath)
                    ->as('Invoice-' . $this->invoice->invoice_number . '.pdf')
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
