<?php

use App\Models\User;
use App\Services\Hr\OrganizationAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates work calendars and audited commands installation-wide', function () {
    $actor = User::factory()->create();
    $service = app(OrganizationAdministrationService::class);
    $calendar = $service->createWorkCalendar([
        'code' => 'COLOMBO',
        'name' => 'Colombo work calendar',
        'timezone' => 'Asia/Colombo',
        'weekly_working_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'reason' => 'Installation-wide work schedule',
        'idempotency_key' => 'calendar-create-1',
    ], $actor->id);

    $calendarEvent = DB::table('hr_organization_change_events')->where('idempotency_key', 'calendar-create-1')->first();
    $calendarSnapshot = json_decode($calendarEvent->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
    expect($calendar)->not->toHaveKey('company_id')
        ->and($calendarSnapshot)->not->toHaveKey('company_id');

    $day = $service->createWorkCalendarDay($calendar['id'], [
        'calendar_date' => '2026-04-13',
        'day_type' => 'holiday',
        'name' => 'New Year holiday',
        'paid' => true,
        'reason' => 'Published holiday schedule',
        'idempotency_key' => 'calendar-day-create-1',
    ], $actor->id);

    $dayEvent = DB::table('hr_organization_change_events')->where('idempotency_key', 'calendar-day-create-1')->first();
    $daySnapshot = json_decode($dayEvent->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
    expect($day['calendar_id'])->toBe($calendar['id'])
        ->and($daySnapshot)->not->toHaveKey('company_id')
        ->and(Schema::hasColumn('hr_work_calendars', 'company_id'))->toBeFalse()
        ->and(Schema::hasColumn('hr_organization_change_events', 'company_id'))->toBeFalse();
});

it('replays installation-wide calendar commands without tenant metadata', function () {
    $actor = User::factory()->create();
    $service = app(OrganizationAdministrationService::class);
    $command = [
        'code' => 'REPLAY-CALENDAR',
        'name' => 'Replay calendar',
        'timezone' => 'Asia/Colombo',
        'weekly_working_days' => ['monday', 'tuesday'],
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'reason' => 'Installation-wide schedule',
        'idempotency_key' => 'calendar-replay-1',
    ];

    $created = $service->createWorkCalendar($command, $actor->id);
    $replayed = $service->createWorkCalendar($command, $actor->id);
    $event = DB::table('hr_organization_change_events')->where('idempotency_key', 'calendar-replay-1')->first();
    $snapshot = json_decode($event->after_snapshot, true, 512, JSON_THROW_ON_ERROR);

    expect($replayed['id'])->toBe($created['id'])
        ->and($snapshot)->not->toHaveKey('company_id')
        ->and(DB::table('hr_organization_change_events')->where('idempotency_key', 'calendar-replay-1')->count())->toBe(1);
});
