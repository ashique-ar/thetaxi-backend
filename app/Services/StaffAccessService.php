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

        $actorStaff = $this->actorStaffs($actor);
        if ($actorStaff->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->can('staff.view-legal-entity')) {
            return $query->whereIn('company_id', $actorStaff->pluck('company_id')->filter()->unique());
        }

        if ($actor->can('staff.view-team')) {
            $teamIds = StaffScopeAssignment::query()
                ->whereIn('manager_staff_id', $actorStaff->pluck('id'))
                ->where('effective_from', '<=', now())
                ->where(fn ($assignment) => $assignment->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->select('member_staff_id');

            return $query->where(fn ($staff) => $staff
                ->whereIn('id', $actorStaff->pluck('id'))
                ->orWhereIn('id', $teamIds));
        }

        return $query->whereIn('id', $actorStaff->pluck('id'));
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

        $actorStaff = $this->actorStaffs($actor);
        $sameLegalEntity = $actor->can("staff.{$action}-legal-entity")
            && $actorStaff->contains(fn (Staff $identity) => $identity->company_id === $staff->company_id);

        $teamMember = $actor->can("staff.{$action}-team")
            && $actorStaff->isNotEmpty()
            && StaffScopeAssignment::query()
                ->whereIn('manager_staff_id', $actorStaff->pluck('id'))
                ->where('member_staff_id', $staff->id)
                ->where('effective_from', '<=', now())
                ->where(fn ($assignment) => $assignment->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->exists();

        return $actorStaff->contains('id', $staff->id) || $sameLegalEntity || $teamMember;
    }

    private function actorStaff(User $actor): ?Staff
    {
        $staff = $this->actorStaffs($actor);

        return $staff->count() === 1 ? $staff->first() : null;
    }

    private function actorStaffs(User $actor)
    {
        $contexts = DB::table('user_contexts')->where('user_id', $actor->id)
            ->where('context_type', 'staff')->where('is_active', true)->whereNull('deleted_at');
        $type = request()->header('X-Active-Context-Type');
        $contextId = request()->header('X-Active-Context-Id');

        if ($type === 'staff') {
            if (! $contextId || ! Str::isUuid($contextId)) {
                return collect();
            }
            $contexts->where('id', $contextId);
        } elseif ($type !== null && $type !== 'internal') {
            return collect();
        }

        $ids = $contexts->pluck('context_id');
        return Staff::query()->where('user_id', $actor->id)->whereIn('id', $ids)
            ->where(fn ($employment) => $employment->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->get();
    }
}
