<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Hr\Attendance\AttendanceDevice;

/**
 * Shared attendance device and feature-flag guards used by every
 * attendance-device concern trait on {@see \App\Http\Controllers\Api\Hr\AttendanceDeviceController}.
 */
trait AuthorizesAttendanceRequests
{
    private function authorizedDevice(string $deviceId): AttendanceDevice
    {
        $device = AttendanceDevice::query()->find($deviceId);
        abort_unless($device, 404);

        return $device;
    }

    private function lockActiveDirectIsapiDevice(AttendanceDevice $device): AttendanceDevice
    {
        $locked = AttendanceDevice::query()->where('status', 'active')
            ->where('integration_mode', 'direct_isapi')->lockForUpdate()->find($device->id);
        abort_unless($locked, 409, 'The attendance device must remain active for direct ISAPI writes.');

        return $locked;
    }

    
}
