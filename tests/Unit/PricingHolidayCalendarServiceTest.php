<?php

use App\Services\Pricing\PricingHolidayCalendarService;
use App\Services\WebsiteSettingsService;
use Carbon\Carbon;

it('matches exact and recurring business-configured pricing holidays', function () {
    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')
        ->with(PricingHolidayCalendarService::EXACT_DATE_SETTING, '')
        ->andReturn("2026-02-04\n2026-04-13");
    $settings->shouldReceive('get')
        ->with(PricingHolidayCalendarService::RECURRING_DATE_SETTING, '01-01,12-25')
        ->andReturn('01-01, 12-25');

    $calendar = new PricingHolidayCalendarService($settings);

    expect($calendar->evaluate(Carbon::parse('2026-02-04')))
        ->toMatchArray([
            'is_holiday' => true,
            'matched_rule' => 'exact_date',
            'matched_value' => '2026-02-04',
            'calendar_healthy' => true,
        ])
        ->and($calendar->evaluate(Carbon::parse('2027-01-01')))
        ->toMatchArray([
            'is_holiday' => true,
            'matched_rule' => 'recurring_date',
            'matched_value' => '01-01',
        ])
        ->and($calendar->isHoliday(Carbon::parse('2026-07-16')))->toBeFalse();
});

it('reports malformed holiday settings instead of silently matching them', function () {
    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')->andReturnUsing(
        fn (string $key) => $key === PricingHolidayCalendarService::EXACT_DATE_SETTING
            ? '2026-02-30, not-a-date'
            : '13-40'
    );

    $calendar = new PricingHolidayCalendarService($settings);
    $result = $calendar->evaluate(Carbon::parse('2026-02-28'));

    expect($result['is_holiday'])->toBeFalse()
        ->and($result['calendar_healthy'])->toBeFalse()
        ->and($result['issues'])->toHaveCount(3);
});
