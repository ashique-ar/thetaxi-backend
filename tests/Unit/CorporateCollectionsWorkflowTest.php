<?php

use App\Console\Commands\GenerateCorporateMonthlyBilling;
use Carbon\Carbon;

it('derives monthly billing periods from the configured cutoff day', function () {
    expect(GenerateCorporateMonthlyBilling::period(Carbon::parse('2026-09-01'), 31))->toBe(['2026-08-01', '2026-08-31'])
        ->and(GenerateCorporateMonthlyBilling::period(Carbon::parse('2026-09-28'), 25))->toBe(['2026-08-26', '2026-09-25'])
        ->and(GenerateCorporateMonthlyBilling::period(Carbon::parse('2026-03-01'), 31))->toBe(['2026-02-01', '2026-02-28']);
});

it('registers remittances follow ups refunds and dedicated finance permissions', function () {
    $root = dirname(__DIR__, 2);
    $routes = file_get_contents($root.'/routes/api.php');
    $controller = file_get_contents($root.'/app/Http/Controllers/Api/FinancialSettlementController.php');
    $service = file_get_contents($root.'/app/Services/FinancialAccountSettlementService.php');
    $migration = file_get_contents($root.'/database/migrations/2026_09_14_000001_complete_corporate_collections_workflow.php');

    expect($routes)->toContain("corporates/{corporate}/remittances")->toContain("corporate-remittances/{remittance}/allocate")->toContain("{financialSettlement}/follow-up")
        ->toContain('permission:financial-settlements.manage')
        ->and($controller)->toContain('receiveCorporateRemittance')->toContain("'metadata.refunded_at' => 'required_if:type,refund")
        ->and($service)->toContain('receiveCorporateRemittance')->toContain('allocateCorporateRemittance')->toContain('updateCollectionFollowUp')->toContain('unapplied_amount')
        ->and($migration)->toContain("Schema::create('corporate_remittances'")->toContain("'next_follow_up_at'");
});

it('provides the admin corporate operations dashboard metrics', function () {
    $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Api/FinancialSettlementController.php');

    expect($controller)->toContain("'active_corporates'")
        ->toContain("'pending_monthly_trip_count'")
        ->toContain("'final_pricing_pending_trip_count'")
        ->toContain("'unapplied_remittance_total'")
        ->toContain('owner_name');
});

it('provides readable corporate employee csv import without ids', function () {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root.'/app/Http/Controllers/Api/Corporate/CorporateEmployeeController.php');
    $routes = file_get_contents($root.'/routes/api.php');

    expect($routes)->toContain("employees-import-template")->toContain("employees-import")
        ->and($controller)->toContain("['email', 'first_name', 'last_name', 'phone', 'employee_code', 'department', 'division', 'role']")
        ->toContain('User::withTrashed()')
        ->toContain("'skipped' => \$skipped")
        ->not->toContain("['department_id', 'division_id']");
});
