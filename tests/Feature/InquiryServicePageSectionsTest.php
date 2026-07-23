<?php

use function Pest\Laravel\get;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('companies', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('domain')->nullable();
        $table->string('website')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('website_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type')->nullable();
        $table->text('value')->nullable();
        $table->uuid('company_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('business_settings', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type')->nullable();
        $table->text('value')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $currencyMigration = require database_path('migrations/2025_07_05_042443_create_currencies_table.php');
    $currencyMigration->up();

    Schema::create('inquiry_forms', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('inquiry_form_fields', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('inquiry_form_id');
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    });

    $pageMigration = require database_path('migrations/2026_01_18_000002_create_inquiry_service_pages_table.php');
    $pageMigration->up();

    $canonicalMigration = require database_path('migrations/2026_01_22_000000_add_canonical_url_to_inquiry_service_pages.php');
    $canonicalMigration->up();

    $ogImageMigration = require database_path('migrations/2026_01_21_000000_add_seo_og_image_to_inquiry_service_pages.php');
    $ogImageMigration->up();

    $sectionMigration = require database_path('migrations/2026_01_22_000000_create_inquiry_service_page_sections_table.php');
    $sectionMigration->up();
});

afterEach(function () {
    Schema::dropIfExists('inquiry_service_page_sections');
    Schema::dropIfExists('inquiry_service_pages');
    Schema::dropIfExists('inquiry_form_fields');
    Schema::dropIfExists('inquiry_forms');
    Schema::dropIfExists('companies');
    Schema::dropIfExists('website_settings');
    Schema::dropIfExists('business_settings');
    Schema::dropIfExists('currencies');
});

it('renders page sections when sections exist in the DB', function () {
    $this->withoutMiddleware(\App\Http\Middleware\WebsiteSettingsSecurity::class);

    $page = \App\Models\InquiryServicePage::withoutEvents(fn () => \App\Models\InquiryServicePage::create([
        'name' => 'Sections Test',
        'slug' => 'sections-test-page',
        'code' => 'sections-test-page',
        'status' => 'published',
        'is_active' => true,
    ]));

    // Ensure we have a hero section
    \App\Models\InquiryServicePageSection::withoutEvents(fn () => \App\Models\InquiryServicePageSection::create([
        'inquiry_service_page_id' => $page->id,
        'type' => 'hero',
        'data' => ['heading' => 'DB Section Heading', 'subheading' => 'Subheading'],
        'sort_order' => 1,
        'is_active' => true,
    ]));

    $response = get('/services/sections-test-page');

    $response->assertStatus(200);
    $response->assertSee('DB Section Heading', false);
});
