<?php

use App\Console\Commands\GenerateCorporateMonthlyBilling;
use Carbon\Carbon;

it('derives monthly billing periods from the configured cutoff day', function () {
    expect(GenerateCorporateMonthlyBilling::period(Carbon::parse('2026-09-01'), 31))->toBe(['2026-08-01', '2026-08-31'])
        ->and(GenerateCorporateMonthlyBilling::period(Carbon::parse('2026-09-28'), 25))->toBe(['2026-08-26', '2026-09-25'])
        ->and(GenerateCorporateMonthlyBilling::period(Carbon::parse('2026-03-01'), 31))->toBe(['2026-02-01', '2026-02-28']);
});

it('schedules retry-safe billing and report delivery', function () {
    $schedule = file_get_contents(dirname(__DIR__, 2).'/routes/console.php');
    $command = file_get_contents(dirname(__DIR__, 2).'/app/Console/Commands/GenerateCorporateMonthlyBilling.php');

    expect($schedule)->toContain('corporate:generate-monthly-billing --months=12 --issue')
        ->toContain('corporate:deliver-management-reports')
        ->toContain('corporate:process-collection-follow-ups')
        ->and($command)->toContain('CorporateBillingTerm::withInactive()')
        ->toContain("if (\$invoiceDate->gt(\$date))")
        ->toContain("\$billing->issue(\$settlement)");
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

it('keeps immutable reversal and adjustment document evidence', function () {
    $root = dirname(__DIR__, 2);
    $routes = file_get_contents($root.'/routes/api.php');
    $service = file_get_contents($root.'/app/Services/FinancialAccountSettlementService.php');

    expect($routes)->toContain("corporate-remittance-allocations/{allocation}/reverse")
        ->toContain("adjustments/{adjustment}/document")
        ->and(strpos($routes, "adjustments/{adjustment}/document"))->toBeLessThan(strpos($routes, "'{financialSettlement}'"))
        ->and($service)->toContain("['adjustment_id' => \$adjustment->id")
        ->toContain('FinancialAllocationReversal::create');
});

it('supports opt in template managed customer payment reminders', function () {
    $root = dirname(__DIR__, 2);
    $command = file_get_contents($root.'/app/Console/Commands/ProcessCorporateCollectionFollowUps.php');
    $migration = file_get_contents($root.'/database/migrations/2026_09_14_000005_seed_corporate_payment_reminder_template.php');

    expect($command)->toContain('delivery_preferences.email_payment_reminders')
        ->toContain("where('code', 'corporate_payment_reminder')")
        ->toContain('Mail::html')
        ->and($migration)->toContain("'code' => 'corporate_payment_reminder'");
});

it('provides the admin corporate operations dashboard metrics', function () {
    $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Api/FinancialSettlementController.php');

    expect($controller)->toContain("'active_corporates'")
        ->toContain("'pending_monthly_trip_count'")
        ->toContain("'corporate_draft_invoices'")
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
