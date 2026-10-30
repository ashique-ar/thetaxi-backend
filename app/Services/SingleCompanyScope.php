<?php

namespace App\Services;

use App\Models\Company;

final class SingleCompanyScope
{
    public function activeDefaultCompany(bool $forUpdate = false): ?Company
    {
        return null;
    }

    public function defaultCompany(): ?Company
    {
        return $this->activeDefaultCompany();
    }
}
