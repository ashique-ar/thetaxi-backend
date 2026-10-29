<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class InquiryAssignmentService
{
    public function eligibleAssignees(string $companyId)
    {
        return DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.company_id', $companyId)->whereNull('staff.deleted_at')
            ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
            ->whereNull('users.deleted_at')->where('users.is_active', true)
            ->whereIn('users.id', User::permission('inquiries.view')->select('id'));
    }

    public function eligibleAssigneeIds(string $companyId): array
    {
        return $this->eligibleAssignees($companyId)->pluck('users.id')->map(fn ($id) => (string) $id)->all();
    }
}
