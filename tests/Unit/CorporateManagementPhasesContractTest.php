<?php

use Illuminate\Support\Facades\Artisan;

uses(Tests\TestCase::class);

it('keeps management analytics company scoped and separates distance purposes', function () {
    $source = file_get_contents(app_path('Services/CorporateManagementAnalyticsService.php'));
    expect($source)
        ->toContain("where('corporate_account_id', \$corporateId)")
        ->toContain("'contractual_km' => 'pricing_snapshot'")
        ->toContain("'operational_km' => 'driver_telemetry_evidence'")
        ->toContain("'financial_metrics_visible' => \$canViewFinance")
        ->toContain("'basis' => 'travel_date'")
        ->toContain('summarizeBookings(');
});

it('provides server generated management exports and permissioned monthly delivery', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateManagementReportController.php'));
    $schedule = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateReportScheduleController.php'));
    expect($routes)->toContain("reports/management/export/{format}", "apiResource('report-schedules'")
        ->and($controller)->toContain("['csv', 'xls', 'pdf']", 'CorporateReportFiltersRequest')
        ->toContain("Selected travel period")
        ->and($schedule)->toContain("permission:schedule_reports", "where('corporate_id', \$request->corporate_id)");
});

it('separates corporate portal permissions and returns a role specific workspace', function () {
    $roles = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateRoleController.php'));
    $workspace = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateWorkspaceController.php'));
    expect($roles)->toContain('create_bookings', 'create_bookings_for_others', 'approve_bookings', 'view_payments', 'view_reports', 'manage_employees', 'view_audit_log')
        ->and($workspace)->toContain("'role_mode'", "'scope' => \$all ? 'company' : 'employee'", "'tasks' => \$tasks", "'capabilities'");
});

it('keeps timeline scope on the authorized booking and selected booking item', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateBookingController.php'));
    expect($controller)->toContain('public function timeline', '$this->authorizedBooking($request, $id)', "whereKey(\$itemId)", "'financial_events_visible'")
        ->not->toContain("'raw_tracking' => true");
});

it('registers the monthly corporate delivery command', function () {
    $events = Artisan::call('schedule:list');
    expect($events)->toBe(0)
        ->and(Artisan::output())->toContain('corporate:deliver-management-reports');
});
