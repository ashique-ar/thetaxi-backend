<?php

use App\Models\Company;
use App\Models\Hr\HrEpfEtfContributionPolicy;
use App\Models\User;
use App\Services\Hr\PayrollStatutoryPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function approved_epf_etf_policy_fixture(array $overrides = []): array
{
    $company = Company::create(['name' => 'EPF earnings basis preview company']);
    $creator = User::factory()->create();
    $approver = User::factory()->create();
    $policy = HrEpfEtfContributionPolicy::create(array_merge([
        'company_id' => $company->id,
        'version' => 1,
        'status' => 'approved',
        'employee_epf_rate_percent' => 8,
        'employer_epf_rate_percent' => 12,
        'employer_etf_rate_percent' => 3,
        'earnings_basis' => [
            'include_basic_salary' => true,
            'include_cost_of_living_allowance' => false,
            'include_food_allowance' => true,
            'include_holiday_pay' => false,
            'include_other_regular_allowances' => true,
            'exclude_overtime' => true,
            'exclude_bonus' => false,
            'exclude_reimbursements' => true,
        ],
        'statutory_reference' => 'Approved test reference',
        'effective_from' => now()->subDay(),
        'reason' => 'Test fixture only',
        'created_by' => $creator->id,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ], $overrides));

    return [app(PayrollStatutoryPolicyService::class), $company, $policy];
}

it('calculates EPF and ETF from only the explicitly included earning categories', function () {
    [$service, $company] = approved_epf_etf_policy_fixture();

    $preview = $service->previewContribution($company->id, [
        'basic_salary' => 100000,
        'cost_of_living_allowance' => 20000,
        'food_allowance' => 10000,
        'holiday_pay' => 5000,
        'other_regular_allowances' => 15000,
        'overtime' => 8000,
        'bonus' => 4000,
        'reimbursements' => 3000,
    ], CarbonImmutable::now());

    expect($preview['blocked'])->toBeFalse()
        ->and($preview['assessable_earnings'])->toBe(129000.0)
        ->and($preview['employee_epf_amount'])->toBe(10320.0)
        ->and($preview['employer_epf_amount'])->toBe(15480.0)
        ->and($preview['employer_etf_amount'])->toBe(3870.0);
});

it('blocks preview of incomplete policies and prevents their approval', function () {
    [$service, $company, $policy] = approved_epf_etf_policy_fixture(['earnings_basis' => []]);

    $preview = $service->previewContribution($company->id, [], CarbonImmutable::now());
    expect($preview['blocked'])->toBeTrue()
        ->and($preview)->not->toHaveKey('employee_epf_amount');

    $draft = HrEpfEtfContributionPolicy::create([
        'company_id' => $company->id,
        'version' => 2,
        'status' => 'draft',
        'employee_epf_rate_percent' => $policy->employee_epf_rate_percent,
        'employer_epf_rate_percent' => $policy->employer_epf_rate_percent,
        'employer_etf_rate_percent' => $policy->employer_etf_rate_percent,
        'earnings_basis' => [],
        'statutory_reference' => $policy->statutory_reference,
        'effective_from' => now()->subDay(),
        'reason' => 'Incomplete legacy policy',
        'created_by' => $policy->created_by,
        'approved_by' => null,
        'approved_at' => null,
    ]);

    expect(fn () => $service->approveEpfEtfPolicy($draft->id, $company->id, (string) Str::uuid()))
        ->toThrow(HttpException::class, 'Explicit EPF/ETF rates, a complete earnings basis, and a statutory reference are required before approval.');
});

it('requires an amount for every earnings category instead of treating missing amounts as zero', function () {
    [$service, $company] = approved_epf_etf_policy_fixture();

    expect(fn () => $service->previewContribution($company->id, ['basic_salary' => 100000], CarbonImmutable::now()))
        ->toThrow(ValidationException::class);
});

it('rejects combined EPF contributions that overflow the supported numeric range', function () {
    [$service, $company] = approved_epf_etf_policy_fixture([
        'employee_epf_rate_percent' => 100,
        'employer_epf_rate_percent' => 100,
    ]);
    $earnings = array_fill_keys([
        'basic_salary', 'cost_of_living_allowance', 'food_allowance', 'holiday_pay',
        'other_regular_allowances', 'overtime', 'bonus', 'reimbursements',
    ], 0.0);
    $earnings['basic_salary'] = PHP_FLOAT_MAX;

    expect(fn () => $service->previewContribution($company->id, $earnings, CarbonImmutable::now()))
        ->toThrow(ValidationException::class, 'The calculated contribution exceeds the supported numeric range.');
});

it('returns normalized boolean earnings-basis choices', function () {
    [$service, $company] = approved_epf_etf_policy_fixture(['earnings_basis' => [
        'include_basic_salary' => 1,
        'include_cost_of_living_allowance' => '0',
        'include_food_allowance' => true,
        'include_holiday_pay' => false,
        'include_other_regular_allowances' => true,
        'exclude_overtime' => true,
        'exclude_bonus' => false,
        'exclude_reimbursements' => true,
    ]]);
    $earnings = array_fill_keys([
        'basic_salary', 'cost_of_living_allowance', 'food_allowance', 'holiday_pay',
        'other_regular_allowances', 'overtime', 'bonus', 'reimbursements',
    ], 0);

    $preview = $service->previewContribution($company->id, $earnings, CarbonImmutable::now());

    expect($preview['earnings_basis']['include_basic_salary'])->toBeTrue()
        ->and($preview['earnings_basis']['include_cost_of_living_allowance'])->toBeFalse();
});
