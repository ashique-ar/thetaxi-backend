<?php

namespace App\Services\Hr;

use App\Services\SingleCompanyScope;

class StaffDefaultCompanyService
{
    public function id(): ?string
    {
        return app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
    }

    public function apply(array $staffData): array
    {
        if (empty($staffData['company_id']) && ($defaultId = $this->id())) {
            $staffData['company_id'] = $defaultId;
        }

        return $staffData;
    }
}
