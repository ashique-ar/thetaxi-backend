<?php

namespace App\Services\Hr\Attendance;

use App\Contracts\Hr\Attendance\AttendanceProviderAdapter;
use App\Models\Hr\Attendance\AttendanceDevice;
use InvalidArgumentException;

/**
 * Resolves the correct {@see AttendanceProviderAdapter} implementation for a
 * given device's provider/integration_mode pair. This is the seam the PULL
 * attendance path polls through — {@see DirectAttendanceSyncService} and the
 * various Hikvision console commands/controllers call
 * {@see AttendanceProviderManager::adapterFor()} to reach out to a device
 * directly (discover, sync events, manage people/credentials) — as opposed
 * to the PUSH path ({@see AttendanceIngestionService}), where the device
 * sends events to us and no adapter lookup is needed.
 */
class AttendanceProviderManager
{
    public function adapterFor(AttendanceDevice $device): AttendanceProviderAdapter
    {
        if ($device->provider === 'hikvision' && $device->integration_mode === 'direct_isapi') {
            return app(HikvisionIsapiAdapter::class);
        }

        throw new InvalidArgumentException("No attendance adapter supports {$device->provider}/{$device->integration_mode}.");
    }
}
