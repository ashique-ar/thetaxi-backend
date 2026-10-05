<?php

namespace App\Http\Controllers\Api\Hr\Concerns;

use App\Models\Staff;
use App\Models\Company;
use App\Models\Hr\Attendance\AttendanceDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            return Company::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('id');
        }

        $staffQuery = Staff::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('company_id')
            ->where(fn ($employment) => $employment
                ->whereNull('employment_ended_at')
                ->orWhere('employment_ended_at', '>', now()));

        $contextType = $request->header('X-Active-Context-Type');
        $contextId = $request->header('X-Active-Context-Id');
        if ($contextType !== null || $contextId !== null) {
            abort_unless($contextType === 'staff' && $contextId && Str::isUuid($contextId), 403, 'Select an active Staff context.');
            $context = DB::table('user_contexts')->where('id', $contextId)->where('user_id', $request->user()->id)
                ->where('context_type', 'staff')->where('is_active', true)->whereNull('deleted_at')->first();
            abort_unless($context, 403, 'Select an active Staff context.');
            $staffQuery->whereKey($context->context_id);
        } else {
            $contexts = DB::table('user_contexts')->where('user_id', $request->user()->id)
                ->where('context_type', 'staff')->where('is_active', true)->whereNull('deleted_at')
                ->limit(2)->get(['context_id']);
            abort_unless($contexts->count() === 1, 403, 'Select an active Staff context.');
            $staffQuery->whereKey($contexts->first()->context_id);
        }

        $companyId = $staffQuery->value('company_id');
        abort_unless($companyId, 403, 'The authenticated user has no active Staff legal-entity context.');

        return Company::query()->whereKey($companyId)->where('is_active', true)->pluck('id');
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
