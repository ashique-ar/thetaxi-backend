<?php

use App\Models\Company;
use App\Models\Hr\HrGratuityPolicy;
use App\Models\User;
use App\Services\Hr\PayrollStatutoryPolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function approved_gratuity_policy_fixture(array $overrides = []): array
{
    $company = Company::create(['name' => 'Gratuity tax preview company']);
    $creator = User::factory()->create();
    $approver = User::factory()->create();
    $policy = HrGratuityPolicy::create(array_merge([
        'company_id' => $company->id,
        'version' => 1,
        'status' => 'approved',
        'minimum_qualifying_service_years' => 5,
        'minimum_employer_headcount_threshold' => 15,
        'monthly_paid_divisor' => 2,
        'non_monthly_daily_wage_multiplier' => 14,
        'non_monthly_lookback_months' => 12,
        'payment_deadline_days' => 30,
        'tax_exempt_threshold_lkr' => null,
        'tax_rate_above_threshold_percent' => null,
        'statutory_reference' => 'Test policy reference',
        'effective_from' => now()->subDay(),
        'reason' => 'Test fixture only',
        'created_by' => $creator->id,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ], $overrides));

    return [app(PayrollStatutoryPolicyService::class), $company, $policy];
}

it('blocks the net preview when an approved legacy policy lacks tax inputs', function () {
    [$service, $company] = approved_gratuity_policy_fixture();

    $preview = $service->previewGratuityEntitlement(
        $company->id,
        'monthly',
        10000,
        5,
        20,
        CarbonImmutable::now(),
    );

    expect($preview['blocked'])->toBeTrue()
        ->and($preview['blocker'])->toBe('The approved gratuity policy is missing an explicit statutory tax threshold or rate.')
        ->and($preview)->not->toHaveKey('net_gratuity_amount_lkr');
});

it('blocks the net preview when only one approved tax input is missing', function () {
    foreach ([
        ['tax_exempt_threshold_lkr' => null, 'tax_rate_above_threshold_percent' => 10],
        ['tax_exempt_threshold_lkr' => 10000, 'tax_rate_above_threshold_percent' => null],
    ] as $taxInputs) {
        [$service, $company] = approved_gratuity_policy_fixture($taxInputs);
        $preview = $service->previewGratuityEntitlement(
            $company->id,
            'monthly',
            10000,
            5,
            20,
            CarbonImmutable::now(),
        );

        expect($preview['blocked'])->toBeTrue()
            ->and($preview)->not->toHaveKey('net_gratuity_amount_lkr');
    }
});

it('calculates tax only from explicit approved threshold and rate values', function () {
    [$service, $company] = approved_gratuity_policy_fixture([
        'tax_exempt_threshold_lkr' => 10000,
        'tax_rate_above_threshold_percent' => 10,
    ]);

    $preview = $service->previewGratuityEntitlement(
        $company->id,
        'monthly',
        10000,
        5,
        20,
        CarbonImmutable::now(),
    );

    expect($preview['blocked'])->toBeFalse()
        ->and($preview['gross_gratuity_amount_lkr'])->toBe(25000.0)
        ->and($preview['tax_amount_lkr'])->toBe(1500.0)
        ->and($preview['net_gratuity_amount_lkr'])->toBe(23500.0);
});

it('rejects approval of a draft missing either statutory tax input', function () {
    [$service, $company, $policy] = approved_gratuity_policy_fixture([
        'status' => 'draft',
        'approved_by' => null,
        'approved_at' => null,
    ]);

    expect(fn () => $service->approveGratuityPolicy($policy->id, $company->id, (string) Str::uuid()))
        ->toThrow(HttpException::class, 'An explicit statutory tax threshold and rate are required before approval.');
});

it('blocks legacy approved policies with invalid formula ranges or a blank statutory reference', function () {
    foreach ([
        ['monthly_paid_divisor' => 12.01],
        ['non_monthly_daily_wage_multiplier' => 365.01],
        ['non_monthly_lookback_months' => 37],
        ['payment_deadline_days' => 366],
        ['minimum_qualifying_service_years' => 51],
        ['minimum_employer_headcount_threshold' => 0],
        ['tax_exempt_threshold_lkr' => -1],
        ['tax_rate_above_threshold_percent' => 100.01],
        ['statutory_reference' => '   '],
    ] as $invalidPolicy) {
        [$service, $company] = approved_gratuity_policy_fixture(array_merge([
            'tax_exempt_threshold_lkr' => 10000,
            'tax_rate_above_threshold_percent' => 10,
        ], $invalidPolicy));

        $preview = $service->previewGratuityEntitlement($company->id, 'monthly', 10000, 5, 20, CarbonImmutable::now());

        expect($preview['blocked'])->toBeTrue()
            ->and($preview)->not->toHaveKey('net_gratuity_amount_lkr');
    }
});

it('rejects approval of a draft with an out-of-range gratuity formula', function () {
    [$service, $company, $policy] = approved_gratuity_policy_fixture([
        'status' => 'draft',
        'approved_by' => null,
        'approved_at' => null,
        'monthly_paid_divisor' => 12.01,
        'tax_exempt_threshold_lkr' => 10000,
        'tax_rate_above_threshold_percent' => 10,
    ]);

    expect(fn () => $service->approveGratuityPolicy($policy->id, $company->id, (string) Str::uuid()))
        ->toThrow(HttpException::class, 'Gratuity formula values and statutory reference must be complete and within accepted ranges before approval.');
});

it('blocks net previews until current employer headcount is supplied', function () {
    [$service, $company] = approved_gratuity_policy_fixture([
        'tax_exempt_threshold_lkr' => 10000,
        'tax_rate_above_threshold_percent' => 10,
    ]);

    $preview = $service->previewGratuityEntitlement($company->id, 'monthly', 10000, 5, null, CarbonImmutable::now());

    expect($preview['blocked'])->toBeTrue()
        ->and($preview['blocker'])->toBe('Current employer headcount is required to evaluate the approved gratuity policy threshold.')
        ->and($preview)->not->toHaveKey('net_gratuity_amount_lkr');
});

it('blocks entitlement previews when headcount is below the approved policy threshold', function () {
    [$service, $company] = approved_gratuity_policy_fixture([
        'tax_exempt_threshold_lkr' => 10000,
        'tax_rate_above_threshold_percent' => 10,
    ]);

    $preview = $service->previewGratuityEntitlement($company->id, 'monthly', 10000, 5, 14, CarbonImmutable::now());

    expect($preview['blocked'])->toBeTrue()
        ->and($preview['blocker'])->toBe('Current employer headcount is below the approved gratuity policy threshold.')
        ->and($preview)->not->toHaveKey('net_gratuity_amount_lkr');
});
