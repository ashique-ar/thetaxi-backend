<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$schemaName = 'reconciliation_contract';

beforeEach(function () use ($schemaName): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_ends_with((string) config('database.connections.pgsql.database'), '_ci')) {
        $this->markTestSkipped('Requires an explicitly isolated PostgreSQL database whose name ends in _ci.');
    }

    DB::statement("DROP SCHEMA IF EXISTS {$schemaName} CASCADE");
    DB::statement("CREATE SCHEMA {$schemaName}");
    DB::statement("SET search_path TO {$schemaName}");
});

afterEach(function () use ($schemaName): void {
    if (DB::getDriverName() !== 'pgsql') {
        return;
    }

    DB::statement('SET search_path TO public');
    DB::statement("DROP SCHEMA IF EXISTS {$schemaName} CASCADE");
});

it('reconciles representative existing PostgreSQL ownership without losing data', function (): void {
    $bookingId = '11111111-1111-4111-8111-111111111111';
    $termsId = '22222222-2222-4222-8222-222222222222';

    DB::statement('CREATE TABLE bookings (id uuid PRIMARY KEY, status varchar(50), booking_date date, customer_id uuid, corporate_account_id uuid)');
    DB::statement('CREATE TABLE terms_and_conditions (id uuid PRIMARY KEY)');
    DB::statement('CREATE TABLE booking_terms (id bigserial PRIMARY KEY, booking_id varchar(36), terms_and_condition_id varchar(36))');
    DB::statement('CREATE TABLE booking_items (id bigserial PRIMARY KEY, booking_id uuid, from_date timestamp, to_date timestamp)');
    DB::statement('CREATE TABLE driver_assignments (id bigserial PRIMARY KEY, booking_id uuid)');
    DB::statement('CREATE TABLE vehicle_assignments (id bigserial PRIMARY KEY, vehicle_id uuid, booking_id uuid)');
    DB::statement('CREATE TABLE route_points (id bigserial PRIMARY KEY, driver_session_id uuid, recorded_at timestamp)');

    DB::table('bookings')->insert(['id' => $bookingId, 'status' => 'pending', 'booking_date' => '2026-07-22']);
    DB::table('terms_and_conditions')->insert(['id' => $termsId]);
    DB::table('booking_terms')->insert([
        'booking_id' => $bookingId,
        'terms_and_condition_id' => $termsId,
    ]);

    $migration = require database_path('migrations/2026_07_22_000006_reconcile_existing_postgresql_schema.php');
    $migration->up();

    $columnTypes = DB::table('information_schema.columns')
        ->where('table_schema', 'reconciliation_contract')
        ->where('table_name', 'booking_terms')
        ->whereIn('column_name', ['booking_id', 'terms_and_condition_id'])
        ->pluck('udt_name', 'column_name');

    expect($columnTypes->all())->toBe([
        'booking_id' => 'uuid',
        'terms_and_condition_id' => 'uuid',
    ])->and(DB::table('booking_terms')->first())
        ->booking_id->toBe($bookingId)
        ->terms_and_condition_id->toBe($termsId);

    $constraints = DB::table('pg_constraint as constraint')
        ->join('pg_namespace as namespace', 'namespace.oid', '=', 'constraint.connamespace')
        ->where('namespace.nspname', 'reconciliation_contract')
        ->whereIn('constraint.conname', [
            'booking_terms_booking_id_foreign',
            'booking_terms_terms_and_condition_id_foreign',
        ])
        ->pluck('constraint.convalidated', 'constraint.conname');

    expect($constraints->all())->toBe([
        'booking_terms_booking_id_foreign' => true,
        'booking_terms_terms_and_condition_id_foreign' => true,
    ]);

    foreach ([
        ['bookings', 'bookings_status_booking_date_index'],
        ['bookings', 'bookings_customer_id_status_index'],
        ['bookings', 'bookings_corporate_account_id_status_index'],
        ['booking_items', 'booking_items_date_range_idx'],
        ['booking_items', 'booking_items_booking_date_idx'],
        ['driver_assignments', 'da_booking_id_index'],
        ['vehicle_assignments', 'va_vehicle_id_index'],
        ['vehicle_assignments', 'va_booking_id_index'],
        ['route_points', 'rp_session_recorded_index'],
    ] as [$table, $index]) {
        expect(Schema::hasIndex($table, $index))->toBeTrue("Missing {$index}");
    }

    $migration->down();

    expect(Schema::hasIndex('bookings', 'bookings_status_booking_date_index'))->toBeTrue()
        ->and(DB::table('booking_terms')->count())->toBe(1);
});

it('refuses orphaned ownership and succeeds after the records are repaired', function (): void {
    $bookingId = '33333333-3333-4333-8333-333333333333';
    $termsId = '44444444-4444-4444-8444-444444444444';

    DB::statement('CREATE TABLE bookings (id uuid PRIMARY KEY)');
    DB::statement('CREATE TABLE terms_and_conditions (id uuid PRIMARY KEY)');
    DB::statement('CREATE TABLE booking_terms (id bigserial PRIMARY KEY, booking_id varchar(36), terms_and_condition_id varchar(36))');
    DB::table('booking_terms')->insert([
        'booking_id' => $bookingId,
        'terms_and_condition_id' => $termsId,
    ]);

    $migration = require database_path('migrations/2026_07_22_000006_reconcile_existing_postgresql_schema.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'booking_terms.booking_id contains 1 orphaned value(s)');

    expect(DB::table('pg_constraint')->where('conname', 'booking_terms_booking_id_foreign')->exists())
        ->toBeFalse();

    DB::table('bookings')->insert(['id' => $bookingId]);
    DB::table('terms_and_conditions')->insert(['id' => $termsId]);

    $migration->up();

    $constraints = DB::table('pg_constraint as constraint')
        ->join('pg_namespace as namespace', 'namespace.oid', '=', 'constraint.connamespace')
        ->where('namespace.nspname', 'reconciliation_contract')
        ->whereIn('constraint.conname', [
            'booking_terms_booking_id_foreign',
            'booking_terms_terms_and_condition_id_foreign',
        ])
        ->where('constraint.convalidated', true)
        ->count();

    expect($constraints)->toBe(2)
        ->and(DB::table('booking_terms')->count())->toBe(1);
});
