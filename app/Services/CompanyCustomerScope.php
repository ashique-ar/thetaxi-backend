<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CompanyCustomerScope
{
    public static function phoneCallingCode(string $companyId): ?string
    {
        $callingCode = DB::table('companies')->join('countries', 'countries.id', '=', 'companies.country_id')
            ->where('companies.id', $companyId)->where('companies.is_active', true)
            ->whereNull('companies.deleted_at')->whereNull('countries.deleted_at')
            ->value('countries.callcode');

        return filled($callingCode) ? (string) $callingCode : null;
    }

    public static function constrain($query, string $customerAlias, string $companyId): void
    {
        // Customer records belong to this installation, not to a location.
    }
}
