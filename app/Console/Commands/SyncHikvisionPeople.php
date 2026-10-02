<?php

namespace App\Console\Commands;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncHikvisionPeople extends Command
{
    protected $signature = 'hr:hikvision-people-sync {--device=}';

    protected $description = 'Synchronize mapped Staff employment status without changing Hikvision display names';

    public function handle(AttendanceProviderManager $providers): int
    {
        if (! config('hr.features.attendance_ingestion')) {
            $this->warn('Attendance ingestion is disabled.');

            return self::SUCCESS;
        }

        $failed = false;
        $devices = AttendanceDevice::query()
            ->where('provider', 'hikvision')
            ->where('integration_mode', 'direct_isapi')
            ->where('status', 'active')
            ->when($this->option('device'), fn ($query, $id) => $query->whereKey($id))
            ->cursor();

        foreach ($devices as $device) {
            $today = CarbonImmutable::now($device->timezone)->toDateString();
            $rows = DB::table('hr_attendance_person_mappings as mapping')
                ->join('staff', 'staff.id', '=', 'mapping.staff_id')
                ->where('mapping.company_id', $device->company_id)
                ->where('mapping.enrollment_status', 'verified')
                ->where(fn ($query) => $query->where('mapping.device_id', $device->id)->orWhereNull('mapping.device_id'))
                ->whereDate('mapping.effective_from', '<=', $today)
                ->where(fn ($query) => $query->whereNull('mapping.effective_until')->orWhereDate('mapping.effective_until', '>', $today))
                ->select(['mapping.provider_person_id', 'staff.employment_ended_at', 'staff.deleted_at'])
                ->get()
                ->unique('provider_person_id');

            foreach ($rows as $row) {
                $enabled = $row->employment_ended_at === null && $row->deleted_at === null;
                try {
                    $providers->adapterFor($device)->setPersonEnabled($device, $row->provider_person_id, $enabled);
                } catch (\Throwable $exception) {
                    report($exception);
                    $failed = true;
                    $this->error("{$device->site_code}/{$row->provider_person_id}: {$exception->getMessage()}");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
