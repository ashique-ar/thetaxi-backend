<?php

use function Pest\Laravel\get;

it('renders page sections when sections exist in the DB', function () {
    $page = \App\Models\InquiryServicePage::create([
        'name' => 'Sections Test',
        'slug' => 'sections-test-page',
        'status' => 'published',
        'is_active' => true,
    ]);

    // Ensure we have a hero section
    \App\Models\InquiryServicePageSection::create([
        'inquiry_service_page_id' => $page->id,
        'type' => 'hero',
        'data' => ['heading' => 'DB Section Heading', 'subheading' => 'Subheading'],
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $response = get('/services/sections-test-page');

    $response->assertStatus(200);
    $response->assertSee('DB Section Heading', false);
});
