<?php

namespace App\Console\Commands;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessHikvisionMaintenance extends Command
{
    protected $signature = 'hr:hikvision-maintenance';

    protected $description = 'Execute approved restricted Hikvision maintenance and verify recovery';

    public function handle(AttendanceProviderManager $providers): int
    {
        if (! config('hr.features.hikvision_maintenance_commands')) {
            return self::SUCCESS;
        }

        DB::table('hr_attendance_device_maintenance_commands')->where('status', 'approved_pending_execution')->orderBy('approved_at')->limit(10)->get()->each(function ($command) use ($providers) {
            $device = AttendanceDevice::query()->find($command->device_id);
            try {
                $result = $providers->adapterFor($device)->reboot($device);
                DB::table('hr_attendance_device_maintenance_commands')->where('id', $command->id)->update(['status' => 'recovery_pending', 'executed_at' => now(), 'offline_detected_at' => now(), 'provider_result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            } catch (\Throwable $exception) {
                report($exception);
                DB::table('hr_attendance_device_maintenance_commands')->where('id', $command->id)->update(['status' => 'failed', 'failure_message' => Str::limit($exception->getMessage(), 2000), 'updated_at' => now()]);
            }
        });

        DB::table('hr_attendance_device_maintenance_commands')->where('status', 'recovery_pending')->where('executed_at', '<=', now()->subSeconds(20))->get()->each(function ($command) use ($providers) {
            $device = AttendanceDevice::query()->find($command->device_id);
            try {
                $facts = $providers->adapterFor($device)->discover($device);
                if (! hash_equals($device->serial_number, $facts['serial_number'])) {
                    throw new \RuntimeException('Recovery probe reached a different terminal identity.');
                }
                DB::table('hr_attendance_device_maintenance_commands')->where('id', $command->id)->update(['status' => 'recovered_verified', 'recovered_at' => now(), 'failure_message' => null, 'updated_at' => now()]);
            } catch (\Throwable $exception) {
                if (now()->diffInMinutes($command->executed_at) >= 10) {
                    DB::table('hr_attendance_device_maintenance_commands')->where('id', $command->id)->update(['status' => 'recovery_failed', 'failure_message' => Str::limit($exception->getMessage(), 2000), 'updated_at' => now()]);
                }
            }
        });

        return self::SUCCESS;
    }
}
