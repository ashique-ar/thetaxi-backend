<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesCommissionCycleAssignment;
use App\Models\Sales\SalesCommissionCycleVersion;
use App\Models\Sales\SalesProfile;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class CommissionCycleResolver
{
    /** @return array{assignment:SalesCommissionCycleAssignment,cycle:SalesCommissionCycleVersion} */
    public function resolve(SalesProfile $profile, CarbonInterface $at): array
    {
        $profile->loadMissing('staff');
        $matches = SalesCommissionCycleAssignment::query()
            ->where('company_id', $profile->company_id)->where('status', 'approved')
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->where(function ($q) use ($profile) {
                $q->where('scope_type', 'company')
                    ->orWhere(fn ($scope) => $scope->where('scope_type', 'sales_profile')->where('sales_profile_id', $profile->id))
                    ->orWhere(fn ($scope) => $scope->where('scope_type', 'employee')->where('staff_id', $profile->staff_id))
                    ->orWhere(fn ($scope) => $scope->where('scope_type', 'staff_category')->where('staff_category', $profile->staff?->staff_type));
            })->get();
        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['cycle' => ['No approved commission-cycle assignment is effective for this Staff member.']]);
        }
        $highest = (int) $matches->max('precedence');
        $winners = $matches->where('precedence', $highest)->values();
        if ($winners->count() !== 1) {
            throw ValidationException::withMessages(['cycle' => ['Multiple commission-cycle assignments have equal winning precedence.']]);
        }
        $assignment = $winners->first();
        $cycle = SalesCommissionCycleVersion::query()->whereKey($assignment->cycle_version_id)
            ->where('company_id', $profile->company_id)->where('status', 'approved')
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))->first();
        if (! $cycle) {
            throw ValidationException::withMessages(['cycle' => ['The assigned commission-cycle version is not approved and effective.']]);
        }
        return compact('assignment', 'cycle');
    }
}
