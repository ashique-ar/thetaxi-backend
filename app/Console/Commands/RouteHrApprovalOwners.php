<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Hr\Ess\HrDomainRequestProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RouteHrApprovalOwners extends Command
{
    protected $signature = 'hr:route-approval-owners {--commit : Apply due primary-manager approval handoffs}';
    protected $description = 'Preview or apply approval handoffs after effective HR reporting-line changes.';

    public function handle(HrDomainRequestProjectionService $projection): int
    {
        $actorId = (string) config('hr.system_user_id');
        if ($this->option('commit') && ! User::query()->whereKey($actorId)->exists()) {
            $this->error('HR_SYSTEM_USER_ID must identify an existing system actor before committed processing.');
            return self::FAILURE;
        }

        $staffIds = DB::table('hr_request_index')->whereIn('source_type', ['work_request', 'timesheet', 'attendance_correction'])
            ->whereIn('status', ['pending', 'pending_approval', 'submitted', 'returned'])
            ->whereNotNull('current_owner_staff_id')->distinct()->orderBy('requester_staff_id')->pluck('requester_staff_id');
        $count = 0;
        foreach ($staffIds as $staffId) $count += $projection->reroutePrimaryManagerApprovals((string) $staffId, $actorId, (bool) $this->option('commit'));

        $this->info(($this->option('commit') ? 'Rerouted ' : 'Would reroute ').$count.' approval(s) after effective reporting-line changes.');
        return self::SUCCESS;
    }
}
