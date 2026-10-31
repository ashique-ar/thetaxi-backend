<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->originalDatabaseConnection = config('database.default');
    config([
        'database.default' => 'inquiry_history_migration_test',
        'database.connections.inquiry_history_migration_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ],
    ]);
    DB::purge('inquiry_history_migration_test');
});

afterEach(function () {
    DB::purge('inquiry_history_migration_test');
    config(['database.default' => $this->originalDatabaseConnection]);
});

it('continues with a warning when the legacy drop already removed inquiry history', function () {
    Schema::create('migrations', function (Blueprint $table): void {
        $table->string('migration');
    });
    DB::table('migrations')->insert(['migration' => '2026_10_30_000052_drop_inquiry_company_scope_history']);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool =>
        str_contains($message, 'continuing installation-wide cleanup')
        && $context['missing_tables'] === ['inquiry_company_scope_events', 'inquiry_scope_history_events']
    );

    $migration = require database_path('migrations/2026_10_31_000032_preserve_inquiry_scope_history_without_company.php');
    $migration->up();

    expect(Schema::hasTable('inquiry_scope_history_events'))->toBeFalse();
});

it('preserves existing event evidence and idempotency while removing company scope on repeated runs', function () {
    Schema::create('inquiry_company_scope_events', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('company_id');
        $table->uuid('inquiry_id');
        $table->uuid('actor_user_id');
        $table->string('reason');
        $table->string('idempotency_key')->unique();
        $table->timestamp('occurred_at');
        $table->index(['company_id', 'inquiry_id', 'occurred_at'], 'inquiry_company_scope_history_idx');
    });
    $event = [
        'id' => 'event-1', 'inquiry_id' => 'inquiry-1', 'actor_user_id' => 'user-1',
        'reason' => 'Historical correction', 'idempotency_key' => 'request-1',
        'occurred_at' => '2026-10-29 10:00:00',
    ];
    DB::table('inquiry_company_scope_events')->insert($event + ['company_id' => 'former-company']);
    $migration = require database_path('migrations/2026_10_31_000032_preserve_inquiry_scope_history_without_company.php');
    $migration->up();
    $migration->up();

    expect((array) DB::table('inquiry_scope_history_events')->sole())->toBe($event)
        ->and(Schema::hasColumn('inquiry_scope_history_events', 'company_id'))->toBeFalse()
        ->and(Schema::hasIndex('inquiry_scope_history_events', ['idempotency_key'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('inquiry_scope_history_events', ['inquiry_id', 'occurred_at']))->toBeTrue();
});
