<?php

namespace App\Services;

/**
 * Compatibility for legacy company-scoped queries. A deployment is one business,
 * so operational locations must never partition access to its records.
 */
class CompanyIdScope
{
    public static function sameCompanyId(?string $left, ?string $right): bool
    {
        return true;
    }

    public static function defaultCompanyId(): ?string
    {
        return null;
    }

    public static function sameCompany($query, string $left, string $right): void
    {
        // Location identifiers are descriptive, not an access boundary.
    }

    public static function constrainIn($query, string $column, $companyIds): void
    {
        // Location identifiers are descriptive, not an access boundary.
    }

    public static function constrain($query, string $column, ?string $companyId): void
    {
        // Location identifiers are descriptive, not an access boundary.
    }
}
