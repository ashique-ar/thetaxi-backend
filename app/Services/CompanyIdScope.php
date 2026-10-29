<?php

namespace App\Services;

class CompanyIdScope
{
    public static function constrainIn($query, string $column, $companyIds): void
    {
        $companyIds = collect($companyIds)->filter(fn ($id) => $id !== null && $id !== '')->values()->all();

        if ($companyIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(fn ($scope) => $scope->whereIn($column, $companyIds)
            ->orWhere(fn ($unassigned) => $unassigned->whereNull($column)->whereExists(fn ($defaultCompany) => $defaultCompany
                ->selectRaw('1')->from('companies as default_company')
                ->whereIn('default_company.id', $companyIds)
                ->where('default_company.is_default', true)
                ->where('default_company.is_active', true)
                ->whereNull('default_company.deleted_at'))));
    }

    public static function constrain($query, string $column, ?string $companyId): void
    {
        if ($companyId === null || $companyId === '') {
            $query->whereNull($column);
            return;
        }

        $query->where(function ($scope) use ($column, $companyId): void {
            $scope->where($column, $companyId)
                ->orWhere(function ($unassigned) use ($column, $companyId): void {
                    $unassigned->whereNull($column)->whereExists(fn ($defaultCompany) => $defaultCompany
                        ->selectRaw('1')->from('companies as default_company')
                        ->where('default_company.id', $companyId)
                        ->where('default_company.is_default', true)
                        ->where('default_company.is_active', true)
                        ->whereNull('default_company.deleted_at'));
                });
        });
    }
}
