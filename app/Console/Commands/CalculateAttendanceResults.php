<?php

namespace App\Console\Commands;

use App\Services\Hr\Attendance\AttendanceResultService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateAttendanceResults extends Command
{
    protected $signature = 'hr:attendance-calculate {--days=2 : Recalculate this many calendar days, including today}';

    protected $description = 'Calculate daily attendance for approved roster assignments';

    public function handle(AttendanceResultService $results): int
    {
        if (! config('hr.features.attendance_results', false)) {
            $this->warn('Attendance result calculation is disabled.');
            return self::SUCCESS;
        }

        $days = max(1, min(31, (int) $this->option('days')));
        $to = CarbonImmutable::today();
        $from = $to->subDays($days - 1);
        $rosters = DB::table('hr_roster_assignments')
            ->whereNotNull('approved_at')
            ->whereDate('effective_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $from))
            ->get(['company_id', 'staff_id', 'effective_from', 'effective_until']);

        $calculated = 0;
        $skipped = 0;
        foreach ($rosters as $roster) {
            for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                if ($date->lt(CarbonImmutable::parse($roster->effective_from)) || ($roster->effective_until && $date->gte(CarbonImmutable::parse($roster->effective_until)))) {
                    continue;
                }
                try {
                    $results->calculate($roster->company_id, $roster->staff_id, $date->toDateString());
                    $calculated++;
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                    $skipped++;
                    $this->warn("{$roster->staff_id}/{$date->toDateString()}: {$exception->getMessage()}");
                }
            }
        }

        $this->info("Attendance calculation complete: {$calculated} calculated, {$skipped} skipped.");
        return self::SUCCESS;
    }
}
