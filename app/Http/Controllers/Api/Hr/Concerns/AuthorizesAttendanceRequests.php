<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Staff;
use Illuminate\Http\Request;

/**
 * Shared legal-entity authorization and feature-flag guards used by every
 * attendance-device concern trait on {@see \App\Http\Controllers\Api\Hr\AttendanceDeviceController}.
 */
trait AuthorizesAttendanceRequests
{
    private function authorizedCompanyId(Request $request, ?string $requestedCompanyId): string
    {
        $actorCompanyId = Staff::query()->where('user_id', $request->user()->id)->value('company_id');
        abort_unless($actorCompanyId, 403, 'The authenticated user has no staff legal-entity context.');
        abort_if($requestedCompanyId && $requestedCompanyId !== $actorCompanyId, 403, 'Attendance data is outside your legal entity.');

        return $actorCompanyId;
    }

    private function requireAttendanceWrites(): void
    {
        abort_unless(config('hr.features.attendance_ingestion', false), 409, 'Attendance ingestion writes are not enabled.');
    }
}
