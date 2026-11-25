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
        $user = $context?->user;

        return $context && $user && $context->is_active && $employee->is_active && $employee->corporate?->is_active
            && app(PermissionEvaluator::class)->userHasAnyForRequest(
                $user,
                [$permission],
                'corporate',
                (string) $context->id,
            );
    }
}
