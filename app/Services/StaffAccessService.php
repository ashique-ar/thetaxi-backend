<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\StaffScopeAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

class StaffAccessService
{
    public function scope(Builder $query, User $actor): Builder
    {
        if ($actor->can('staff.view-all')) {
            return $query;
        }

        $actorStaff = Staff::query()->where('user_id', $actor->id)->first();
        if ($actor->can('staff.view-legal-entity') && $actorStaff?->company_id) {
            return $query->where('company_id', $actorStaff->company_id);
        }

        if ($actor->can('staff.view-team') && $actorStaff) {
            $teamIds = StaffScopeAssignment::query()
                ->where('manager_staff_id', $actorStaff->id)
                ->where('effective_from', '<=', now())
                ->where(fn ($assignment) => $assignment->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->select('member_staff_id');

            return $query->where(fn ($staff) => $staff
                ->where('user_id', $actor->id)
                ->orWhereIn('id', $teamIds));
        }

        return $query->where('user_id', $actor->id);
    }

    public function authorize(User $actor, Staff $staff, string $action): void
    {
        if ($actor->can("staff.{$action}-all") || ($action === 'view' && $actor->can('staff.view-all'))) {
            return;
        }

        $actorStaff = Staff::query()->where('user_id', $actor->id)->first();
        $sameLegalEntity = $actor->can("staff.{$action}-legal-entity")
            && $actorStaff?->company_id
            && $actorStaff->company_id === $staff->company_id;

        $teamMember = $actor->can("staff.{$action}-team")
            && $actorStaff
            && StaffScopeAssignment::query()
                ->where('manager_staff_id', $actorStaff->id)
                ->where('member_staff_id', $staff->id)
                ->where('effective_from', '<=', now())
                ->where(fn ($assignment) => $assignment->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->exists();

        abort_unless($staff->user_id === $actor->id || $sameLegalEntity || $teamMember, Response::HTTP_FORBIDDEN, 'The requested Staff record is outside your authorized scope.');
    }
}
