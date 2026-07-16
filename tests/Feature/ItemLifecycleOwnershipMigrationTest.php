<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('booking_dispatches');
    Schema::dropIfExists('booking_items');

    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->string('status')->default('confirmed');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('booking_dispatches', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->timestamps();
        $table->softDeletes();
    });
});

it('backfills only unambiguous legacy dispatches and enforces one dispatch per item', function () {
    DB::table('booking_items')->insert([
        ['id' => 'single-item', 'booking_id' => 'single-booking'],
        ['id' => 'multi-item-a', 'booking_id' => 'multi-booking'],
        ['id' => 'multi-item-b', 'booking_id' => 'multi-booking'],
    ]);
    DB::table('booking_dispatches')->insert([
        ['id' => 'single-dispatch', 'booking_id' => 'single-booking'],
        ['id' => 'ambiguous-dispatch', 'booking_id' => 'multi-booking'],
    ]);

    $migration = require database_path(
        'migrations/2026_07_16_140000_add_item_ownership_to_booking_lifecycle.php'
    );
    $migration->up();

    expect(Schema::hasColumns('booking_items', [
        'returned_at',
        'final_priced_at',
        'completed_at',
        'lifecycle_data',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('booking_dispatches', 'booking_item_id'))->toBeTrue()
        ->and(DB::table('booking_dispatches')->where('id', 'single-dispatch')->value('booking_item_id'))
        ->toBe('single-item')
        ->and(DB::table('booking_dispatches')->where('id', 'ambiguous-dispatch')->value('booking_item_id'))
        ->toBeNull();

    DB::table('booking_dispatches')->insert([
        'id' => 'item-dispatch',
        'booking_id' => 'multi-booking',
        'booking_item_id' => 'multi-item-a',
    ]);

    expect(fn () => DB::table('booking_dispatches')->insert([
        'id' => 'duplicate-item-dispatch',
        'booking_id' => 'multi-booking',
        'booking_item_id' => 'multi-item-a',
    ]))->toThrow(QueryException::class);

    $migration->down();

    expect(Schema::hasColumn('booking_dispatches', 'booking_item_id'))->toBeFalse()
        ->and(Schema::hasColumn('booking_items', 'completed_at'))->toBeFalse();
});
