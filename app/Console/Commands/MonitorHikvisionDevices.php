<?php

namespace App\Console\Commands;

use App\Models\Hr\Attendance\AttendanceDevice;
use App\Models\Staff;
use App\Services\Hr\Attendance\AttendanceProviderManager;
use App\Services\Hr\HrNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MonitorHikvisionDevices extends Command
{
    protected $signature = 'hr:hikvision-monitor';

    protected $description = 'Monitor Hikvision connectivity, clock drift and stale synchronization';

    public function handle(AttendanceProviderManager $p): int
    {
        AttendanceDevice::query()->where('provider', 'hikvision')->where('status', 'active')->cursor()->each(function ($d) use ($p) {
            $seen = [];
            try {
                $adapter = $p->adapterFor($d);
                $facts = $adapter->timeFacts($d);
                if (($facts['drift_seconds'] ?? 0) > 60) {
                    $this->recordAlert($d, 'clock_drift', 'high', 'Terminal clock drift is '.$facts['drift_seconds'].' seconds.');
                } else {
                    $seen[] = 'clock_drift';
                }$capacity = $adapter->capacityFacts($d);
                if (($capacity['users'] / $capacity['user_capacity']) >= .9 || ($capacity['cards'] / $capacity['card_capacity']) >= .9) {
                    $this->recordAlert($d, 'capacity', 'high', 'Terminal user or card capacity has reached 90%.');
                } else {
                    $seen[] = 'capacity';
                }
            } catch (\Throwable$e) {
                report($e);
                $this->recordAlert($d, 'offline', 'critical', 'Terminal connectivity or authentication failed: '.Str::limit($e->getMessage(), 500));
            }if (! $d->last_sync_at || $d->last_sync_at->lt(now()->subMinutes(15))) {
                $this->recordAlert($d, 'stale_sync', 'high', 'No successful attendance synchronization completed within 15 minutes.');
            } else {
                $seen[] = 'stale_sync';
            }foreach ($seen as $type) {
                DB::table('hr_attendance_device_alerts')->where('device_id', $d->id)->where('alert_type', $type)->where('status', 'open')->update(['status' => 'auto_resolved', 'resolved_at' => now(), 'resolution_note' => 'Condition cleared by automated monitoring.', 'updated_at' => now()]);
            }
        });

        return self::SUCCESS;
    }

    private function recordAlert($d, string $type, string $severity, string $message): void
    {
        $key = hash('sha256', $d->id.'|'.$type);
        $existing = DB::table('hr_attendance_device_alerts')->where('dedupe_key', $key)->first();
        $id = $existing?->id ?? (string) Str::uuid();
        DB::table('hr_attendance_device_alerts')->updateOrInsert(['dedupe_key' => $key], ['id' => $id, 'company_id' => $d->company_id, 'device_id' => $d->id, 'alert_type' => $type, 'severity' => $severity, 'status' => 'open', 'message' => $message, 'detected_at' => now(), 'resolved_at' => null, 'resolved_by' => null, 'resolution_note' => null, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
        if (! $existing) {
            Staff::query()->with('user')->where('company_id', $d->company_id)->whereNull('employment_ended_at')->get()->filter(fn ($s) => $s->user?->can('hr.attendance.devices.manage'))->each(fn ($s) => app(HrNotificationService::class)->queue($d->company_id, 'hikvision_device_alert', 'attendance_device_alert', $id, $s->id, ['site_code' => $d->site_code, 'alert_type' => $type, 'severity' => $severity, 'message' => $message]));
        }
    }
}
