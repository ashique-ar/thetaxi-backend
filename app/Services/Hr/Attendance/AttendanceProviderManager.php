<?php

namespace App\Services\Hr\Attendance;

use App\Contracts\Hr\Attendance\AttendanceProviderAdapter;
use App\Models\Hr\Attendance\AttendanceDevice;
use InvalidArgumentException;

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
