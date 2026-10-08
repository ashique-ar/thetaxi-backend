<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesProfile;
use App\Services\StaffAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollectionCommissionController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $request->query->remove('staff_id');
        $request->attributes->set('legacy_commission_self_only', true);

        return $this->index($request);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->newEngineIndex($request);
    }

    public function markPaid(): JsonResponse
    {
        abort(409, 'Legacy direct commission payouts are disabled. Use the approved commission statement Finance workflow.');
    }

    private function newEngineIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'staff_id' => ['nullable', 'uuid', 'exists:staff,id'],
            'status' => ['nullable', 'in:earned,held,shadow_earned,shadow_held'],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SalesCommissionDecision::query()
            ->when($filters['staff_id'] ?? null, fn ($q, $id) => $q->where('beneficiary_staff_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('decision_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('decision_at', '<=', $date));
        $this->scopeDecisionQuery($query, $request);
        $totals = (clone $query)->selectRaw(
            "COALESCE(SUM(eligible_source_amount),0) receipt_total,
             COALESCE(SUM(eligible_lkr_amount),0) eligible_total,
             COALESCE(SUM(CASE WHEN status = 'earned' THEN commission_amount_lkr ELSE 0 END),0) earned_total"
        )->first();
        return $this->deprecatedResponse($request, response()->json(['status' => 'success', 'engine' => 'sales_commission_decisions',
            'data' => $query->latest('decision_at')->paginate($filters['per_page'] ?? 25),
            'summary' => ['receipt_total' => (float) $totals->receipt_total, 'eligible_total' => (float) $totals->eligible_total,
                'earned_total' => (float) $totals->earned_total, 'paid_total' => 0, 'outstanding_total' => (float) $totals->earned_total]]));
    }

    private function scopeDecisionQuery($query, Request $request): void
    {
        if ($request->attributes->get('legacy_commission_self_only', false)) {
            $staff = app(StaffAccessService::class)->currentActorStaff($request->user());
            $query->where('beneficiary_staff_id', $staff->id);
            return;
        }
        if ($request->user()->can('collection-commissions.view-all')) return;
        $staff = app(StaffAccessService::class)->currentActorStaff($request->user());
        $ids = [$staff->id];
        if ($request->user()->can('collection-commissions.view-team')) {
            $profile = SalesProfile::query()->where('staff_id', $staff->id)->where('company_id', $staff->company_id)->activeAt(now())->first();
            if ($profile) {
                $memberProfileIds = DB::table('sales_reporting_assignments')->where('manager_sales_profile_id', $profile->id)
                    ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                    ->pluck('member_sales_profile_id');
                $ids = array_merge($ids, SalesProfile::query()->whereIn('id', $memberProfileIds)->where('company_id', $staff->company_id)->pluck('staff_id')->all());
            }
        }
        $query->whereIn('beneficiary_staff_id', array_values(array_unique($ids)));
    }

    private function deprecatedResponse(Request $request, JsonResponse $response): JsonResponse
    {
        activity('legacy-commission-api')
            ->causedBy($request->user())
            ->withProperties([
                'path' => $request->path(),
                'self_only' => (bool) $request->attributes->get('legacy_commission_self_only', false),
                'successor' => '/api/sales/commission-statements',
                'ip' => $request->ip(),
            ])
            ->log('legacy_collection_commissions_read');

        $payload = $response->getData(true);
        $payload['compatibility'] = [
            'deprecated' => true,
            'successor' => '/api/sales/commission-statements',
            'direct_payment_available' => false,
        ];
        $response->setData($payload);
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Link', '</api/sales/commission-statements>; rel="successor-version"');
        $response->headers->set('Warning', '299 - "Deprecated API: use /api/sales/commission-statements"');

        return $response;
    }
}
