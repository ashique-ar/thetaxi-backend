<?php

namespace App\Console\Commands;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
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
        

        $failed = false;
        $deviceOption = isset($this->input) ? $this->input->getOption('device') : null;
        $devices = AttendanceDevice::query()
            ->where('provider', 'hikvision')
            ->where('integration_mode', 'direct_isapi')
            ->where('status', 'active')
            ->when($deviceOption, fn ($query, $id) => $query->whereKey($id))
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
                ->select(['mapping.id as mapping_id', 'mapping.provider_person_id', 'mapping.device_id', 'mapping.staff_id'])
                ->get()
                ->groupBy('provider_person_id')
                ->map(function ($mappings) use ($device) {
                    $deviceMappings = $mappings->where('device_id', $device->id);
                    if ($deviceMappings->isNotEmpty()) {
                        return $deviceMappings->count() === 1 ? $deviceMappings->first() : null;
                    }

                    $companyMappings = $mappings->whereNull('device_id');

                    return $companyMappings->count() === 1 ? $companyMappings->first() : null;
                })
                ->filter()
                ->values();

            foreach ($rows as $row) {
                try {
                    DB::transaction(function () use ($device, $row, $today, $providers) {
                        $company = DB::table('companies')->where('id', $device->company_id)->lockForUpdate()->first();
                        if (! $company) return;
                        $mappings = DB::table('hr_attendance_person_mappings')->where('company_id', $device->company_id)
                            ->where('provider_person_id', $row->provider_person_id)->where('enrollment_status', 'verified')
                            ->where(fn ($query) => $query->where('device_id', $device->id)->orWhereNull('device_id'))
                            ->whereDate('effective_from', '<=', $today)
                            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>', $today))
                            ->orderBy('id')->lockForUpdate()->get();
                        $deviceMappings = $mappings->where('device_id', $device->id);
                        $mapping = $deviceMappings->isNotEmpty()
                            ? ($deviceMappings->count() === 1 ? $deviceMappings->first() : null)
                            : ($mappings->whereNull('device_id')->count() === 1 ? $mappings->whereNull('device_id')->first() : null);
                        if (! $mapping || $mapping->id !== $row->mapping_id) {
                            return;
                        }

                        $staff = Staff::withTrashed()->whereKey($mapping->staff_id)
                            ->where('company_id', $device->company_id)->lockForUpdate()->first();
                        if (! $staff) return;

                        $enabled = $staff->employment_ended_at === null && ! $staff->trashed();
                        $providers->adapterFor($device)->setPersonEnabled($device, $mapping->provider_person_id, $enabled);
                    });
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
