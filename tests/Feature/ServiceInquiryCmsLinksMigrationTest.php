<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    Schema::dropIfExists('inquiries');
    Schema::dropIfExists('cms_contents');
    Schema::dropIfExists('cms_content_types');
    Schema::dropIfExists('inquiry_forms');

    Schema::create('inquiry_forms', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('slug')->unique();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
    Schema::create('cms_content_types', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('title');
        $table->string('slug')->unique();
        $table->timestamps();
    });
    Schema::create('cms_contents', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('cms_content_type_id');
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->timestamps();
    });
    Schema::create('inquiries', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('inquiry_number')->nullable()->unique();
        $table->string('inquiry_type');
        $table->text('message');
        $table->timestamps();
    });
});

it('adds idempotent nullable service inquiry links with null-on-delete constraints', function () {
    $migration = require database_path('migrations/2026_09_22_000001_add_service_inquiry_cms_links.php');

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('cms_contents', 'inquiry_form_id'))->toBeTrue()
        ->and(Schema::hasColumn('inquiries', 'cms_content_id'))->toBeTrue()
        ->and(collect(Schema::getIndexes('cms_contents'))->pluck('name'))
        ->toContain('cms_contents_inquiry_form_id_index')
        ->and(collect(Schema::getIndexes('inquiries'))->pluck('name'))
        ->toContain('inquiries_cms_content_id_index');

    $formId = (string) Str::uuid();
    $contentTypeId = (string) Str::uuid();
    $contentId = (string) Str::uuid();
    $inquiryId = (string) Str::uuid();

    DB::table('inquiry_forms')->insert([
        'id' => $formId,
        'name' => 'Migration contract form',
        'slug' => 'migration-contract-form',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('cms_content_types')->insert([
        'id' => $contentTypeId,
        'title' => 'Services',
        'slug' => 'migration-contract-services',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('cms_contents')->insert([
        'id' => $contentId,
        'cms_content_type_id' => $contentTypeId,
        'title' => 'Migration contract service',
        'slug' => 'migration-contract-service',
        'inquiry_form_id' => $formId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('inquiries')->insert([
        'id' => $inquiryId,
        'inquiry_number' => 'INQMIGRATION',
        'inquiry_type' => 'general',
        'message' => 'Migration contract inquiry',
        'cms_content_id' => $contentId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('inquiry_forms')->where('id', $formId)->delete();
    expect(DB::table('cms_contents')->where('id', $contentId)->value('inquiry_form_id'))->toBeNull();

    DB::table('cms_contents')->where('id', $contentId)->delete();
    expect(DB::table('inquiries')->where('id', $inquiryId)->value('cms_content_id'))->toBeNull();

    $migration->down();
    expect(Schema::hasColumn('cms_contents', 'inquiry_form_id'))->toBeFalse()
        ->and(Schema::hasColumn('inquiries', 'cms_content_id'))->toBeFalse();
});
