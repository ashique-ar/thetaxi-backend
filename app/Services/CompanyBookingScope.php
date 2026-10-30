<?php

namespace App\Services;

class CompanyBookingScope
{
    public static function constrain($query, string $bookingAlias, string $companyId): void
    {
        CompanyIdScope::constrain($query, "{$bookingAlias}.company_id", $companyId);
    }
}
