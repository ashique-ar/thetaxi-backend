<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class SingleCompanyScope
{
    public function activeDefaultCompany(bool $forUpdate = false): ?Company
    {
        $query = Company::query()->where('is_active', true)->where('is_default', true);
        if ($forUpdate) $query->lockForUpdate();
        $defaults = $query->limit(2)->get(['id', 'name', 'is_default']);

        if ($defaults->count() === 1) return $defaults->first();
        if ($defaults->count() > 1) return null;

        return DB::transaction(function (): ?Company {
            DB::table('companies')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get(['id']);
            $defaults = Company::query()->where('is_active', true)->where('is_default', true)
                ->lockForUpdate()->limit(2)->get(['id', 'name', 'is_default']);
            if ($defaults->count() === 1) return $defaults->first();
            if ($defaults->count() > 1) return null;

            $first = Company::query()->where('is_active', true)->orderBy('created_at')->orderBy('id')
                ->lockForUpdate()->first();
            if (! $first) return null;

            DB::table('companies')->whereNull('deleted_at')->update(['is_default' => false]);
            DB::table('companies')->where('id', $first->id)->update(['is_default' => true, 'updated_at' => now()]);

            return $first->fresh();
        });
    }

    public function defaultCompany(): ?Company
    {
        return $this->activeDefaultCompany();
    }
}
