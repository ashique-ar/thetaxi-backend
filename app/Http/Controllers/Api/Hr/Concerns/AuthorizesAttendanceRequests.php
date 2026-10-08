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
            $defaultCompanyId = app(\App\Services\SingleCompanyScope::class)->activeDefaultCompany()?->id;
            $requestedCompanyId = $defaultCompanyId && $actorCompanyIds->contains($defaultCompanyId)
                ? (string) $defaultCompanyId
                : ($actorCompanyIds->count() === 1 ? (string) $actorCompanyIds->first() : null);
            abort_unless($requestedCompanyId, 403, 'No authorized default legal entity is available for attendance access.');
        }

        return (string) $requestedCompanyId;
    }

    private function authorizedCompanyIds(Request $request)
    {
        // A global Staff permission can span legal entities only in the
        // explicit internal context. An explicitly selected Staff context is
        // always constrained to that employment's company, including admins.
        $contextType = $request->header('X-Active-Context-Type');
        if ($request->user()->can('staff.view-all') && $contextType === 'internal') {
            return Company::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('id');
        }

        $contexts = DB::table('user_contexts')->where('user_id', $request->user()->id)
            ->where('context_type', 'staff')->where('is_active', true)->whereNull('deleted_at');
        $contextId = $request->header('X-Active-Context-Id');
        if ($contextType === 'staff') {
            abort_unless($contextId && Str::isUuid($contextId), 403, 'Select an active Staff context.');
            $contexts->where('id', $contextId);
        } elseif ($contextType !== null && $contextType !== 'internal') {
            abort(403, 'Select an active Staff context.');
        }

        $staffQuery = Staff::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $contexts->pluck('context_id'))
            ->whereNotNull('company_id')
            ->where(fn ($employment) => $employment
                ->whereNull('employment_ended_at')
                ->orWhere('employment_ended_at', '>', now()));
        return Company::query()->whereIn('id', $staffQuery->select('company_id'))
            ->where('is_active', true)->pluck('id');
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

    private function lockActiveAttendanceCompany(string $companyId): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first(), 409, 'Attendance writes require an active legal entity.');
    }

    private function lockActiveDirectIsapiDevice(AttendanceDevice $device): AttendanceDevice
    {
        $locked = AttendanceDevice::query()->where('company_id', $device->company_id)->where('status', 'active')
            ->where('integration_mode', 'direct_isapi')->lockForUpdate()->find($device->id);
        abort_unless($locked, 409, 'The attendance device must remain active for direct ISAPI writes.');

        return $locked;
    }

    
}
