<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanySettingsAccessService
{
    public function assertCanAccess(User $user, string $companyId, bool $manage = false): void
    {
        $contextType = request()->header('X-Active-Context-Type');
        if ($contextType === 'staff') {
            $contextId = request()->header('X-Active-Context-Id');
            $selectedContextMatches = $contextId && DB::table('user_contexts as context')
                ->join('staff', 'staff.id', '=', 'context.context_id')
                ->where('context.id', $contextId)
                ->where('context.user_id', $user->id)
                ->where('context.context_type', 'staff')
                ->where('context.is_active', true)
                ->whereNull('context.deleted_at')
                ->where('staff.user_id', $user->id)
                ->whereNull('staff.deleted_at')
                ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
                ->exists();
            abort_unless($selectedContextMatches, 403, 'Select a valid active Staff context.');

            return;
        }
        if ($contextType !== null && $contextType !== 'internal') {
            abort(403, 'Select a valid business context before changing these settings.');
        }
        if ($contextType === 'internal' && $user->can('staff.view-all')) {
            return;
        }
        if ($user->can('companies.view') && (! $manage || $user->can('companies.edit'))) {
            return;
        }
        abort(403, 'You do not have permission to access company settings.');
    }
}
