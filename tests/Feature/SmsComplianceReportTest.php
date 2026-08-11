<?php

use App\Services\Sms\SmsProviderManager;
use App\Services\Sms\SmsSegmentCalculator;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('sms_messages', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('booking_id')->nullable();
        $table->string('source')->nullable();
        $table->string('event_key')->nullable();
        $table->string('idempotency_key')->nullable();
        $table->string('status');
        $table->unsignedInteger('segments')->nullable();
        $table->decimal('total_cost', 12, 4)->nullable();
        $table->string('cost_currency', 3)->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('sms_messages');
});

it('separates normal-three compliance, admin costs, optional messages, and unexpected extras', function (): void {
    $now = now();
    $rows = [];
    $add = function (string $booking, string $event, string $id, int $segments = 1, float $cost = 1.5) use (&$rows, $now): void {
        $rows[] = [
            'id' => $id,
            'booking_id' => $booking,
            'source' => 'automation',
            'event_key' => $event,
            'idempotency_key' => "{$booking}|{$event}|{$id}",
            'status' => 'dry_run',
            'segments' => $segments,
            'total_cost' => $cost,
            'cost_currency' => 'LKR',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    };

    foreach (['booking.confirmed', 'driver.dispatched', 'driver.arrived'] as $index => $event) {
        $add('booking-1', $event, "00000000-0000-0000-0000-00000000000{$index}");
    }
    $add('booking-1', 'driver.arrived', '00000000-0000-0000-0000-000000000010');
    $add('booking-1', 'admin.booking_confirmed_summary', '00000000-0000-0000-0000-000000000011', 2, 3);
    $add('booking-1', 'trip.completed', '00000000-0000-0000-0000-000000000012');
    $add('booking-1', 'legacy.unexpected', '00000000-0000-0000-0000-000000000013');
    DB::table('sms_messages')->insert($rows);

    $settings = Mockery::mock(SmsSettingsService::class);
    $settings->shouldReceive('getSettings')->once()->andReturn([
        'admin_booking_summary_enabled' => true,
        'admin_booking_summary_numbers' => ['94771234567'],
        'cost_currency' => 'LKR',
    ]);
    $service = new SmsService(
        Mockery::mock(SmsProviderManager::class),
        $settings,
        new SmsSegmentCalculator()
    );

    $report = $service->getTransactionalComplianceReport();

    expect($report['customer_normal_three']['bookings_with_extras'])->toBe(1)
        ->and($report['customer_normal_three']['duplicate_event_messages'])->toBe(1)
        ->and($report['admin_summaries']['recorded_messages'])->toBe(1)
        ->and($report['admin_summaries']['segments'])->toBe(2)
        ->and($report['admin_summaries']['estimated_cost'])->toBe(3.0)
        ->and($report['optional_messages']['total'])->toBe(1)
        ->and($report['unexpected_messages']['total'])->toBe(2)
        ->and($report['unexpected_messages']['standard_overage'])->toBe(1)
        ->and($report['unexpected_messages']['unknown_event_messages'])->toBe(1);
});
