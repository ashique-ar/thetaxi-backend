<?php

it('evaluates default-company state from the locked database row', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/CompanyController.php'));
    $allCompanyLock = strpos($source, "DB::table('companies')->whereNull('deleted_at')->lockForUpdate()->get(['id'])");
    $companyLock = strpos($source, 'Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail()');
    $defaultState = strpos($source, '$makeDefault = (bool) ($data[\'is_default\'] ?? $lockedCompany->is_default)');
    $activeState = strpos($source, '$willBeActive = (bool) ($data[\'is_active\'] ?? $lockedCompany->is_active)');

    expect(is_int($allCompanyLock) && is_int($companyLock) && $companyLock > $allCompanyLock)->toBeTrue()
        ->and(is_int($defaultState) && $defaultState > $companyLock)->toBeTrue()
        ->and(is_int($activeState) && $activeState > $companyLock)->toBeTrue();
});

it('checks and deletes the current locked company row', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/CompanyController.php'));
    preg_match('/public function destroy\(Company \$company\): JsonResponse\s*\{(.*?)\n    \}/s', $source, $match);
    $body = $match[1] ?? '';
    $lock = strpos($body, 'Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail()');
    $defaultCheck = strpos($body, '$lockedCompany->is_default');
    $delete = strpos($body, '$lockedCompany->delete()');

    expect(is_int($lock) && is_int($defaultCheck) && $defaultCheck > $lock)->toBeTrue()
        ->and(is_int($delete) && $delete > $defaultCheck)->toBeTrue();
});

it('normalizes company active state after serializing default creation', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/CompanyController.php'));
    preg_match('/public function store\(CreateCompanyRequest \$request\): JsonResponse\s*\{(.*?)\n    \}/s', $source, $match);
    $body = $match[1] ?? '';
    $lock = strpos($body, "DB::table('companies')->whereNull('deleted_at')->lockForUpdate()->get(['id'])");
    $activeState = strpos($body, '$willBeActive = (bool) ($data[\'is_active\'] ?? true)');
    $insert = strpos($body, 'Company::create(');

    expect(is_int($lock) && is_int($activeState) && $activeState > $lock)->toBeTrue()
        ->and(is_int($insert) && $insert > $activeState)->toBeTrue();
});

it('does not let an inactive legacy default block the first active default', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/CompanyController.php'));

    expect($source)->toContain("Company::query()->where('is_default', true)->where('is_active', true)->exists()");
});
