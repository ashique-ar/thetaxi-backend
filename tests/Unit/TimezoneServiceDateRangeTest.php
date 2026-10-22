<?php

use App\Services\TimezoneService;
use Illuminate\Support\Facades\Auth;

it('converts local report dates to UTC boundaries across daylight saving changes', function () {
    Auth::shouldReceive('user')->andReturn((object) ['timezone' => 'America/New_York']);

    expect(TimezoneService::userDateStartUtc('2026-11-01')->toDateTimeString())
        ->toBe('2026-11-01 04:00:00')
        ->and(TimezoneService::userDateEndExclusiveUtc('2026-11-01')->toDateTimeString())
        ->toBe('2026-11-02 05:00:00');
});
