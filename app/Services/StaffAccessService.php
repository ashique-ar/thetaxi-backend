<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\StaffScopeAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class StaffAccessService
{
    public function currentActorStaff(User $actor): Staff
    {
        $staff = $this->actorStaff($actor);
        abort_unless($staff, 403, 'Select an active Staff context.');

        return $staff;
    }

    public function scope(Builder $query, User $actor): Builder
    {
        if ($actor->can('staff.view-all')) {
            return $query;
        }

        $actorStaff = $this->actorStaff($actor);
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
                ->where('id', $actorStaff->id)
                ->orWhereIn('id', $teamIds));
        }

        return $actorStaff ? $query->whereKey($actorStaff->id) : $query->whereRaw('1 = 0');
    }

    public function authorize(User $actor, Staff $staff, string $action): void
    {
        $ability = match ($action) {
            'view' => 'view',
            'edit', 'update' => 'update',
            'terminate', 'delete' => 'terminate',
            default => null,
        };

        abort_unless($ability !== null, 403, 'The requested Staff action is not authorized.');
        Gate::forUser($actor)->authorize($ability, $staff);
    }

    public function allows(User $actor, Staff $staff, string $action): bool
    {
        if ($actor->can("staff.{$action}-all") || ($action === 'view' && $actor->can('staff.view-all'))) {
            return true;
        }

        $actorStaff = $this->actorStaff($actor);
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

        return $actorStaff?->id === $staff->id || $sameLegalEntity || $teamMember;
    }

    private function actorStaff(User $actor): ?Staff
    {
        $query = Staff::query()->where('user_id', $actor->id)
            ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()));
        $type = request()->header('X-Active-Context-Type');
        $contextId = request()->header('X-Active-Context-Id');

        if ($type !== null || $contextId !== null) {
            if ($type !== 'staff' || ! $contextId || ! Str::isUuid($contextId)) {
                return null;
            }

            $context = DB::table('user_contexts')->where('id', $contextId)->where('user_id', $actor->id)
                ->where('context_type', 'staff')->where('is_active', true)->whereNull('deleted_at')->first();

            return $context ? $query->whereKey($context->context_id)->first() : null;
        }

        $staff = $query->limit(2)->get();

        return $staff->count() === 1 ? $staff->first() : null;
    }
}
