<?php

use Database\Seeders\AllPermissionsSeeder;

it('registers the staff sensitive personal permission without baseline sub-admin expansion', function () {
    expect(AllPermissionsSeeder::allPermissionNames())->toContain('staff-sensitive-personal.view');

    $source = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));
    preg_match('/private static function denyByDefaultPermissions\(\): array\s*\{(.*?)\n    \}/s', $source, $matches);
    expect($matches)->not->toBeEmpty('denyByDefaultPermissions() method body was not found in the seeder source.');
    $denyBlock = $matches[1];

    expect($denyBlock)->toContain("'staff-sensitive-personal.view'");
});

it('keeps every staff-sensitive-* permission classified as deny-by-default', function () {
    // Structural non-expansion guard: any future permission added under the
    // staff-sensitive-* naming convention must be deliberately excluded from
    // the sub-admin catch-all grant, the same way existing sensitive Staff
    // permissions already are.
    $source = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    preg_match('/private static function denyByDefaultPermissions\(\): array\s*\{(.*?)\n    \}/s', $source, $matches);
    expect($matches)->not->toBeEmpty('denyByDefaultPermissions() method body was not found in the seeder source.');
    $denyBlock = $matches[1];

    preg_match_all("/'(staff-sensitive-[a-z0-9_.-]+)'/", $source, $sensitiveMatches);
    $sensitivePermissions = array_unique($sensitiveMatches[1]);
    expect($sensitivePermissions)->not->toBeEmpty();

    foreach ($sensitivePermissions as $permission) {
        expect($denyBlock)->toContain("'{$permission}'");
    }
});

it('masks staff nic/dob/license/address behind the sensitive personal permission', function () {
    $resource = file_get_contents(app_path('Http/Resources/StaffResource.php'));

    expect($resource)
        ->toContain("viewer->can('staff-sensitive-personal.view')")
        ->toContain("'nic' => \$canViewSensitivePersonal ? \$this->nic : null")
        ->toContain("'dob' => \$canViewSensitivePersonal ? \$this->dob : null")
        ->toContain("'license_no' => \$canViewSensitivePersonal ? \$this->license_no : null")
        ->toContain("'license_expiry' => \$canViewSensitivePersonal ? \$this->license_expiry : null")
        ->toContain("'address' => \$canViewSensitivePersonal ? \$this->address : null")
        ->toContain("'sensitive_personal_restricted' => ! \$canViewSensitivePersonal");
});

it('never lets a generic-scope editor overwrite sensitive personal fields it cannot see', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));

    expect($controller)
        ->toContain('restrictSensitivePersonalFields')
        ->toContain("private const SENSITIVE_PERSONAL_FIELDS = ['nic', 'dob', 'license_no', 'license_expiry', 'address']")
        ->toContain('array_diff_key($data, array_flip(self::SENSITIVE_PERSONAL_FIELDS))')
        ->and(substr_count($controller, 'restrictSensitivePersonalFields($data'))->toBe(2);
});

it('audits third-party staff personal detail reveals and excludes sensitive fields from unauthorized search/sort', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));

    expect($controller)
        ->toContain("->log('staff_personal_details_viewed')")
        ->toContain('$canSearchSensitivePersonal = $request->user()->can(\'staff-sensitive-personal.view\')')
        ->toContain('if ($canSearchSensitivePersonal) {')
        ->toContain("orWhere('nic_fingerprint', hash('sha256', mb_strtolower(trim(\$search))))")
        ->not->toContain("orWhereLikeInsensitive('nic', \$search)")
        ->not->toContain("orderBy('nic', \$sortDirection)");
});
