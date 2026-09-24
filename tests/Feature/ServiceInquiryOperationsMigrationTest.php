<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('adds inquiry operations fields idempotently without changing legacy columns', function () {
    Schema::dropIfExists('inquiries');
    Schema::create('inquiries', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('status')->default('open');
    });

    $migration = require database_path('migrations/2026_09_22_000002_add_inquiry_operations_fields.php');
    $migration->up();
    $migration->up();

    foreach (['priority', 'response', 'responded_at', 'notes'] as $column) {
        expect(Schema::hasColumn('inquiries', $column))->toBeTrue();
    }
    expect(Schema::hasColumn('inquiries', 'status'))->toBeTrue();
});
