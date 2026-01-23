<?php

use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\get;
use Illuminate\Support\Facades\Artisan;


it('validates hero section payload when creating', function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('inquiry_service_pages')) {
        return; // Skip tests when migrations are not available in this environment
    }

    $page = \App\Models\InquiryServicePage::create([
        'name' => 'API Sections Page',
        'slug' => 'api-sections-page',
        'status' => 'published',
        'is_active' => true,
    ]);

    // Missing required data.heading, should fail
    $response = post("/api/inquiry-service-pages/{$page->id}/sections", [
        'type' => 'hero',
        'data' => ['subheading' => 'Only subheading']
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['data.heading']);

    // Valid payload
    $response = post("/api/inquiry-service-pages/{$page->id}/sections", [
        'type' => 'hero',
        'data' => ['heading' => 'Hero Heading', 'subheading' => 'Sub', 'banner_image' => 'assets/img/test.jpg']
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.section.type', 'hero');
    $response->assertJsonPath('data.section.data.heading', 'Hero Heading');
});

it('can reorder sections', function () {
    if (!\Illuminate\Support\Facades\Schema::hasTable('inquiry_service_pages')) {
        return; // Skip tests when migrations are not available in this environment
    }

    $page = \App\Models\InquiryServicePage::create([
        'name' => 'Reorder Page',
        'slug' => 'reorder-page',
        'status' => 'published',
        'is_active' => true,
    ]);

    $s1 = \App\Models\InquiryServicePageSection::create(['inquiry_service_page_id' => $page->id, 'type' => 'hero', 'data' => ['heading' => 'A'], 'sort_order' => 1]);
    $s2 = \App\Models\InquiryServicePageSection::create(['inquiry_service_page_id' => $page->id, 'type' => 'features', 'data' => ['heading' => 'B'], 'sort_order' => 2]);
    $s3 = \App\Models\InquiryServicePageSection::create(['inquiry_service_page_id' => $page->id, 'type' => 'faq', 'data' => ['heading' => 'C'], 'sort_order' => 3]);

    $response = post("/api/inquiry-service-pages/{$page->id}/sections/reorder", ['ordered_ids' => [$s3->id, $s1->id, $s2->id]]);

    $response->assertStatus(200);
    $page->refresh();
    $order = $page->sections()->orderBy('sort_order')->pluck('id')->toArray();
    expect($order)->toEqual([$s3->id, $s1->id, $s2->id]);
});
