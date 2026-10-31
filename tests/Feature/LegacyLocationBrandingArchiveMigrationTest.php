<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->originalDatabaseConnection = config('database.default');
    config([
        'database.default' => 'branding_archive_test',
        'database.connections.branding_archive_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ],
    ]);
    DB::purge('branding_archive_test');
    Schema::create('companies', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('domain')->nullable();
        $table->string('website')->nullable();
        $table->softDeletes();
    });
});

it('keeps complete branding evidence for deleted locations after the obsolete columns are dropped', function () {
    Schema::table('companies', function (Blueprint $table): void {
        $table->string('brand_name')->nullable();
        $table->string('logo')->nullable();
        $table->index('domain');
    });
    $values = ['brand_name' => 'Legacy transport', 'domain' => 'old.example', 'logo' => 'logos/legacy.svg'];
    DB::table('companies')->insert($values + [
        'id' => 'deleted-location', 'website' => 'https://location.example', 'deleted_at' => now(),
    ]);

    $archive = require database_path('migrations/2026_10_31_000024_500000_archive_legacy_location_branding.php');
    $remove = require database_path('migrations/2026_10_31_000025_remove_company_tenant_branding_fields.php');
    $archive->up();
    $remove->up();
    $archive->up();
    $remove->up();

    $saved = DB::table('legacy_location_branding_archive')->where('source_record_id', 'deleted-location')->sole();
    expect(json_decode($saved->branding_values, true, 512, JSON_THROW_ON_ERROR))->toBe($values)
        ->and(Schema::hasColumn('companies', 'brand_name'))->toBeFalse()
        ->and(Schema::hasColumn('companies', 'domain'))->toBeFalse()
        ->and(Schema::hasColumn('companies', 'logo'))->toBeFalse()
        ->and(DB::table('companies')->where('id', 'deleted-location')->value('website'))->toBe('https://location.example')
        ->and(DB::table('companies')->where('id', 'deleted-location')->value('deleted_at'))->not->toBeNull();
});

afterEach(function () {
    DB::purge('branding_archive_test');
    config(['database.default' => $this->originalDatabaseConnection]);
});

it('preserves distinct domains before removing branding and can retry safely', function () {
    DB::table('companies')->insert([
        ['id' => 'location-a', 'domain' => 'one.example', 'website' => 'https://location.example'],
        ['id' => 'location-b', 'domain' => 'two.example', 'website' => null],
    ]);
    $migration = require database_path('migrations/2026_10_31_000024_500000_archive_legacy_location_branding.php');
    $migration->up();
    $migration->up();

    expect(DB::table('legacy_location_branding_archive')->count())->toBe(2)
        ->and(DB::table('companies')->whereNotNull('domain')->count())->toBe(0)
        ->and(DB::table('companies')->where('id', 'location-a')->value('website'))->toBe('https://location.example');
    foreach (['location-a' => 'one.example', 'location-b' => 'two.example'] as $id => $domain) {
        $values = json_decode(DB::table('legacy_location_branding_archive')->where('source_record_id', $id)->value('branding_values'), true);
        expect($values)->toBe(['domain' => $domain]);
    }

    DB::table('companies')->where('id', 'location-a')->update(['domain' => 'changed.example']);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    expect(DB::table('companies')->where('id', 'location-a')->value('domain'))->toBe('changed.example');
});
