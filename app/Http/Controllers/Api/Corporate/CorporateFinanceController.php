<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Finance\FinancialAccountSettlement;
use App\Services\CorporateFinancialProjectionService;
use App\Services\CorporateOnlinePaymentService;
use App\Services\WebXPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorporateFinanceController extends Controller
{
    public function __construct(
        private readonly CorporateFinancialProjectionService $projection,
        private readonly CorporateOnlinePaymentService $onlinePayments,
    )
    {
        $this->middleware('permission:view_payments');
    }

    public function account(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => [
            ...$this->projection->accountSummary($request->corporate_id),
            'online_payment_enabled' => app(WebXPayService::class)->isEnabled(),
        ]]);
    }

    public function billingPeriods(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->projection->billingPeriods($request->corporate_id)]);
    }

    public function payInvoice(Request $request, string $settlement): JsonResponse
    {
        $corporate = \App\Models\Corporate\Corporate::query()->findOrFail($request->corporate_id);
        $user = $request->user();
        $nameParts = preg_split('/\s+/', trim((string) ($corporate->name ?: 'Corporate Account')), 2) ?: [];
        $payment = $this->onlinePayments->initiate((string) $corporate->id, $settlement, $user?->id, [
            'first_name' => $user?->first_name ?: ($nameParts[0] ?? 'Corporate'),
            'last_name' => $user?->last_name ?: ($nameParts[1] ?? 'Account'),
            'email' => $corporate->contact_email ?: $user?->email,
            'contact_number' => $corporate->contact_phone ?: $user?->phone,
            'address_line_one' => $corporate->billing_address ?: $user?->address,
            'city' => $user?->city,
        ]);

        return response()->json(['status' => 'success', 'data' => $payment]);
    }

    public function show(Request $request, string $settlement): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->projection->settlementDetail($request->corporate_id, $settlement)]);
    }

    public function invoice(Request $request, string $settlement): StreamedResponse
    {
        $row = FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')
            ->where('owner_id', $request->corporate_id)
            ->with('document')
            ->findOrFail($settlement);
        abort_unless($row->document?->pdf_path, 404, 'Invoice document is not available.');

        return Storage::disk($row->document->pdf_disk ?: 'local')->download(
            $row->document->pdf_path,
            ($row->invoice_number ?: $row->settlement_number) . '.pdf'
        );
    }

    public function statement(Request $request, string $settlement): StreamedResponse
    {
        $row = FinancialAccountSettlement::query()
            ->where('owner_type', 'corporate')->where('owner_id', $request->corporate_id)
            ->findOrFail($settlement);
        abort_unless($row->statement_pdf_path, 404, 'Statement document is not available.');
        return Storage::disk($row->statement_pdf_disk ?: 'local')->download(
            $row->statement_pdf_path,
            'statement-' . $row->settlement_number . '.pdf'
        );
    }
}
