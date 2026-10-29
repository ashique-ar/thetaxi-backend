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
                ->where(fn ($company) => CompanyIdScope::constrain($company, 'staff.company_id', $companyId))
                ->whereNull('staff.deleted_at')
                ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
                ->exists();
            abort_unless($selectedContextMatches, 403, 'These settings are outside your selected Staff business.');

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
        if ((string) $user->company_id === $companyId) {
            return;
        }

        $contexts = DB::table('user_contexts as context')
            ->join('staff', 'staff.id', '=', 'context.context_id')
            ->where('context.user_id', $user->id)
            ->where('context.context_type', 'staff')
            ->where('context.is_active', true)
            ->whereNull('context.deleted_at')
            ->where('staff.user_id', $user->id)
            ->where(fn ($company) => CompanyIdScope::constrain($company, 'staff.company_id', $companyId))
            ->whereNull('staff.deleted_at')
            ->where(fn ($employment) => $employment->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()));

        if ($contextType === 'internal') {
            $contexts->whereRaw('1 = 0');
        }

        abort_unless($contexts->exists(), 403, 'These settings are outside your business access.');
    }
}
