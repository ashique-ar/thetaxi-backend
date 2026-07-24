<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\BookingCollectionCommission;
use App\Models\Booking\CollectionCommissionPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionCommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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
        $totals = (clone $query)->selectRaw(
            "COALESCE(SUM(receipt_amount),0) receipt_total,
             COALESCE(SUM(eligible_amount),0) eligible_total,
             COALESCE(SUM(CASE WHEN status IN ('earned','paid') THEN commission_amount ELSE 0 END),0) earned_total,
             COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END),0) paid_total,
             COALESCE(SUM(CASE WHEN status = 'earned' THEN commission_amount ELSE 0 END),0) outstanding_total"
        )->first();
        $rows = $query->latest('earned_at')->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'status' => 'success',
            'data' => $rows,
            'summary' => [
                'receipt_total' => (float) $totals->receipt_total,
                'eligible_total' => (float) $totals->eligible_total,
                'earned_total' => (float) $totals->earned_total,
                'paid_total' => (float) $totals->paid_total,
                'outstanding_total' => (float) $totals->outstanding_total,
            ],
        ]);
    }

    public function markPaid(Request $request): JsonResponse
    {
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
}
