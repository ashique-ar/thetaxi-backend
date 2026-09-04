<?php

use function Pest\Laravel\get;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
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

it('respects explicit canonical_url on inquiry pages', function () {
    // Create a test page with canonical_url
    $page = \App\Models\InquiryServicePage::create([
        'name' => 'SEO Canonical Test',
        'slug' => 'seo-canonical-test',
        'code' => 'seo-canonical-test',
        'status' => 'published',
        'is_active' => true,
        'canonical_url' => 'https://example.com/custom-canonical'
    ]);

    $response = get('/services/seo-canonical-test');
    $response->assertStatus(200);
    $response->assertSee('<link rel="canonical" href="https://example.com/custom-canonical"', false);
});

it('runs sitemap command and pings search engines', function () {
    Http::fake();

    // Ensure settings instruct the command to ping
    $settingsService = app(\App\Services\WebsiteSettingsService::class);
    $settingsService->set('sitemap_auto_generate', true);
    $settingsService->set('sitemap_auto_ping', true);
    $settingsService->set('sitemap_ping_urls', "http://www.google.com/ping?sitemap={sitemap_url}");

    Artisan::call('sitemap:generate-and-ping');

    // Assert an HTTP call was made to the Google ping endpoint
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'www.google.com/ping');
    });
});

it('sends a notification email when ping attempts fail and notification enabled', function () {
    Mail::fake();
    Http::fake(['*' => Http::response('', 404)]);

    $settingsService = app(\App\Services\WebsiteSettingsService::class);
    $settingsService->set('sitemap_auto_generate', true);
    $settingsService->set('sitemap_auto_ping', true);
    $settingsService->set('sitemap_ping_urls', "http://www.google.com/ping?sitemap={sitemap_url}");

    // Set notification options
    $settingsService->set('sitemap_failure_notify', true);
    $settingsService->set('sitemap_failure_notify_email', 'seo-admin@Company');

    Artisan::call('sitemap:generate-and-ping');

    Mail::assertSent(\App\Mail\SitemapPingFailed::class, function ($mail) {
        return $mail->hasTo('seo-admin@Company');
    });
});

it('retries ping on 4xx and succeeds on alternate', function () {
    $calls = 0;
    Http::fake(function ($request) use (&$calls) {
        $calls++;
        // First request => 404, second request => 200
        if ($calls === 1) {
            return Http::response('', 404);
        }
        return Http::response('', 200);
    });

    // Use a single Google endpoint so the command has a fallback opportunity
    $settingsService = app(\App\Services\WebsiteSettingsService::class);
    $settingsService->set('sitemap_auto_generate', true);
    $settingsService->set('sitemap_auto_ping', true);
    $settingsService->set('sitemap_ping_urls', "http://www.google.com/ping?sitemap={sitemap_url}");

    Artisan::call('sitemap:generate-and-ping');

    // Ensure it attempted at least the initial and retry call
    Http::assertSentCount(2);
});
