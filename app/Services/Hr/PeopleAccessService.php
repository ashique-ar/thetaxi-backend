<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmploymentAssignment;
use App\Models\Hr\HrReportingLine;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

class PeopleAccessService
{
    public function scope(Builder $query, User $actor): Builder
    {
        $actorStaff = Staff::query()->where('user_id', $actor->id)->first();
        abort_unless($actorStaff?->company_id, Response::HTTP_FORBIDDEN, 'An active internal Staff identity is required.');

        $query->where('company_id', $actorStaff->company_id);

        if ($actor->can('hr.people.view-all')) {
            return $query;
        }

        if ($actor->can('hr.people.view-team')) {
            $at = now();
            $reportingMembers = HrReportingLine::query()
                ->where('company_id', $actorStaff->company_id)
                ->where('manager_staff_id', $actorStaff->id)
                ->whereIn('line_type', ['primary', 'dotted_line'])
                ->where('effective_from', '<=', $at)
                ->where(fn (Builder $line) => $line->whereNull('effective_until')->orWhere('effective_until', '>', $at))
                ->select('member_staff_id');
            $assignmentMembers = HrEmploymentAssignment::query()
                ->where('company_id', $actorStaff->company_id)
                ->whereNotExists(fn ($governed) => $governed->selectRaw('1')->from('hr_reporting_lines as governed_lines')
                    ->whereColumn('governed_lines.member_staff_id', 'hr_employment_assignments.staff_id')
                    ->whereColumn('governed_lines.company_id', 'hr_employment_assignments.company_id')
                    ->whereIn('governed_lines.line_type', ['primary', 'dotted_line']))
                ->where(fn (Builder $assignment) => $assignment
                    ->where('manager_staff_id', $actorStaff->id)
                    ->orWhere('dotted_line_manager_staff_id', $actorStaff->id))
                ->where('effective_from', '<=', $at)
                ->where(fn (Builder $assignment) => $assignment->whereNull('effective_until')->orWhere('effective_until', '>', $at))
                ->select('staff_id');

            return $query->where(fn (Builder $staff) => $staff
                ->whereKey($actorStaff->id)
                ->orWhereIn('id', $reportingMembers)
                ->orWhereIn('id', $assignmentMembers));
        }

        return $query->whereKey($actorStaff->id);
    }

    public function authorize(User $actor, Staff $staff): void
    {
        $allowed = $this->scope(Staff::withTrashed()->whereKey($staff->id), $actor)->exists();
        abort_unless($allowed, Response::HTTP_FORBIDDEN, 'The requested employee is outside your authorized People scope.');
    }

    public function actorCompanyId(User $actor): string
    {
        $actorStaff = Staff::query()->where('user_id', $actor->id)->first();
        abort_unless($actorStaff?->company_id, Response::HTTP_FORBIDDEN, 'An active internal Staff identity is required.');

        return $actorStaff->company_id;
    }
}
