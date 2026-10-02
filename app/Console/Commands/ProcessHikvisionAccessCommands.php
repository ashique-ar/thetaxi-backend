<?php

namespace App\Console\Commands;

use App\Models\Hr\Attendance\AttendanceDevice;
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
        }$failed = false;
        DB::table('hr_attendance_access_commands')->whereIn('status', ['approved_pending_delivery', 'retry_pending'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('approved_at')->limit(100)->get()->each(function ($command) use ($providers, &$failed) {
            $attempt = (int) $command->attempt_count + 1;
            $id = (string) Str::uuid();
            try {
                $device = AttendanceDevice::query()->findOrFail($command->device_id);
                $mapping = DB::table('hr_attendance_person_mappings')->where('device_id', $device->id)->where('staff_id', $command->staff_id)->where('enrollment_status', 'verified')->whereNull('effective_until')->first();
                if (! $mapping) {
                    throw new \RuntimeException('No active verified terminal mapping exists.');
                }$group = $command->access_group_code ? DB::table('hr_attendance_access_groups')->where('device_id', $device->id)->where('code', $command->access_group_code)->where('status', 'active')->first() : null;
                if (in_array($command->command_type, ['grant', 'restore'], true) && ! $group) {
                    throw new \RuntimeException('The approved access group is not active.');
                }$result = $providers->adapterFor($device)->applyAccess($device, $mapping->provider_person_id, $group?->door_no, $group?->plan_template_no);
                DB::transaction(function () use ($command, $attempt, $id, $result) {
                    DB::table('hr_attendance_access_delivery_attempts')->insert(['id' => $id, 'command_id' => $command->id, 'attempt_no' => $attempt, 'status' => 'delivered', 'provider_result' => json_encode($result, JSON_THROW_ON_ERROR), 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('hr_attendance_access_commands')->where('id', $command->id)->update(['status' => 'delivered_reconciled', 'attempt_count' => $attempt, 'delivered_at' => now(), 'reconciled_at' => now(), 'provider_reference' => 'isapi-userinfo-modify', 'provider_result' => json_encode($result, JSON_THROW_ON_ERROR), 'failure_message' => null, 'updated_at' => now()]);
                });
            } catch (\Throwable$e) {
                report($e);
                $failed = true;
                $dead = $attempt >= 5;
                DB::transaction(function () use ($command, $attempt, $id, $e, $dead) {
                    DB::table('hr_attendance_access_delivery_attempts')->insert(['id' => $id, 'command_id' => $command->id, 'attempt_no' => $attempt, 'status' => $dead ? 'dead_letter' : 'failed', 'error_summary' => Str::limit($e->getMessage(), 2000), 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('hr_attendance_access_commands')->where('id', $command->id)->update(['status' => $dead ? 'dead_letter' : 'retry_pending', 'attempt_count' => $attempt, 'next_attempt_at' => $dead ? null : now()->addMinutes(min(60, 2 ** $attempt)), 'failure_message' => Str::limit($e->getMessage(), 2000), 'updated_at' => now()]);
                });
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
