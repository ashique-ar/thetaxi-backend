<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * AttendanceDeviceController's implementation lives across the main controller
 * file and its Concerns/*.php traits (see the trait-based reorganization in
 * app/Http/Controllers/Api/Hr/Concerns). Several Unit contract tests assert on
 * raw source text rather than behavior; this helper lets them find that text
 * regardless of which file within the controller/trait group it now lives in,
 * so the tests keep verifying the same guarantees after the reorganization.
 *
 * @return string[] absolute paths, controller first, then its concern traits.
 */
function hr_attendance_device_controller_files(): array
{
    return array_merge(
        [app_path('Http/Controllers/Api/Hr/AttendanceDeviceController.php')],
        glob(app_path('Http/Controllers/Api/Hr/Concerns/*.php')) ?: []
    );
}

/**
 * Concatenated source of AttendanceDeviceController plus all of its concern
 * traits — for simple ->toContain() assertions that don't care which file a
 * snippet lives in.
 */
function hr_attendance_device_controller_source(): string
{
    return implode("\n", array_map('file_get_contents', hr_attendance_device_controller_files()));
}

/**
 * Slices out a single method's body from whichever AttendanceDeviceController
 * file contains it: finds $fromNeedle (e.g. 'function health(') in the first
 * matching file, then cuts the slice at $toNeedle (e.g. the next method's
 * signature) if that needle is found later in the *same* file, otherwise
 * returns to the end of that file. Used by tests that previously relied on
 * two methods being adjacent in one file — now only valid when both still
 * live in the same trait.
 */
function hr_attendance_device_method_slice(string $fromNeedle, ?string $toNeedle = null): string
{
    foreach (hr_attendance_device_controller_files() as $file) {
        $contents = file_get_contents($file);
        $start = strpos($contents, $fromNeedle);
        if ($start === false) {
            continue;
        }
        $slice = substr($contents, $start);
        if ($toNeedle !== null) {
            $end = strpos($slice, $toNeedle);
            if ($end !== false) {
                $slice = substr($slice, 0, $end);
            }
        }

        return $slice;
    }

    return '';
}

/**
 * Seeds real Spatie roles/permissions (via AllPermissionsSeeder, scoped to
 * the current test's RefreshDatabase connection — never the real database),
 * creates a Company plus a User with the 'admin' role and a matching Staff
 * record, and returns [User, Company]. Used by tests/Feature/Hr/* tests that
 * exercise real HR API endpoints end-to-end instead of grepping source text.
 *
 * @return array{0: \App\Models\User, 1: \App\Models\Company}
 */
function hr_seed_admin_actor(array $companyAttributes = []): array
{
    (new \Database\Seeders\AllPermissionsSeeder())->run();

    $company = \App\Models\Company::create(array_merge([
        'name' => 'Hr Feature Test Co',
        'is_default' => true,
    ], $companyAttributes));

    $admin = \App\Models\User::factory()->create();
    $admin->assignRole('admin');
    $staff = \App\Models\Staff::factory()->create([
        'user_id' => $admin->id,
        'company_id' => $company->id,
        'staff_type' => 'admin',
    ]);

    // Routes guarded by the 'ensure.internal' middleware (e.g. hr/people,
    // hr/employees) resolve permissions via PermissionEvaluator::
    // userHasAnyForInternalContext(), which requires an *active 'staff'
    // UserContext row* (not just a Staff record) — see
    // PermissionEvaluator::hasActiveStaffIdentity(). Without this, an actor
    // with the 'admin' role and a Staff record still gets a 403 on any
    // internal-context route.
    \App\Models\UserContext::create([
        'user_id' => $admin->id,
        'context_type' => 'staff',
        'context_id' => $staff->id,
        'is_active' => true,
        'created_user_id' => $admin->id,
    ]);

    return [$admin, $company];
}
