<?php

namespace App\Services;

use App\Models\Corporate\CorporateEmployee;
use Illuminate\Http\Request;

class CorporatePortalPermission
{
    public static function allows(Request $request, string $permission): bool
    {
        return self::allowsAny($request, [$permission]);
    }

    public static function allowsAny(Request $request, array $permissions): bool
    {
        $employee = $request->attributes->get('corporate_employee');
        if (! $employee instanceof CorporateEmployee) {
            return false;
        }

        return self::employeeAllowsAny($employee, $permissions);
    }

    public static function employeeAllows(CorporateEmployee $employee, string $permission): bool
    {
        return self::employeeAllowsAny($employee, [$permission]);
    }

    public static function employeeAllowsAny(CorporateEmployee $employee, array $permissions): bool
    {
        $context = $employee->userContext;
        $user = $context?->user;

        return $context && $user && $context->is_active && $employee->is_active && $employee->corporate?->is_active
            && app(PermissionEvaluator::class)->userHasAnyForRequest(
                $user,
                $permissions,
                'corporate',
                (string) $context->id,
            );
    }
}
