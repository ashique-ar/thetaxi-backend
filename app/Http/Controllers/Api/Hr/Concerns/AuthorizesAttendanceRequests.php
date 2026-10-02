<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Staff;
use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use Illuminate\Http\Request;

/**
 * Shared legal-entity authorization and feature-flag guards used by every
 * attendance-device concern trait on {@see \App\Http\Controllers\Api\Hr\AttendanceDeviceController}.
 */
trait AuthorizesAttendanceRequests
{
    private function authorizedCompanyId(Request $request, ?string $requestedCompanyId): string
    {
        $actorCompanyIds = $this->authorizedCompanyIds($request);
        abort_unless($actorCompanyIds->isNotEmpty(), 403, 'The authenticated user has no active Staff legal-entity context.');
        if ($requestedCompanyId) {
            abort_unless($actorCompanyIds->contains($requestedCompanyId), 403, 'Attendance data is outside your legal entity.');
        } else {
            abort_unless($actorCompanyIds->count() === 1, 403, 'Select an authorized Staff legal entity for attendance access.');
        }

        return $requestedCompanyId ?: (string) $actorCompanyIds->first();
    }

    private function authorizedCompanyIds(Request $request)
    {
        if ($request->user()->can('staff.view-all')) {
            return Company::query()->where('is_active', true)->orderBy('name')->pluck('id');
        }

        $staffCompanyIds = Staff::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('company_id')
            ->where(fn ($employment) => $employment
                ->whereNull('employment_ended_at')
                ->orWhere('employment_ended_at', '>', now()))
            ->select('company_id');

        return Company::query()->where('is_active', true)->whereIn('id', $staffCompanyIds)
            ->distinct()->orderBy('name')->pluck('id');
    }

    private function authorizedDevice(Request $request, string $deviceId, ?string $companyId = null): AttendanceDevice
    {
        $companyIds = $companyId
            ? collect([$this->authorizedCompanyId($request, $companyId)])
            : $this->authorizedCompanyIds($request);
        $device = AttendanceDevice::query()->whereIn('company_id', $companyIds)->find($deviceId);
        abort_unless($device, 404);

        return $device;
    }

    private function requireAttendanceWrites(): void
    {
        abort_unless(config('hr.features.attendance_ingestion', false), 409, 'Attendance ingestion writes are not enabled.');
    }
}
