<?php

namespace App\Services;

use App\Models\Company;

final class SingleCompanyScope
{
    public function defaultCompany(): ?Company
    {
        $active = Company::query()->where('is_active', true)->limit(2)->get(['id', 'name', 'is_default']);

        return $active->count() === 1 && $active->first()->is_default
            ? $active->first()
            : null;
    }
}
