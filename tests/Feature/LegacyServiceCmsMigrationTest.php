<?php

use App\Models\InquiryServicePage;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Services\Website\LegacyServiceCmsMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    activity()->disableLogging();
    Schema::create('cms_content_types', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('slug');
        $table->string('title');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('cms_contents', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('cms_content_type_id');
        $table->uuid('inquiry_form_id')->nullable();
        $table->string('slug')->unique();
        $table->string('title')->nullable();
        $table->text('body')->nullable();
        $table->text('excerpt')->nullable();
        $table->string('featured_image')->nullable();
        $table->string('meta_title')->nullable();
        $table->text('meta_description')->nullable();
        $table->text('meta_tags')->nullable();
        $table->integer('display_order')->nullable();
        $table->string('status')->default('draft');
        $table->boolean('is_active')->default(true);
        $table->json('custom_fields')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('inquiry_service_pages', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('inquiry_form_id')->nullable();
        $table->string('name');
        $table->string('slug');
        $table->json('content')->nullable();
        $table->string('status')->default('draft');
        $table->string('seo_title')->nullable();
        $table->text('seo_description')->nullable();
        $table->string('seo_keywords')->nullable();
        $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('inquiry_service_page_sections', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('inquiry_service_page_id');
        $table->string('type');
        $table->json('data')->nullable();
        $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
});

it('reports and migrates matches and new drafts idempotently without replacing CMS copy', function () {
    $type = CmsContentType::create(['slug' => 'services', 'title' => 'Services']);
    $formId = (string) \Illuminate\Support\Str::uuid();
    $matched = CmsContent::create(['cms_content_type_id' => $type->id, 'slug' => 'airport-transfer', 'title' => 'CMS title', 'body' => 'CMS body', 'status' => 'published']);
    $legacyMatch = InquiryServicePage::create(['name' => 'Old title', 'slug' => 'airport-transfer', 'inquiry_form_id' => $formId]);
    $legacyNew = InquiryServicePage::create(['name' => 'City Tour', 'slug' => 'city-tour', 'content' => ['sections' => [['type' => 'hero', 'data' => ['subheading' => 'Explore', 'banner_image' => 'hero.jpg']], ['type' => 'content_block', 'data' => ['body' => '<p>Visit</p>']]]], 'status' => 'published', 'seo_title' => 'Tour SEO']);

    $migration = app(LegacyServiceCmsMigration::class);
    expect(collect($migration->report())->pluck('action'))->toContain('match', 'create_draft');
    $migration->apply();
    $matched->refresh();
    expect($matched->title)->toBe('CMS title')
        ->and($matched->body)->toBe('CMS body')
        ->and($matched->inquiry_form_id)->toBe($formId)
        ->and(data_get($matched->custom_fields, 'legacy_inquiry_service_page_id'))->toBe($legacyMatch->id);
    $draft = CmsContent::where('slug', 'city-tour')->firstOrFail();
    expect($draft->status)->toBe('draft')
        ->and($draft->body)->toBe('<p>Visit</p>')
        ->and($draft->excerpt)->toBe('Explore')
        ->and($draft->featured_image)->toBe('hero.jpg')
        ->and($draft->meta_title)->toBe('Tour SEO')
        ->and(data_get($draft->custom_fields, 'legacy_inquiry_service_page_id'))->toBe($legacyNew->id);
    $migration->apply();
    expect(CmsContent::count())->toBe(2)
        ->and(collect($migration->report())->pluck('action')->unique()->all())->toBe(['already_migrated']);
});

it('reports collisions and leaves conflicting CMS content untouched', function () {
    $type = CmsContentType::create(['slug' => 'services', 'title' => 'Services']);
    $other = CmsContentType::create(['slug' => 'articles', 'title' => 'Articles']);
    CmsContent::create(['cms_content_type_id' => $other->id, 'slug' => 'city-tour', 'title' => 'Article']);
    InquiryServicePage::create(['name' => 'City Tour', 'slug' => 'city-tour']);
    $migration = app(LegacyServiceCmsMigration::class);
    expect($migration->report()[0]['action'])->toBe('collision');
    $migration->apply();
    expect(CmsContent::count())->toBe(1);
});

it('does not create through a soft-deleted slug or an ambiguous normalized legacy slug', function () {
    $type = CmsContentType::create(['slug' => 'services', 'title' => 'Services']);
    $deleted = CmsContent::create(['cms_content_type_id' => $type->id, 'slug' => 'airport-transfer', 'title' => 'Retired']);
    $deleted->delete();
    InquiryServicePage::create(['name' => 'Airport', 'slug' => 'airport-transfer']);
    InquiryServicePage::create(['name' => 'City A', 'slug' => 'city-tour']);
    InquiryServicePage::create(['name' => 'City B', 'slug' => 'City Tour']);

    $migration = app(LegacyServiceCmsMigration::class);
    $report = collect($migration->report())->keyBy('legacy_slug');
    expect($report['airport-transfer']['action'])->toBe('collision')
        ->and($report['city-tour']['action'])->toBe('ambiguous')
        ->and($report['City Tour']['action'])->toBe('ambiguous');
    $migration->apply();
    expect(CmsContent::withTrashed()->count())->toBe(1);
});
