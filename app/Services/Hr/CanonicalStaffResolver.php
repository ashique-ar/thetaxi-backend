<?php

namespace App\Services\Hr;

use App\Models\Hr\HrPeopleIdentityLink;
use App\Models\Staff;

class CanonicalStaffResolver
{
    public function id(string $staffId): string
    {
        $link = HrPeopleIdentityLink::query()->where('alias_staff_id', $staffId)->where('status', 'active')->first();
        return (string) ($link?->canonical_staff_id ?? $staffId);
    }

    public function resolve(string $staffId): Staff
    {
        return Staff::withTrashed()->whereKey($this->id($staffId))->firstOrFail();
    }
}
