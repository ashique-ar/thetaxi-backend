<?php

use App\Models\User;
use App\Services\Hr\OrganizationAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates work calendars and their audited commands without company ownership', function () {
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

    expect($calendar)->not->toHaveKey('company_id')
        ->and(DB::table('hr_organization_change_events')->where('idempotency_key', 'calendar-create-1')->value('company_id'))->toBeNull();

    $day = $service->createWorkCalendarDay($calendar['id'], [
        'calendar_date' => '2026-04-13',
        'day_type' => 'holiday',
        'name' => 'New Year holiday',
        'paid' => true,
        'reason' => 'Published holiday schedule',
        'idempotency_key' => 'calendar-day-create-1',
    ], $actor->id);

    expect($day['calendar_id'])->toBe($calendar['id'])
        ->and(DB::table('hr_organization_change_events')->where('idempotency_key', 'calendar-day-create-1')->value('company_id'))->toBeNull();
});

it('removes company metadata from historical calendar events and preserves replay checksums', function () {
    [$actor, $company] = hr_seed_admin_actor();
    $calendarId = (string) Str::uuid();
    $idempotencyKey = 'legacy-calendar-create';
    $reason = 'Existing company-scoped calendar';
    $snapshot = [
        'id' => $calendarId,
        'company_id' => $company->id,
        'code' => 'LEGACY',
        'name' => 'Legacy calendar',
        'timezone' => 'Asia/Colombo',
        'weekly_working_days' => ['monday', 'tuesday'],
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'status' => 'active',
        'created_by' => $actor->id,
    ];
    DB::table('hr_organization_change_events')->insert([
        'id' => (string) Str::uuid(),
        'company_id' => $company->id,
        'aggregate_type' => 'work_calendar',
        'aggregate_id' => $calendarId,
        'event_type' => 'created',
        'aggregate_version' => 1,
        'after_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        'reason' => $reason,
        'actor_user_id' => $actor->id,
        'idempotency_key' => $idempotencyKey,
        'request_checksum' => str_repeat('0', 64),
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_30_000022_remove_work_calendar_tenant_company.php');
    $migration->up();

    $event = DB::table('hr_organization_change_events')->where('idempotency_key', $idempotencyKey)->first();
    $after = json_decode($event->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
    $payload = array_intersect_key($after, array_flip(['code', 'name', 'timezone', 'weekly_working_days', 'effective_from', 'effective_until']));
    $expectedChecksum = hash('sha256', json_encode(['command' => 'create_work_calendar', 'payload' => $payload, 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    expect($event->company_id)->toBeNull()
        ->and($after)->not->toHaveKey('company_id')
        ->and($event->request_checksum)->toBe($expectedChecksum);
});
