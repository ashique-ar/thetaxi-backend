<?php

use function Pest\Laravel\get;
use Illuminate\Support\Facades\Artisan;

beforeAll(function () {
    // Ensure database seeded for known content like 'corporate-transfers'
    Artisan::call('db:seed', ['--class' => 'CorporateTransferInquirySeeder']);
    Artisan::call('db:seed', ['--class' => 'WebsiteContentSeeder']);
});

it('renders meta tags and JSON-LD on inquiry service page', function () {
    $response = get('/services/corporate-transfers');

    $response->assertStatus(200);

    // Check meta description from seeder
    $response->assertSee('Professional corporate transport solutions with tailored business services', false);

    // Check canonical link
    $response->assertSee('<link rel="canonical"', false);

    // Check JSON-LD
    $response->assertSee('application/ld+json', false);
});

it('serves sitemap.xml and contains key urls', function () {
    $response = get('/sitemap.xml');

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/xml');

    $content = $response->getContent();

    // The sitemap should contain the home page and at least the services permalink
    expect(strpos($content, url('/')))->toBeGreaterThanOrEqual(0);
    expect(strpos($content, route('inquiry-services.show', 'corporate-transfers')))->toBeGreaterThanOrEqual(0);
});
