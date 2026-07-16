<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('booking_qcs');
    Schema::dropIfExists('booking_dispatches');
    Schema::dropIfExists('booking_items');

    Schema::create('booking_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('booking_dispatches', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('booking_item_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('booking_qcs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('booking_id');
        $table->uuid('dispatch_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

it('backfills QC ownership from item dispatches or an unambiguous single item', function () {
    DB::table('booking_items')->insert([
        ['id' => 'dispatch-item', 'booking_id' => 'dispatch-booking'],
        ['id' => 'single-item', 'booking_id' => 'single-booking'],
        ['id' => 'multi-item-a', 'booking_id' => 'multi-booking'],
        ['id' => 'multi-item-b', 'booking_id' => 'multi-booking'],
    ]);
    DB::table('booking_dispatches')->insert([
        'id' => 'item-dispatch',
        'booking_id' => 'dispatch-booking',
        'booking_item_id' => 'dispatch-item',
    ]);
    DB::table('booking_qcs')->insert([
        ['id' => 'dispatch-qc', 'booking_id' => 'dispatch-booking', 'dispatch_id' => 'item-dispatch'],
        ['id' => 'single-qc', 'booking_id' => 'single-booking', 'dispatch_id' => null],
        ['id' => 'ambiguous-qc', 'booking_id' => 'multi-booking', 'dispatch_id' => null],
    ]);

    $migration = require database_path(
        'migrations/2026_07_16_140001_add_item_ownership_to_booking_qcs.php'
    );
    $migration->up();

    expect(DB::table('booking_qcs')->where('id', 'dispatch-qc')->value('booking_item_id'))
        ->toBe('dispatch-item')
        ->and(DB::table('booking_qcs')->where('id', 'single-qc')->value('booking_item_id'))
        ->toBe('single-item')
        ->and(DB::table('booking_qcs')->where('id', 'ambiguous-qc')->value('booking_item_id'))
        ->toBeNull();

    DB::table('booking_qcs')->insert([
        'id' => 'owned-multi-qc',
        'booking_id' => 'multi-booking',
        'booking_item_id' => 'multi-item-a',
    ]);
    expect(fn () => DB::table('booking_qcs')->insert([
        'id' => 'duplicate-multi-qc',
        'booking_id' => 'multi-booking',
        'booking_item_id' => 'multi-item-a',
    ]))->toThrow(QueryException::class);

    $migration->down();
    expect(Schema::hasColumn('booking_qcs', 'booking_item_id'))->toBeFalse();
});
