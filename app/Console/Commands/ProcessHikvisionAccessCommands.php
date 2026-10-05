<?php

namespace App\Console\Commands;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessHikvisionAccessCommands extends Command
{
    protected $signature = 'hr:hikvision-access-deliver';

    protected $description = 'Deliver approved Hikvision physical-access commands';

    public function handle(AttendanceProviderManager $providers): int
    {
        if (! config('hr.features.physical_access_commands')) {
            return self::SUCCESS;
        }
        $staleSeconds = max(60, ((int) config('hr.hikvision.connect_timeout_seconds', 3) + (int) config('hr.hikvision.request_timeout_seconds', 10)) * 3 + 5);
        DB::table('hr_attendance_access_commands')->where('status', 'delivering')->where('updated_at', '<=', now()->subSeconds($staleSeconds))
            ->update(['status' => 'delivery_unknown', 'failure_message' => 'Worker claim expired; verify the terminal before taking further action.', 'updated_at' => now()]);
        $failed = false;
        DB::table('hr_attendance_access_commands')->whereIn('status', ['approved_pending_delivery', 'retry_pending'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('approved_at')->limit(100)->get()->each(function ($command) use ($providers, &$failed) {
            $attempt = (int) $command->attempt_count + 1;
            $id = (string) Str::uuid();
            $claimed = DB::table('hr_attendance_access_commands')->where('id', $command->id)
                ->whereIn('status', ['approved_pending_delivery', 'retry_pending'])
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->update(['status' => 'delivering', 'attempt_count' => $attempt, 'updated_at' => now()]);
            if (! $claimed) return;

            $providerAttempted = false;
            $blocked = false;
            try {
                DB::transaction(function () use ($command, $attempt, $id, $providers, &$providerAttempted, &$blocked) {
                    $locked = DB::table('hr_attendance_access_commands')->where('id', $command->id)
                        ->where('attempt_count', $attempt)->where('status', 'delivering')->lockForUpdate()->first();
                    if (! $locked) return;

                    $device = AttendanceDevice::query()->where('company_id', $locked->company_id)->findOrFail($locked->device_id);
                    $staff = Staff::withTrashed()->whereKey($locked->staff_id)->where('company_id', $locked->company_id)->lockForUpdate()->first();
                    if (! $staff || (in_array($locked->command_type, ['grant', 'restore'], true) && ($staff->trashed() || filled($staff->employment_ended_at)))) {
                        $blocked = true;
                        throw new \RuntimeException('Staff is no longer eligible for this physical-access command.');
                    }
                    $today = now($device->timezone)->toDateString();
                    $mapping = DB::table('hr_attendance_person_mappings')->where('company_id', $locked->company_id)
                        ->where('device_id', $device->id)->where('staff_id', $locked->staff_id)->where('enrollment_status', 'verified')
                        ->whereDate('effective_from', '<=', $today)
                        ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>', $today))->first();
                    if (! $mapping) throw new \RuntimeException('No active verified terminal mapping exists.');
                    $group = $locked->access_group_code ? DB::table('hr_attendance_access_groups')->where('company_id', $locked->company_id)->where('device_id', $device->id)->where('code', $locked->access_group_code)->where('status', 'active')->first() : null;
                    if (in_array($locked->command_type, ['grant', 'restore'], true) && ! $group) throw new \RuntimeException('The approved access group is not active.');

                    $providerAttempted = true;
                    $result = $providers->adapterFor($device)->applyAccess($device, $mapping->provider_person_id, $group?->door_no, $group?->plan_template_no);
                    DB::table('hr_attendance_access_delivery_attempts')->insert(['id' => $id, 'command_id' => $command->id, 'attempt_no' => $attempt, 'status' => 'provider_accepted', 'provider_result' => json_encode($result, JSON_THROW_ON_ERROR), 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('hr_attendance_access_commands')->where('id', $command->id)->where('attempt_count', $attempt)->where('status', 'delivering')->update(['status' => 'provider_accepted_unverified', 'delivered_at' => now(), 'provider_reference' => 'isapi-userinfo-modify', 'provider_result' => json_encode($result, JSON_THROW_ON_ERROR), 'failure_message' => null, 'updated_at' => now()]);
                });
            } catch (\Throwable$e) {
                report($e);
                $failed = true;
                $status = $providerAttempted ? 'delivery_unknown' : ($blocked ? 'blocked' : ($attempt >= 5 ? 'dead_letter' : 'retry_pending'));
                DB::transaction(function () use ($command, $attempt, $id, $e, $status) {
                    DB::table('hr_attendance_access_delivery_attempts')->insert(['id' => $id, 'command_id' => $command->id, 'attempt_no' => $attempt, 'status' => $status, 'error_summary' => Str::limit($e->getMessage(), 2000), 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('hr_attendance_access_commands')->where('id', $command->id)->where('attempt_count', $attempt)->whereIn('status', ['delivering', 'delivery_unknown'])->update(['status' => $status, 'next_attempt_at' => $status === 'retry_pending' ? now()->addMinutes(min(60, 2 ** $attempt)) : null, 'failure_message' => Str::limit($e->getMessage(), 2000), 'updated_at' => now()]);
                });
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
