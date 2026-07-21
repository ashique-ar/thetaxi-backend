<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->originalDatabaseConnection = config('database.default');
    config([
        'database.default' => 'corporate_keyword_migration_test',
        'database.connections.corporate_keyword_migration_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);
    DB::purge('corporate_keyword_migration_test');

    Schema::dropIfExists('service_types');
    Schema::create('service_types', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('code');
        $table->string('name');
        $table->string('slug');
        $table->string('context');
        $table->string('owner_type')->default('');
        $table->string('owner_id')->default('');
        $table->text('description')->nullable();
        $table->timestamps();
        $table->unique(['code', 'context', 'owner_type', 'owner_id'], 'service_types_code_scope_unique');
        $table->unique(['slug', 'context', 'owner_type', 'owner_id'], 'service_types_slug_scope_unique');
    });
});

afterEach(function () {
    Schema::dropIfExists('service_types');
    DB::purge('corporate_keyword_migration_test');
    config(['database.default' => $this->originalDatabaseConnection]);
});

it('renames a legacy corporate service when its canonical scoped key is available', function () {
    insertCorporateKeywordServiceType('legacy', 'corp_on_meter', 'Corporate On Meter');

    corporateKeywordMigration()->up();

    expect(DB::table('service_types')->where('id', 'legacy')->first())
        ->code->toBe('on_meter')
        ->name->toBe('On Meter')
        ->slug->toBe('on-meter')
        ->description->toBe('Pricing service');
});

it('preserves the legacy internal key when the canonical scoped key already exists', function () {
    insertCorporateKeywordServiceType('legacy', 'corp_on_meter', 'Corporate On Meter');
    insertCorporateKeywordServiceType('canonical', 'on_meter', 'On Meter');

    corporateKeywordMigration()->up();

    expect(DB::table('service_types')->where('id', 'legacy')->first())
        ->code->toBe('corp_on_meter')
        ->name->toBe('On Meter')
        ->slug->toBe('corp-on-meter')
        ->description->toBe('Pricing service')
        ->and(DB::table('service_types')->where('id', 'canonical')->value('code'))->toBe('on_meter')
        ->and(DB::table('service_types')->count())->toBe(2);
});

function insertCorporateKeywordServiceType(string $id, string $code, string $name): void
{
    DB::table('service_types')->insert([
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'slug' => str_replace('_', '-', $code),
        'context' => 'corporate',
        'owner_type' => '',
        'owner_id' => '',
        'description' => 'Corporate pricing service',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function corporateKeywordMigration(): Migration
{
    return require database_path('migrations/2026_06_25_130000_remove_corporate_keywords_from_service_types.php');
}
