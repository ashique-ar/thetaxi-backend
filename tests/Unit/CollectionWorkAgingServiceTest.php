<?php

use App\Services\Sales\CollectionWorkAgingService;
use Carbon\CarbonImmutable;

it('derives outstanding and aging from schedule minus net allocations', function (): void {
    $aging = app(CollectionWorkAgingService::class);
    $asOf = CarbonImmutable::parse('2026-08-13 07:00:00');

    expect($aging->derive(1000, 250, CarbonImmutable::parse('2026-07-13'), $asOf, 3))
        ->toMatchArray([
            'outstanding_amount' => 750.0,
            'days_overdue' => 31,
            'aging_bucket' => '31_60',
            'work_status' => 'overdue',
        ])
        ->and($aging->derive(1000, 1000, CarbonImmutable::parse('2026-07-13'), $asOf, 3))
        ->toMatchArray([
            'outstanding_amount' => 0.0,
            'aging_bucket' => 'paid',
            'work_status' => 'completed',
        ]);
});

it('progresses open work through upcoming and due without treating reminders as payment', function (): void {
    $aging = app(CollectionWorkAgingService::class);
    $asOf = CarbonImmutable::parse('2026-08-13 07:00:00');

    expect($aging->derive(500, 100, CarbonImmutable::parse('2026-08-20'), $asOf, 3)['work_status'])->toBe('open')
        ->and($aging->derive(500, 100, CarbonImmutable::parse('2026-08-16'), $asOf, 3)['work_status'])->toBe('upcoming')
        ->and($aging->derive(500, 100, CarbonImmutable::parse('2026-08-13'), $asOf, 3)['work_status'])->toBe('due');
});
