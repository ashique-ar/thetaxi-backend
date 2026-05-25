<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:invoices.view')->only(['index', 'show', 'download']);
        $this->middleware('permission:invoices.generate')->only(['generate', 'regenerate']);
        $this->middleware('permission:invoices.send')->only(['send']);
        $this->middleware('permission:invoices.void')->only(['void']);
    }

    /**
     * List invoices for a booking.
     * GET /api/bookings/{bookingId}/invoices
     */
    public function index(string $bookingId): JsonResponse
    {
        $booking  = Booking::findOrFail($bookingId);
        $invoices = Invoice::where('booking_id', $booking->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => ['invoices' => $invoices],
        ]);
    }

    /**
     * Get single invoice.
     * GET /api/invoices/{id}
     */
    public function show(string $id): JsonResponse
    {
        $invoice = Invoice::with('booking.customer.user')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => ['invoice' => $invoice],
        ]);
    }

    /**
     * Generate invoice for a booking (creates new or returns existing).
     * POST /api/bookings/{bookingId}/invoices/generate
     */
    public function generate(Request $request, string $bookingId): JsonResponse
    {
        $booking = Booking::with([
            'customer.user',
            'bookingItems.serviceType',
            'bookingItems.vehicle.group',
            'bookingItems.driver.user',
            'bookingAddons',
        ])->findOrFail($bookingId);

        $force   = $request->boolean('force', false);
        $invoice = $this->invoiceService->generateForBooking($booking, $force);

        return response()->json([
            'status'  => 'success',
            'message' => 'Invoice generated successfully',
            'data'    => ['invoice' => $invoice],
        ]);
    }

    /**
     * Regenerate (overwrite) an existing invoice's PDF.
     * POST /api/invoices/{id}/regenerate
     */
    public function regenerate(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->isVoid()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot regenerate a voided invoice',
            ], 422);
        }

        $invoice = $this->invoiceService->regeneratePdf($invoice);

        return response()->json([
            'status'  => 'success',
            'message' => 'Invoice PDF regenerated',
            'data'    => ['invoice' => $invoice],
        ]);
    }

    /**
     * Send invoice to customer by email.
     * POST /api/invoices/{id}/send
     */
    public function send(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        if ($invoice->isVoid()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot send a voided invoice',
            ], 422);
        }

        $this->invoiceService->sendToCustomer($invoice);

        return response()->json([
            'status'  => 'success',
            'message' => 'Invoice sent to customer',
        ]);
    }

    /**
     * Download the invoice PDF.
     * GET /api/invoices/{id}/download
     */
    public function download(string $id): Response|JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        if (!$invoice->pdf_path || !Storage::disk($invoice->pdf_disk ?? 'local')->exists($invoice->pdf_path)) {
            // Generate PDF on demand if missing
            $invoice = $this->invoiceService->regeneratePdf($invoice);
        }

        if (!$invoice->pdf_path) {
            return response()->json([
                'status'  => 'error',
                'message' => 'PDF could not be generated',
            ], 500);
        }

        $content  = Storage::disk($invoice->pdf_disk ?? 'local')->get($invoice->pdf_path);
        $filename = 'Invoice-' . $invoice->invoice_number . '.pdf';

        return response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Void an invoice.
     * POST /api/invoices/{id}/void
     */
    public function void(Request $request, string $id): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $invoice = Invoice::findOrFail($id);

        if ($invoice->isVoid()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invoice is already voided',
            ], 422);
        }

        $this->invoiceService->void($invoice, $request->input('reason', ''));

        return response()->json([
            'status'  => 'success',
            'message' => 'Invoice voided',
        ]);
    }

    /**
     * List all invoices (admin view).
     * GET /api/invoices
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Invoice::with('booking:id,booking_number')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, function ($q, $s) {
                $q->where(function ($inner) use ($s) {
                    $inner->where('invoice_number', 'like', "%$s%")
                          ->orWhere('customer_name', 'like', "%$s%")
                          ->orWhere('customer_email', 'like', "%$s%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'status' => 'success',
            'data'   => [
                'invoices'   => $query->items(),
                'pagination' => [
                    'current_page' => $query->currentPage(),
                    'last_page'    => $query->lastPage(),
                    'per_page'     => $query->perPage(),
                    'total'        => $query->total(),
                ],
            ],
        ]);
    }
}
