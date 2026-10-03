<?php

namespace App\Services;

use App\Models\Company;

final class SingleCompanyScope
{
    public function activeDefaultCompany(bool $forUpdate = false): ?Company
    {
        $query = Company::query()->where('is_active', true)->where('is_default', true);
        if ($forUpdate) $query->lockForUpdate();
        $defaults = $query->limit(2)->get(['id', 'name', 'is_default']);

        return $defaults->count() === 1 ? $defaults->first() : null;
    }

    public function defaultCompany(): ?Company
    {
        $active = Company::query()->where('is_active', true)->limit(2)->get(['id', 'name', 'is_default']);

        return $active->count() === 1 && $active->first()->is_default
            ? $active->first()
            : null;
    }
}
