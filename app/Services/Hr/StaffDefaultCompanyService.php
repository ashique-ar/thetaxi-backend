<?php

namespace App\Services\Hr;

use App\Models\Company;

class StaffDefaultCompanyService
{
    public function id(): ?string
    {
        $defaults = Company::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->limit(2)
            ->pluck('id');

        return $defaults->count() === 1 ? (string) $defaults->first() : null;
    }

    public function apply(array $staffData): array
    {
        if (empty($staffData['company_id']) && ($defaultId = $this->id())) {
            $staffData['company_id'] = $defaultId;
        }

        return $staffData;
    }
}
