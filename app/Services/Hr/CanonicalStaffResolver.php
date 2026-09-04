<?php

namespace App\Services\Hr;

use App\Models\Hr\HrPeopleIdentityLink;
use App\Models\Staff;

class CanonicalStaffResolver
{
    public function id(string $staffId, string $companyId): string
    {
        $link = HrPeopleIdentityLink::query()->where('company_id', $companyId)->where('alias_staff_id', $staffId)->where('status', 'active')->first();
        return (string) ($link?->canonical_staff_id ?? $staffId);
    }

    public function resolve(string $staffId, string $companyId): Staff
    {
        return Staff::withTrashed()->whereKey($this->id($staffId, $companyId))->where('company_id', $companyId)->firstOrFail();
    }
}
