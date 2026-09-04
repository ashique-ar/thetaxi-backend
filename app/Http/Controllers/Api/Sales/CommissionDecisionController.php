<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionDecision;
use App\Services\Sales\SalesAccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommissionDecisionController extends Controller
{
    public function __construct(private readonly SalesAccessScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['earned', 'held', 'shadow_earned', 'shadow_held'])],
            'commission_category' => ['nullable', Rule::in(['one_time', 'long_term', 'unknown'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SalesCommissionDecision::query();
        $this->applyScope($query, $request);
        return response()->json(['status' => 'success', 'data' => $query
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['commission_category'] ?? null, fn ($q, $category) => $q->where('commission_category', $category))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('decision_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('decision_at', '<=', $to))
            ->latest('decision_at')->paginate($request->integer('per_page', 25))]);
    }

    public function show(Request $request, SalesCommissionDecision $decision): JsonResponse
    {
        $query = SalesCommissionDecision::query()->whereKey($decision->id);
        $this->applyScope($query, $request);
        return response()->json(['status' => 'success', 'data' => $query->firstOrFail()]);
    }

    private function applyScope($query, Request $request): void
    {
        $profileIds = $this->scope->profileIds($request->user(), 'sales.commission-decisions.view-all',
            'sales.commission-decisions.view-team');
        if ($profileIds !== null) $query->whereIn('beneficiary_sales_profile_id', $profileIds);
    }
}
