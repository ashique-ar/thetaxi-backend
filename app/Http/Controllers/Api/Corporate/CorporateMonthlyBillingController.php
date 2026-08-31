<?php

namespace App\Http\Controllers\Api\Corporate;

use App\Http\Controllers\Controller;
use App\Models\Corporate\Corporate;
use App\Models\Corporate\CorporateBillingTerm;
use App\Models\Finance\FinancialAccountSettlement;
use App\Services\CorporateMonthlyBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateMonthlyBillingController extends Controller
{
    public function __construct(private readonly CorporateMonthlyBillingService $billing)
    {
    }

    public function terms(Corporate $corporate): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->billing->terms($corporate->id)]);
    }

    public function storeTerms(Request $request, Corporate $corporate): JsonResponse
    {
        $data = $request->validate([
            'billing_cycle' => ['required', 'in:monthly'], 'cutoff_day' => ['required', 'integer', 'between:1,31'],
            'invoice_day' => ['required', 'integer', 'between:1,31'], 'due_days' => ['required', 'integer', 'between:0,365'],
            'credit_limit' => ['nullable', 'numeric', 'gte:0'], 'currency' => ['required', 'string', 'size:3'],
            'billing_name' => ['nullable', 'string', 'max:255'], 'tax_identifier' => ['nullable', 'string', 'max:100'],
            'billing_address' => ['nullable', 'string', 'max:2000'], 'recipients' => ['nullable', 'array', 'max:20'],
            'recipients.*' => ['email:rfc', 'max:255'], 'delivery_preferences' => ['nullable', 'array'],
            'delivery_preferences.email_invoice' => ['nullable', 'boolean'], 'delivery_preferences.email_statement' => ['nullable', 'boolean'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);
        $term = $this->billing->storeTerms($corporate, $data, $request->user()?->id);
        return response()->json(['status' => 'success', 'message' => 'Corporate billing terms created.', 'data' => $term], 201);
    }

    public function endTerms(Request $request, Corporate $corporate, CorporateBillingTerm $term): JsonResponse
    {
        $data = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d']]);
        return response()->json(['status'=>'success','message'=>'Billing terms version ended.','data'=>$this->billing->endTerms($corporate, $term, $data['effective_to'], $request->user()?->id)]);
    }

    public function preview(Request $request, Corporate $corporate): JsonResponse
    {
        $data = $this->period($request);
        return response()->json(['status' => 'success', 'data' => $this->billing->preview($corporate->id, $data['period_start'], $data['period_end'])]);
    }

    public function generate(Request $request, Corporate $corporate): JsonResponse
    {
        $data = $this->period($request);
        $settlement = $this->billing->generate($corporate->id, $data['period_start'], $data['period_end'], $request->user()?->id);
        return response()->json(['status' => 'success', 'message' => 'Monthly corporate settlement and statement generated.', 'data' => $settlement], 201);
    }

    public function issue(Request $request, Corporate $corporate, FinancialAccountSettlement $settlement): JsonResponse
    {
        abort_unless($settlement->owner_type === 'corporate' && (string) $settlement->owner_id === (string) $corporate->id, 404);
        $data = $request->validate(['send_documents' => ['nullable', 'boolean']]);
        $issued = $this->billing->issue($settlement, $data['send_documents'] ?? true);
        return response()->json(['status'=>'success','message'=>'Corporate invoice issued and configured documents processed.','data'=>$issued]);
    }

    private function period(Request $request): array
    {
        return $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);
    }
}
