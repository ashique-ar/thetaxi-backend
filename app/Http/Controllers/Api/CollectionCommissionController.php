<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\BookingCollectionCommission;
use App\Models\Booking\CollectionCommissionPayout;
use App\Models\Sales\SalesCommissionDecision;
use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        if (config('sales.features.commission_accrual', false)) {
            return $this->newEngineIndex($request);
        }
        $filters = $request->validate([
            'staff_id' => ['nullable', 'uuid', 'exists:staff,id'],
            'status' => ['nullable', 'in:earned,paid,ineligible,reversed'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = BookingCollectionCommission::query()
            ->with(['staff.user:id,first_name,last_name', 'booking:id,booking_number', 'receipt:id,payment_purpose,received_at'])
            ->when($filters['staff_id'] ?? null, fn ($q, $id) => $q->where('staff_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('earned_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('earned_at', '<=', $date));
        $this->scopeLegacyQuery($query, $request);
        $totals = (clone $query)->selectRaw(
            "COALESCE(SUM(receipt_amount),0) receipt_total,
             COALESCE(SUM(eligible_amount),0) eligible_total,
             COALESCE(SUM(CASE WHEN status IN ('earned','paid') THEN commission_amount ELSE 0 END),0) earned_total,
             COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END),0) paid_total,
             COALESCE(SUM(CASE WHEN status = 'earned' THEN commission_amount ELSE 0 END),0) outstanding_total"
        )->first();
        $rows = $query->latest('earned_at')->paginate($filters['per_page'] ?? 25);

        return $this->deprecatedResponse($request, response()->json([
            'status' => 'success',
            'data' => $rows,
            'summary' => [
                'receipt_total' => (float) $totals->receipt_total,
                'eligible_total' => (float) $totals->eligible_total,
                'earned_total' => (float) $totals->earned_total,
                'paid_total' => (float) $totals->paid_total,
                'outstanding_total' => (float) $totals->outstanding_total,
            ],
        ]));
    }

    public function markPaid(Request $request): JsonResponse
    {
        abort_if(config('sales.features.commission_accrual', false) || config('sales.features.payouts', false), 409,
            'Direct commission payment is disabled. Generate, approve, and pay a commission statement through the Finance workflow.');
        $data = $request->validate([
            'commission_ids' => ['required', 'array', 'min:1'],
            'commission_ids.*' => ['uuid', 'exists:booking_collection_commissions,id'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'payment_reference' => ['required', 'string', 'max:120', 'unique:collection_commission_payouts,payment_reference'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $payout = DB::transaction(function () use ($data, $request) {
            $commissions = BookingCollectionCommission::query()
                ->whereIn('id', $data['commission_ids'])
                ->lockForUpdate()
                ->get();
            if ($commissions->count() !== count(array_unique($data['commission_ids']))) {
                throw ValidationException::withMessages(['commission_ids' => ['One or more commissions no longer exist.']]);
            }
            if ($commissions->contains(fn ($commission) => $commission->status !== 'earned')) {
                throw ValidationException::withMessages(['commission_ids' => ['Only outstanding earned commissions can be paid. Refresh and select again.']]);
            }
            if ($commissions->pluck('staff_id')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['commission_ids' => ['Create a separate payout for each staff member.']]);
            }
            $payout = CollectionCommissionPayout::create([
                'payout_number' => 'CCP-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) \Illuminate\Support\Str::uuid(), 0, 6)),
                'period_start' => \Illuminate\Support\Carbon::parse($commissions->min('earned_at'))->toDateString(),
                'period_end' => \Illuminate\Support\Carbon::parse($commissions->max('earned_at'))->toDateString(),
                'total_amount' => round((float) $commissions->sum('commission_amount'), 2),
                'status' => 'paid',
                'paid_at' => $data['paid_at'],
                'payment_reference' => $data['payment_reference'],
                'notes' => $data['notes'] ?? null,
                'paid_by' => $request->user()->id,
            ]);
            $commissions->each->update([
                    'status' => 'paid',
                    'paid_at' => $data['paid_at'],
                    'paid_by' => $request->user()->id,
                    'payout_id' => $payout->id,
                    'payment_reference' => $data['payment_reference'],
                    'notes' => $data['notes'] ?? null,
                ]);
            return $payout;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Collection commission payout recorded.',
            'data' => $payout,
        ]);
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

    private function scopeLegacyQuery($query, Request $request): void
    {
        $staff = Staff::query()->where('user_id', $request->user()->id)->firstOrFail();
        if ($request->attributes->get('legacy_commission_self_only', false)) {
            $query->where('staff_id', $staff->id);
            return;
        }
        if ($request->user()->can('collection-commissions.view-all')) return;
        $staffIds = [$staff->id];
        if ($request->user()->can('collection-commissions.view-team')) {
            $profile = SalesProfile::query()->where('staff_id', $staff->id)->activeAt(now())->first();
            if ($profile) {
                $profileIds = DB::table('sales_reporting_assignments')->where('manager_sales_profile_id', $profile->id)
                    ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                    ->pluck('member_sales_profile_id');
                $staffIds = array_merge($staffIds, SalesProfile::query()->whereIn('id', $profileIds)->pluck('staff_id')->all());
            }
        }
        $query->whereIn('staff_id', array_values(array_unique($staffIds)));
    }

    private function scopeDecisionQuery($query, Request $request): void
    {
        $staff = Staff::query()->where('user_id', $request->user()->id)->firstOrFail();
        if ($request->attributes->get('legacy_commission_self_only', false)) {
            $query->where('beneficiary_staff_id', $staff->id);
            return;
        }
        if ($request->user()->can('collection-commissions.view-all')) return;
        $ids = [$staff->id];
        if ($request->user()->can('collection-commissions.view-team')) {
            $profile = SalesProfile::query()->where('staff_id', $staff->id)->activeAt(now())->first();
            if ($profile) {
                $memberProfileIds = DB::table('sales_reporting_assignments')->where('manager_sales_profile_id', $profile->id)
                    ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                    ->pluck('member_sales_profile_id');
                $ids = array_merge($ids, SalesProfile::query()->whereIn('id', $memberProfileIds)->pluck('staff_id')->all());
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
            'direct_payment_available' => ! config('sales.features.commission_accrual', false)
                && ! config('sales.features.payouts', false),
        ];
        $response->setData($payload);
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Link', '</api/sales/commission-statements>; rel="successor-version"');
        $response->headers->set('Warning', '299 - "Deprecated API: use /api/sales/commission-statements"');

        return $response;
    }
}
