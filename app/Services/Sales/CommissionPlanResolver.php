<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesCommissionPlanAssignment;
use App\Models\Sales\SalesProfile;
use Illuminate\Support\Collection;

class CommissionPlanResolver
{
    public function freezeFamilyForAttribution(SalesBookingAttribution $attribution): ?SalesBookingAttribution
    {
        if (! config('sales.features.commission_shadow', false) && ! config('sales.features.commission_accrual', false)) {
            return $attribution;
        }
        if ($attribution->commission_plan_family_id) {
            return $attribution;
        }
        $profile = $attribution->acquisition_sales_profile_id
            ? SalesProfile::query()->with('staff')->find($attribution->acquisition_sales_profile_id)
            : null;
        if (! $profile) {
            return null;
        }
        $matches = $this->assignmentCandidates($attribution, $profile);
        if ($matches->isEmpty()) {
            return null;
        }
        $highest = (int) $matches->max('precedence');
        $winners = $matches->where('precedence', $highest)->values();
        if ($winners->count() !== 1) {
            return null;
        }
        $assignment = $winners->first();
        $attribution->update([
            'commission_plan_family_id' => $assignment->plan_family_id,
            'commission_plan_assignment_id' => $assignment->id,
            'commission_plan_resolved_at' => $attribution->secured_at,
        ]);

        return $attribution->fresh();
    }

    /** @return Collection<int, SalesCommissionPlanAssignment> */
    private function assignmentCandidates(SalesBookingAttribution $attribution, SalesProfile $profile): Collection
    {
        $staff = $profile->staff;
        return SalesCommissionPlanAssignment::query()
            ->where('sales_commission_plan_assignments.company_id', $attribution->company_id)
            ->where('sales_commission_plan_assignments.status', 'approved')
            ->where('sales_commission_plan_assignments.effective_from', '<=', $attribution->secured_at)
            ->where(fn ($q) => $q->whereNull('sales_commission_plan_assignments.effective_until')
                ->orWhere('sales_commission_plan_assignments.effective_until', '>', $attribution->secured_at))
            ->whereHas('planFamily', fn ($q) => $q->where('status', 'approved')
                ->where('commission_category', $attribution->commission_category))
            ->where(function ($q) use ($profile, $staff) {
                $q->where('scope_type', 'company')
                    ->orWhere(fn ($profileScope) => $profileScope->where('scope_type', 'sales_profile')->where('sales_profile_id', $profile->id))
                    ->orWhere(fn ($employeeScope) => $employeeScope->where('scope_type', 'employee')->where('staff_id', $staff?->id))
                    ->orWhere(fn ($categoryScope) => $categoryScope->where('scope_type', 'staff_category')->where('staff_category', $staff?->staff_type));
            })
            ->get();
    }
}
