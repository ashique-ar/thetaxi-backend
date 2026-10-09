<?php

use App\Models\Company;
use App\Models\Hr\HrEpfEtfContributionPolicy;
use App\Models\Hr\HrGratuityPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('keeps statutory policy terms auditable without free-text reasons or actor identifiers', function () {
    $company = Company::create(['name' => 'Statutory audit company']);
    $creator = User::factory()->create();
    $approver = User::factory()->create();
    $privateReason = 'private statutory policy rationale';

    $epf = HrEpfEtfContributionPolicy::create([
        'company_id' => $company->id,
        'version' => 1,
        'status' => 'approved',
        'employee_epf_rate_percent' => 8,
        'employer_epf_rate_percent' => 12,
        'employer_etf_rate_percent' => 3,
        'earnings_basis' => ['include_basic_salary' => true],
        'statutory_reference' => 'EPF reference',
        'effective_from' => '2026-01-01',
        'reason' => $privateReason,
        'created_by' => $creator->id,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);
    $gratuity = HrGratuityPolicy::create([
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
        'statutory_reference' => 'Gratuity reference',
        'effective_from' => '2026-01-01',
        'reason' => $privateReason,
        'created_by' => $creator->id,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);

    $epfProperties = DB::table('activity_log')->where('subject_type', HrEpfEtfContributionPolicy::class)
        ->where('subject_id', $epf->id)->value('attribute_changes');
    $gratuityProperties = DB::table('activity_log')->where('subject_type', HrGratuityPolicy::class)
        ->where('subject_id', $gratuity->id)->value('attribute_changes');

    expect($epfProperties)->not->toBeNull()
        ->and($gratuityProperties)->not->toBeNull()
        ->and($epfProperties)->toContain('8.00')
        ->and($epfProperties)->toContain('EPF reference')
        ->and($gratuityProperties)->toContain('14.00')
        ->and($gratuityProperties)->toContain('Gratuity reference')
        ->and($epfProperties.' '.$gratuityProperties)->not->toContain($privateReason)
        ->and($epfProperties.' '.$gratuityProperties)->not->toContain((string) $creator->id)
        ->and($epfProperties.' '.$gratuityProperties)->not->toContain((string) $approver->id);
});
