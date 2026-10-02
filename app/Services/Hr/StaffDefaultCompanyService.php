<?php

namespace App\Services\Hr;

use App\Models\Company;

class StaffDefaultCompanyService
{
    public function id(): ?string
    {
        return Company::query()
            ->where('is_default', true)
            ->orderBy('created_at')
            ->value('id');
    }
    public function apply(array $staffData): array
    {
        if (empty($staffData['company_id']) && ($defaultId = $this->id())) {
            $staffData['company_id'] = $defaultId;

        }

        return $staffData;
    }
}
