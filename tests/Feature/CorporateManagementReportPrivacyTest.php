<?php

use App\Services\CorporateManagementAnalyticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('omits monetary fields from management data without the selected context payment permission', function () {
    Schema::create('bookings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('corporate_account_id')->nullable();
        $table->softDeletes();
    });
    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->softDeletes();
    });

    $payload = app(CorporateManagementAnalyticsService::class)->report(
        '00000000-0000-4000-8000-000000000000',
        [],
        false
    );

    expect($payload['financial_metrics_visible'])->toBeFalse()
        ->and($payload['operational'])->not->toHaveKeys(['estimated_booking_value', 'finalized_charges'])
        ->and($payload)->not->toHaveKeys(['financial', 'financial_period']);

});
