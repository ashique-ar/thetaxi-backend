<?php

use App\Services\WebsiteSettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

it('enforces fractional portal booking advance hours', function () {
    Carbon::setTestNow('2026-10-24 10:00:00');
    try {
        app(WebsiteSettingsService::class)->setGlobal('corporate_portal_booking_advance_hours', 2.5);
        $validator = Validator::make([], []);
        app(WebsiteSettingsService::class)->validatePortalBookingAdvance($validator, [
            'from_date' => '2026-10-24',
            'from_time' => '12:29',
        ], 'corporate_portal_booking_advance_hours');

        expect($validator->errors()->has('from_date'))->toBeTrue();
        expect($validator->errors()->first('from_date'))->toContain('2 hours 30 minutes');
    } finally {
        Carbon::setTestNow();
    }
});
