<?php

use App\Models\Activity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Intervention\Image\Laravel\Facades\Image;

afterEach(function (): void {
    DB::purge('activitylog_upgrade_test');
});

it('migrates activity log changes to the version five schema without losing custom properties', function () {
    config([
        'activitylog.database_connection' => 'activitylog_upgrade_test',
        'activitylog.table_name' => 'activity_log',
        'database.connections.activitylog_upgrade_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    $schema = Schema::connection('activitylog_upgrade_test');

    $schema->create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->json('properties')->nullable();
        $table->uuid('batch_uuid')->nullable();
    });

    DB::connection('activitylog_upgrade_test')->table('activity_log')->insert([
        'properties' => json_encode([
            'attributes' => ['status' => 'confirmed'],
            'old' => ['status' => 'pending'],
            'source' => 'dispatch-console',
        ], JSON_THROW_ON_ERROR),
        'batch_uuid' => '11111111-1111-1111-1111-111111111111',
    ]);

    $migration = require database_path('migrations/2026_07_21_000001_upgrade_activity_log_to_v5.php');
    $migration->up();

    expect($schema->hasColumn('activity_log', 'attribute_changes'))->toBeTrue()
        ->and($schema->hasColumn('activity_log', 'batch_uuid'))->toBeFalse();

    $activity = DB::connection('activitylog_upgrade_test')->table('activity_log')->first();

    expect(json_decode($activity->attribute_changes, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'attributes' => ['status' => 'confirmed'],
        'old' => ['status' => 'pending'],
    ])->and(json_decode($activity->properties, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'source' => 'dispatch-console',
    ]);

    $model = new Activity();

    expect($model->getTable())->toBe('activity_log')
        ->and($model->getConnectionName())->toBe('activitylog_upgrade_test');

    $migration->down();

    expect($schema->hasColumn('activity_log', 'attribute_changes'))->toBeFalse()
        ->and($schema->hasColumn('activity_log', 'batch_uuid'))->toBeTrue();

    $rolledBack = DB::connection('activitylog_upgrade_test')->table('activity_log')->first();
    $properties = json_decode($rolledBack->properties, true, flags: JSON_THROW_ON_ERROR);

    expect($properties)->toMatchArray([
        'source' => 'dispatch-console',
        'attributes' => ['status' => 'confirmed'],
        'old' => ['status' => 'pending'],
    ]);
});

it('uses the Intervention Image version four decoding and encoding API', function () {
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );

    $image = Image::decode($png)->cover(1, 1);
    $encoded = $image->encodeUsingFileExtension('png');

    expect($image->width())->toBe(1)
        ->and($image->height())->toBe(1)
        ->and((string) $encoded)->toStartWith("\x89PNG");
});
