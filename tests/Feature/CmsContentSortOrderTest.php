<?php

use App\Http\Controllers\Api\Website\CmsContentController;
use App\Http\Controllers\Website\CmsController;
use App\Http\Controllers\Website\HomeController;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use App\Services\DiscountService;
use App\Services\HomepageCmsSections;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    Schema::dropIfExists('activity_log');
    Schema::dropIfExists('cms_contents');
    Schema::dropIfExists('cms_content_types');
    Schema::dropIfExists('faqs');

    Schema::create('activity_log', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->nullableUuidMorphs('subject');
        $table->string('event')->nullable();
        $table->nullableUuidMorphs('causer');
        $table->json('properties')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });

    Schema::create('cms_content_types', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('parent_id')->nullable();
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->text('description')->nullable();
        $table->string('icon')->nullable();
        $table->json('template_config')->nullable();
        $table->boolean('is_active')->default(true);
        $table->integer('display_order')->nullable();
        $table->string('url_prefix')->nullable();
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('cms_contents', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('cms_content_type_id');
        $table->uuid('inquiry_form_id')->nullable();
        $table->string('title')->nullable();
        $table->string('slug')->unique();
        $table->string('author')->nullable();
        $table->string('thumbnail')->nullable();
        $table->text('body')->nullable();
        $table->string('meta_title')->nullable();
        $table->text('meta_description')->nullable();
        $table->text('meta_tags')->nullable();
        $table->boolean('is_active')->default(true);
        $table->integer('display_order')->nullable();
        $table->string('url')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->string('status')->default('draft');
        $table->text('excerpt')->nullable();
        $table->json('custom_fields')->nullable();
        $table->string('featured_image')->nullable();
        $table->json('gallery_images')->nullable();
        $table->integer('views_count')->default(0);
        $table->boolean('is_featured')->default(false);
        $table->boolean('allow_comments')->default(true);
        $table->boolean('is_ai_generated')->default(false);
        $table->decimal('price', 10, 2)->nullable();
        $table->string('price_currency')->nullable();
        $table->string('duration')->nullable();
        $table->decimal('rating', 3, 1)->nullable();
        $table->integer('reviews_count')->nullable();
        $table->string('location')->nullable();
        $table->string('category')->nullable();
        $table->string('availability_status')->nullable();
        $table->string('pickup_location')->nullable();
        $table->string('dropoff_location')->nullable();
        $table->string('service_type')->nullable();
        $table->integer('min_days')->nullable();
        $table->decimal('pickup_lat', 10, 8)->nullable();
        $table->decimal('pickup_lng', 11, 8)->nullable();
        $table->decimal('dropoff_lat', 10, 8)->nullable();
        $table->decimal('dropoff_lng', 11, 8)->nullable();
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('faqs', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('question');
        $table->text('answer');
        $table->boolean('is_active')->default(true);
        $table->boolean('is_featured')->default(false);
        $table->integer('sort_order')->default(0);
    });
});

function seedCmsSortItems(string $typeSlug = 'services'): array
{
    $type = CmsContentType::create([
        'id' => (string) Str::uuid(),
        'title' => ucfirst($typeSlug),
        'slug' => $typeSlug,
        'is_active' => true,
        'display_order' => 1,
    ]);

    // Created in reverse/mixed order with newer published_at on higher display_order
    // and one item with null display_order to verify nulls sort last.
    $nullOrder = CmsContent::create([
        'id' => (string) Str::uuid(),
        'cms_content_type_id' => $type->id,
        'title' => 'Alpha Null Order',
        'slug' => 'alpha-null-order',
        'status' => 'published',
        'is_active' => true,
        'is_featured' => true,
        'display_order' => null,
        'published_at' => now()->subMinutes(1),
    ]);

    $third = CmsContent::create([
        'id' => (string) Str::uuid(),
        'cms_content_type_id' => $type->id,
        'title' => 'Third Item',
        'slug' => 'third-item',
        'status' => 'published',
        'is_active' => true,
        'is_featured' => true,
        'display_order' => 3,
        'published_at' => now()->subMinutes(2),
    ]);

    $first = CmsContent::create([
        'id' => (string) Str::uuid(),
        'cms_content_type_id' => $type->id,
        'title' => 'First Item',
        'slug' => 'first-item',
        'status' => 'published',
        'is_active' => true,
        'is_featured' => true,
        'display_order' => 1,
        'published_at' => now()->subMinutes(10),
    ]);

    $second = CmsContent::create([
        'id' => (string) Str::uuid(),
        'cms_content_type_id' => $type->id,
        'title' => 'Second Item',
        'slug' => 'second-item',
        'status' => 'published',
        'is_active' => true,
        'is_featured' => true,
        'display_order' => 2,
        'published_at' => now()->subMinutes(5),
    ]);

    return [$type, [$first->title, $second->title, $third->title, $nullOrder->title]];
}

it('orders admin and public API cms content listings by display_order with nulls last', function () {
    [, $expected] = seedCmsSortItems('services');

    $controller = new CmsContentController();

    $adminSlugs = collect($controller->index(Request::create('/api/cms-contents', 'GET'))->collection)
        ->pluck('title')
        ->all();
    expect($adminSlugs)->toBe($expected);

    $publicTitles = collect($controller->published(Request::create('/api/public/cms-contents/published', 'GET'))->collection)
        ->pluck('title')
        ->all();
    expect($publicTitles)->toBe($expected);
});

it('orders managed homepage CMS sections by display_order', function () {
    [$type, $expected] = seedCmsSortItems('services');

    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')->once()->with('homepage_cms_sections')->andReturn(json_encode([
        [
            'enabled' => true,
            'content_type_id' => $type->id,
            'mode' => 'featured',
            'limit' => 6,
            'layout' => 'cards',
        ],
    ]));

    $result = (new HomepageCmsSections($settings))->load();
    $titles = $result['sections'][0]['items']->pluck('title')->all();

    expect($titles)->toBe($expected);
});

it('orders legacy homepage CMS queries by display_order', function () {
    [, $expected] = seedCmsSortItems('services');

    $controller = new HomeController();
    $method = new ReflectionMethod(HomeController::class, 'getCompleteHomeData');
    $method->setAccessible(true);
    $data = $method->invoke($controller);

    $titles = $data['inspirations']->pluck('title')->all();
    expect($titles)->toBe(array_slice($expected, 0, 3));
});

it('orders public CMS content type listing by display_order by default while preserving title sort', function () {
    [, $expected] = seedCmsSortItems('services');

    $controller = new CmsController(
        Mockery::mock(BookingFlowService::class),
        Mockery::mock(CurrencyService::class),
        Mockery::mock(DiscountService::class),
        Mockery::mock(WebsiteSettingsService::class),
    );

    $defaultView = $controller->index('services', Request::create('/cms/services', 'GET'));
    $defaultTitles = $defaultView->getData()['contents']->getCollection()->pluck('title')->all();
    expect($defaultTitles)->toBe($expected);

    $titleView = $controller->index('services', Request::create('/cms/services?sort=title', 'GET'));
    $titleSorted = $titleView->getData()['contents']->getCollection()->pluck('title')->all();
    expect($titleSorted)->toBe(['Alpha Null Order', 'First Item', 'Second Item', 'Third Item']);
});
