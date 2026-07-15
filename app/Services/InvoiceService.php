<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingItem;
use App\Models\Invoice;
use App\Models\Website\WebsiteSetting;
use App\Notifications\BookingLifecycleNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InvoiceService
{
    public function __construct(
        private readonly ContractualDistanceSnapshotProjector $distanceSnapshotProjector,
    ) {}

    // ────────────────────────────────────────────────────────────────
    // Public API
    // ────────────────────────────────────────────────────────────────

    /**
     * Generate (or regenerate) an invoice for a completed booking.
     * Idempotent: if an invoice already exists it is returned as-is
     * unless $force = true.
     */
    public function generateForBooking(Booking $booking, bool $force = false): Invoice
    {
        $existing = Invoice::where('booking_id', $booking->id)
            ->whereNotIn('status', ['void'])
            ->latest()
            ->first();

        if ($existing && !$force) {
            return $existing;
        }

        $booking->loadMissing([
            'customer.user',
            'bookingItems.serviceType',
            'bookingItems.vehicle.group',
            'bookingItems.driver.user',
            'bookingAddons.addon',
            'bookingCommonRatePricings',
        ]);

        $invoice = $this->createInvoiceRecord($booking);

        $this->generateAndStorePdf($invoice, $booking);

        // Back-fill invoice_number on the booking itself
        if (!$booking->invoice_number) {
            $booking->update(['invoice_number' => $invoice->invoice_number]);
        }

        Log::info('Invoice generated', [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'booking_id'     => $booking->id,
        ]);

        return $invoice;
    }

    /**
     * Send the invoice to the customer by email.
     */
    public function sendToCustomer(Invoice $invoice): void
    {
        $booking = $invoice->booking ?? Booking::find($invoice->booking_id);

        if (!$invoice->customer_email) {
            Log::warning('Invoice email skipped — no customer email', ['invoice_id' => $invoice->id]);
            return;
        }

        $pdfPath = $invoice->pdf_path
            ? Storage::disk($invoice->pdf_disk ?? 'local')->path($invoice->pdf_path)
            : null;

        try {
            app(MailDispatchService::class)->sendToCustomer(
                $invoice->customer_email,
                new InvoiceMail($invoice, $booking, $pdfPath, $this->contractualDistanceBreakdowns($booking))
            );

            Log::info('Invoice email sent', [
                'invoice_id' => $invoice->id,
                'to'         => $invoice->customer_email,
            ]);

            if ($booking) {
                $this->notifyInvoiceSent($booking, $invoice);
            }
        } catch (\Throwable $e) {
            Log::error('Invoice email failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate + send in one call (called from booking completion hook).
     */
    public function generateAndSend(Booking $booking, bool $force = false): Invoice
    {
        $invoice = $this->generateForBooking($booking, $force);
        $this->sendToCustomer($invoice);
        return $invoice;
    }

    /**
     * Regenerate the PDF for an existing invoice (e.g. after price adjustments).
     */
    public function regeneratePdf(Invoice $invoice): Invoice
    {
        $booking = $invoice->booking ?? Booking::with([
            'customer.user',
            'bookingItems.serviceType',
            'bookingItems.vehicle.group',
            'bookingItems.driver.user',
            'bookingAddons.addon',
        ])->findOrFail($invoice->booking_id);

        $this->generateAndStorePdf($invoice, $booking);
        return $invoice->fresh();
    }

    /**
     * Void an invoice (e.g. on booking cancellation).
     */
    public function void(Invoice $invoice, string $reason = ''): void
    {
        $invoice->void();
        Log::info('Invoice voided', ['invoice_id' => $invoice->id, 'reason' => $reason]);
    }

    /**
     * Mark invoice as paid.
     */
    public function markPaid(Invoice $invoice): void
    {
        $invoice->markPaid();
    }

    private function notifyInvoiceSent(Booking $booking, Invoice $invoice): void
    {
        try {
            $booking->loadMissing('customer.user');
            $booking->customer?->user?->notify(new BookingLifecycleNotification(
                $booking,
                'Invoice sent',
                'Your invoice is ready and has been sent to your email address.',
                'booking_invoice_sent',
                false,
            ));
        } catch (\Throwable $exception) {
            Log::warning('Invoice database notification could not be queued', [
                'booking_id' => $booking->id,
                'invoice_id' => $invoice->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    // ────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────

    private function createInvoiceRecord(Booking $booking): Invoice
    {
        $currency = $booking->currency
            ?? WebsiteSetting::getValue('default_currency', config('booking.default_currency', 'LKR'));

        $lineItems = $this->buildLineItems($booking, $currency);

        $subtotal       = collect($lineItems)->sum('amount');
        $discountAmount = (float) ($booking->discount_amount ?? 0);
        $taxAmount      = $this->calculateTax($subtotal - $discountAmount);
        $totalAmount    = max(0, $subtotal - $discountAmount + $taxAmount);

        $customer  = $booking->customer;
        $user      = $customer?->user;
        $firstName = $user?->first_name ?? '';
        $lastName  = $user?->last_name ?? '';
        $fullName  = trim("$firstName $lastName") ?: ($customer?->name ?? 'Customer');

        $dueDays = (int) (WebsiteSetting::getValue('invoice_due_days', 7) ?? 7);

        return Invoice::create([
            'invoice_number'  => $this->generateInvoiceNumber(),
            'booking_id'      => $booking->id,
            'customer_id'     => $booking->customer_id,
            'customer_name'   => $fullName,
            'customer_email'  => $user?->email,
            'customer_phone'  => $user?->phone,
            'customer_address' => $customer?->address ?? $user?->address,
            'currency'        => $currency,
            'subtotal'        => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount'      => $taxAmount,
            'total_amount'    => $totalAmount,
            'line_items'      => $lineItems,
            'status'          => 'issued',
            'issue_date'      => Carbon::today(),
            'due_date'        => $dueDays > 0 ? Carbon::today()->addDays($dueDays) : null,
            'payment_terms'   => WebsiteSetting::getValue('invoice_payment_terms'),
            'notes'           => WebsiteSetting::getValue('invoice_notes'),
            'created_user_id' => auth()->id(),
        ]);
    }

    /**
     * Build structured line items from booking data.
     * @return array<int, array<string, mixed>>
     */
    private function buildLineItems(Booking $booking, string $currency): array
    {
        $items = [];

        // --- Booking items (trips) ---
        foreach ($booking->bookingItems as $item) {
            /** @var BookingItem $item */
            $serviceLabel = $item->serviceType?->name ?? 'Transport Service';
            $vehicleLabel = $item->vehicle?->group?->name ?? $item->vehicle?->title ?? null;
            $durationLabel = $this->describeDuration($item);

            $baseAmount = (float) ($item->base_rate ?? $item->base_amount ?? $item->total_price ?? 0);

            $description = $serviceLabel;
            if ($vehicleLabel) {
                $description .= " — $vehicleLabel";
            }

            $items[] = [
                'description' => $description,
                'note'        => $durationLabel,
                'quantity'    => 1,
                'unit_price'  => $baseAmount,
                'amount'      => $baseAmount,
                'type'        => 'service',
            ];

            // Driver cost
            if (!empty($item->driver_cost) && $item->driver_cost > 0) {
                $items[] = [
                    'description' => 'Driver Allowance',
                    'note'        => null,
                    'quantity'    => 1,
                    'unit_price'  => (float) $item->driver_cost,
                    'amount'      => (float) $item->driver_cost,
                    'type'        => 'driver',
                ];
            }

            // Distance cost
            if (!empty($item->distance_cost) && $item->distance_cost > 0) {
                $items[] = [
                    'description' => 'Distance Charges',
                    'note'        => $item->estimated_distance ? number_format($item->estimated_distance, 1) . ' km' : null,
                    'quantity'    => 1,
                    'unit_price'  => (float) $item->distance_cost,
                    'amount'      => (float) $item->distance_cost,
                    'type'        => 'distance',
                ];
            }

            $extraKmLine = $this->extractExtraKmLineItem($item);
            if ($extraKmLine) {
                $items[] = $extraKmLine;
            }
        }

        // --- Addons ---
        foreach ($booking->bookingAddons ?? [] as $addon) {
            if ((bool) ($addon->is_milage ?? false)) {
                continue;
            }

            $items[] = [
                'description' => $addon->label ?? $addon->addon?->name ?? 'Add-on',
                'note'        => null,
                'quantity'    => (int) ($addon->qty ?? 1),
                'unit_price'  => (float) ($addon->rate ?? $addon->amount ?? 0),
                'amount'      => (float) ($addon->total_price ?? $addon->amount ?? 0),
                'type'        => 'addon',
            ];
        }

        // --- Fallback: use booking-level amounts if no items present ---
        if (empty($items)) {
            $baseAmount = (float) ($booking->base_amount ?? $booking->total_estimated ?? 0);
            $items[] = [
                'description' => 'Transport Service',
                'note'        => null,
                'quantity'    => 1,
                'unit_price'  => $baseAmount,
                'amount'      => $baseAmount,
                'type'        => 'service',
            ];
        }

        return $items;
    }

    private function extractExtraKmLineItem(BookingItem $item): ?array
    {
        $kilometers = 0;
        $rate = 0;
        $amount = 0;

        $customizations = is_array($item->customizations ?? null) ? $item->customizations : [];
        foreach ($customizations as $customization) {
            if (!is_array($customization)) {
                continue;
            }

            $type = $customization['type'] ?? $customization['code'] ?? null;
            if (!in_array($type, ['extra_km', 'extra_kilometers', 'additional_km'], true)) {
                continue;
            }

            $kilometers = (float) ($customization['quantity'] ?? ($customization['km'] ?? ($customization['value'] ?? 0)));
            $rate = (float) ($customization['rate_per_km'] ?? ($customization['rate'] ?? ($customization['price_per_km'] ?? 0)));
            $amount = (float) ($customization['total_cost'] ?? ($customization['total'] ?? ($kilometers * $rate)));
            break;
        }

        $metadata = is_array($item->metadata ?? null) ? $item->metadata : [];
        if ($kilometers <= 0 && !empty($metadata)) {
            $kilometers = (float) ($metadata['extra_km'] ?? ($metadata['extra_kilometers'] ?? ($metadata['additional_km'] ?? 0)));
            $rate = (float) ($metadata['extra_km_rate'] ?? ($metadata['km_rate'] ?? 0));
            $amount = (float) ($metadata['extra_km_total'] ?? ($kilometers * $rate));
        }

        if ($kilometers <= 0 || $amount <= 0) {
            return null;
        }

        return [
            'description' => 'Extra Kilometers',
            'note'        => number_format($kilometers, 0) . ' km',
            'quantity'    => $kilometers,
            'unit_price'  => $rate,
            'amount'      => $amount,
            'type'        => 'extra_km',
        ];
    }

    private function describeDuration(BookingItem $item): ?string
    {
        if (!$item->from_date) {
            return null;
        }
        $from = $item->from_date->format('d M Y');
        $to   = $item->to_date ? $item->to_date->format('d M Y') : $from;
        return $from === $to ? $from : "$from – $to";
    }

    /**
     * Tax calculation. Rate is read from website_settings.
     * Returns 0 by default (no tax) — configure 'invoice_tax_rate_pct' in settings.
     */
    private function calculateTax(float $taxableAmount): float
    {
        $rate = (float) (WebsiteSetting::getValue('invoice_tax_rate_pct', 0) ?? 0);
        if ($rate <= 0 || $taxableAmount <= 0) {
            return 0.0;
        }
        return round($taxableAmount * $rate / 100, 2);
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = WebsiteSetting::getValue('invoice_prefix', 'INV') ?? 'INV';
        $year   = date('Y');
        $month  = date('m');

        // Count invoices this month for sequential numbering
        $count = Invoice::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->withTrashed()
            ->count();

        return sprintf('%s-%s%s-%04d', $prefix, $year, $month, $count + 1);
    }

    private function generateAndStorePdf(Invoice $invoice, Booking $booking): void
    {
        try {
            $viewData = $this->buildPdfViewData($invoice, $booking);

            /** @var \Barryvdh\DomPDF\PDF $pdf */
            $pdf = Pdf::loadView('invoices.invoice', $viewData)
                ->setPaper('a4', 'portrait');

            $relativePath = 'invoices/' . $invoice->invoice_number . '.pdf';
            Storage::disk('local')->put($relativePath, $pdf->output());

            $invoice->update([
                'pdf_path' => $relativePath,
                'pdf_disk' => 'local',
            ]);
        } catch (\Throwable $e) {
            // PDF generation failure should NOT block the booking completion flow.
            Log::error('Invoice PDF generation failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPdfViewData(Invoice $invoice, Booking $booking): array
    {
        $firstItem   = $booking->bookingItems?->first();
        $vehicle     = $firstItem?->vehicle;
        $driver      = $firstItem?->driver;
        $serviceType = $firstItem?->serviceType?->name ?? null;

        return [
            'invoice'        => $invoice,
            'booking'        => $booking,
            // Company
            'companyName'    => WebsiteSetting::getValue('company_name', config('app.name')),
            'companyAddress' => WebsiteSetting::getValue('company_address', ''),
            'companyPhone'   => WebsiteSetting::getValue('company_phone', ''),
            'companyEmail'   => WebsiteSetting::getValue('company_email', config('mail.from.address', '')),
            'companyWeb'     => WebsiteSetting::getValue('company_website', config('app.url')),
            'companyLogo'    => WebsiteSetting::getValue('company_logo_path', null),
            // Booking context
            'serviceType'    => $serviceType,
            'fromDate'       => $firstItem?->from_date?->format('d M Y H:i'),
            'toDate'         => $firstItem?->to_date?->format('d M Y H:i'),
            'vehicle'        => $vehicle ? ($vehicle->group?->name ?? $vehicle->title) . ' (' . $vehicle->license_plate . ')' : null,
            'driver'         => $driver ? trim(($driver->user?->first_name ?? '') . ' ' . ($driver->user?->last_name ?? '')) : null,
            'contractualDistanceBreakdowns' => $this->contractualDistanceBreakdowns($booking),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function contractualDistanceBreakdowns(Booking $booking): array
    {
        $breakdowns = [];

        foreach ($booking->bookingItems ?? [] as $item) {
            $breakdown = $this->distanceSnapshotProjector->project(
                is_array($item->pricing_breakdown) ? $item->pricing_breakdown : []
            );
            if ($breakdown) {
                $breakdown['service_type'] = $item->serviceType?->name;
                $breakdowns[] = $breakdown;
            }
        }

        if (empty($breakdowns)) {
            $breakdown = $this->distanceSnapshotProjector->project(
                is_array($booking->pricing_snapshot) ? $booking->pricing_snapshot : []
            );
            if ($breakdown) {
                $breakdowns[] = $breakdown;
            }
        }

        return $breakdowns;
    }
}
