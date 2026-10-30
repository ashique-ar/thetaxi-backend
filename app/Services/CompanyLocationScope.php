<?php

namespace App\Services;

final class CompanyLocationScope
{
    public static function constrain($query, string $column, ?string $companyId, bool $includeGlobal = true): void
    {
        if (! $companyId) {
            $query->whereNull($column);
            return;
        }

        $query->where(function ($scope) use ($column, $companyId, $includeGlobal): void {
            $scope->where($column, $companyId);
            if ($includeGlobal) $scope->orWhereNull($column);
        });
    }
}
