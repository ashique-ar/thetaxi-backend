<?php

namespace App\Services;

class CompanyIdScope
{
    public static function constrain($query, string $column, string $companyId): void
    {
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
