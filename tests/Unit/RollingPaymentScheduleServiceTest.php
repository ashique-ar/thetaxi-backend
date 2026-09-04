<?php

use App\Models\Booking\BookingPaymentScheduleRule;
use App\Services\Sales\RollingPaymentScheduleService;
use Carbon\CarbonImmutable;

it('maintains twelve monthly occurrences from the current rule boundary', function (): void {
    $rule = new BookingPaymentScheduleRule(['anchor_date' => '2026-08-13']);
    $service = app(RollingPaymentScheduleService::class);

    expect($service->targetOccurrenceFor($rule, CarbonImmutable::parse('2026-08-13')))->toBe(12)
        ->and($service->targetOccurrenceFor($rule, CarbonImmutable::parse('2026-09-13')))->toBe(13)
        ->and($service->targetOccurrenceFor($rule, CarbonImmutable::parse('2026-09-14')))->toBe(14);
});

it('keeps rolling generation feature gated, occurrence unique, and outside attribution writes', function (): void {
    $service = file_get_contents(app_path('Services/Sales/RollingPaymentScheduleService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_134000_create_rolling_booking_payment_schedule_rules.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));

    expect($service)
        ->toContain("config('sales.features.rolling_payment_schedules', false)")
        ->toContain("->eligibleAt('collection', now())")
        ->toContain("'lifetime_contract_value_state' => 'not_applicable_open_ended'")
        ->not->toContain('SalesBookingAttribution::create')
        ->and($migration)
        ->toContain("'booking_schedule_rule_occurrence_unique'")
        ->toContain('Refusing to drop rolling payment schedule rule or occurrence evidence.')
        ->and($routes)
        ->toContain("'sales.feature:rolling_payment_schedules'")
        ->and($ledger)
        ->toContain("'lifetime_contract_value' => \$openEndedRule ? null : \$total")
        ->toContain("'generated_horizon_value' => \$openEndedRule ? \$scheduledAmount : null")
        ->toContain('allocateConfirmedReceiptsToNewSchedules')
        ->and($controller)
        ->toContain("'sales.collections.view-team'")
        ->toContain("whereIn('attribution.collection_sales_profile_id', \$profileIds)")
        ->toContain("in_array(\$attribution->collection_sales_profile_id, \$profileIds, true)")
        ->not->toContain("whereIn('attribution.company_id', \$this->actorCompanyIds(\$request))");
});
