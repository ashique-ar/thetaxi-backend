<?php

namespace App\Services;

use App\Models\Corporate\CorporateEmployee;
use Illuminate\Http\Request;

class CorporatePortalPermission
{
    public static function allows(Request $request, string $permission): bool
    {
        $employee = $request->attributes->get('corporate_employee');
        if (! $employee instanceof CorporateEmployee) {
            return false;
        }

        return self::employeeAllows($employee, $permission);
    }

    public static function employeeAllows(CorporateEmployee $employee, string $permission): bool
    {
        $context = $employee->userContext;

        return $context && $context->is_active && $employee->is_active && $employee->corporate?->is_active
            && $context->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }
}
