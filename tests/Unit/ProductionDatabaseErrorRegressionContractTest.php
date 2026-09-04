<?php

uses(Tests\TestCase::class);

it('uses collision-safe inquiry creation on every inquiry write path', function () {
    $model = file_get_contents(app_path('Models/Inquiry.php'));
    $publicController = file_get_contents(app_path('Http/Controllers/InquiryController.php'));
    $apiController = file_get_contents(app_path('Http/Controllers/Api/InquiryController.php'));
    $bookingController = file_get_contents(app_path('Http/Controllers/BookingController.php'));

    expect($model)
        ->toContain('public static function createWithUniqueNumber')
        ->toContain("\$sqlState === '23505'")
        ->toContain('inquiries_inquiry_number_unique');
    expect(substr_count($publicController, 'Inquiry::createWithUniqueNumber(['))->toBe(2);
    expect($apiController)->toContain('Inquiry::createWithUniqueNumber($data)');
    expect($bookingController)->toContain('Inquiry::createWithUniqueNumber($inquiryData)');
});

it('reconciles corporate report schedule model conventions with its table', function () {
    $model = file_get_contents(app_path('Models/Corporate/CorporateReportSchedule.php'));
    $migration = file_get_contents(database_path('migrations/2026_09_04_000001_add_soft_deletes_to_corporate_report_schedules_table.php'));

    expect($model)->toContain('protected $useUserTracking = false;');
    expect($migration)
        ->toContain("Schema::hasColumn('corporate_report_schedules', 'deleted_at')")
        ->toContain('$table->softDeletes();');
});
