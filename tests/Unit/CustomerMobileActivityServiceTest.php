<?php

namespace Tests\Unit;

use App\Services\CustomerMobileActivityService;
use Carbon\Carbon;
use DomainException;
use PHPUnit\Framework\TestCase;

class CustomerMobileActivityServiceTest extends TestCase
{
    private CustomerMobileActivityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CustomerMobileActivityService();
        Carbon::setTestNow(Carbon::parse('2026-07-16T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_reduces_immutable_events_into_one_complete_cumulative_summary(): void
    {
        $summary = $this->service->summarizeActivities([
            [
                'occurred_at' => '2026-07-16T08:00:00+05:30',
                'actual_start_time' => '2026-07-16T08:00:00+05:30',
                'distance_km' => 0,
                'waiting_minutes' => 0,
            ],
            [
                'occurred_at' => '2026-07-16T10:45:00+05:30',
                'distance_km' => 84.125,
                'waiting_minutes' => 17,
            ],
            [
                'occurred_at' => '2026-07-16T11:00:00+05:30',
                'actual_return_time' => '2026-07-16T11:00:00+05:30',
                'distance_km' => 82.5,
                'waiting_minutes' => 15,
            ],
        ]);

        self::assertSame('2026-07-16T02:30:00+00:00', $summary['actual_start_time']);
        self::assertSame('2026-07-16T05:30:00+00:00', $summary['actual_return_time']);
        self::assertSame(84.125, $summary['distance_km']);
        self::assertSame(17, $summary['waiting_minutes']);
        self::assertSame(3, $summary['event_count']);
        self::assertTrue($summary['complete']);
    }

    public function test_completed_event_defaults_return_time_and_hash_is_stable_after_normalization(): void
    {
        $first = $this->service->normalizePayload([
            'client_event_id' => 'B39D4054-C7BA-44EA-AACB-CEB52FDE8C19',
            'event_type' => 'trip_completed',
            'occurred_at' => '2026-07-16T15:30:00+05:30',
            'distance_km' => '12.500',
            'waiting_minutes' => '4',
        ]);
        $second = $this->service->normalizePayload([
            'waiting_minutes' => 4,
            'distance_km' => 12.5,
            'occurred_at' => '2026-07-16T10:00:00Z',
            'event_type' => 'trip_completed',
            'client_event_id' => 'b39d4054-c7ba-44ea-aacb-ceb52fde8c19',
        ]);

        self::assertSame('2026-07-16T10:00:00+00:00', $first['actual_return_time']);
        self::assertSame($this->service->payloadHash($first), $this->service->payloadHash($second));
    }

    public function test_it_rejects_impossible_or_metric_free_events(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('At least one activity metric is required');

        $this->service->normalizePayload([
            'client_event_id' => 'b39d4054-c7ba-44ea-aacb-ceb52fde8c19',
            'event_type' => 'telemetry',
            'occurred_at' => '2026-07-16T10:00:00Z',
        ]);
    }
}
