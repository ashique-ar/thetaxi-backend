<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Hr\Ess\HrDomainRequestProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProjectHrRequests extends Command
{
    protected $signature = 'hr:project-requests {--company=} {--commit}';
    protected $description = 'Preview or idempotently project existing HR domain requests into the ESS request index.';

    public function handle(HrDomainRequestProjectionService $projection): int
    {
        $sources = [
            ['hr_leave_requests', 'leave', ['draft']],
            ['hr_work_requests', 'work', ['draft']],
            ['hr_timesheets', 'timesheet', ['draft']],
            ['hr_attendance_correction_requests', 'attendanceCorrection', []],
        ];
        $counts = [];
        foreach ($sources as [$table, $method, $excluded]) {
            $query = DB::table($table)->when($this->option('company'), fn ($q, $company) => $q->where('company_id', $company));
            if ($excluded) $query->whereNotIn('status', $excluded);
            $counts[$table] = $query->count();
        }
        foreach ($counts as $table => $count) $this->line($table.': '.$count);
        if (! $this->option('commit')) return self::SUCCESS;
        if (! config('hr.features.employee_self_service', false)) { $this->error('HR employee self-service is disabled.'); return self::FAILURE; }
        $actor = User::query()->find(config('hr.system_user_id'));
        if (! $actor) { $this->error('HR_SYSTEM_USER_ID must identify an existing system actor.'); return self::FAILURE; }
        foreach ($sources as [$table, $method, $excluded]) {
            DB::table($table)->when($this->option('company'), fn ($q, $company) => $q->where('company_id', $company))->when($excluded, fn ($q) => $q->whereNotIn('status', $excluded))->orderBy('id')->chunk(250, function ($rows) use ($projection, $method, $actor) { foreach ($rows as $row) $projection->{$method}($row, $actor->id); });
        }
        return self::SUCCESS;
    }
}
