<?php

use App\Services\HomepageCmsSections;
use App\Services\WebsiteSettingsService;

it('keeps the existing homepage blocks until CMS sections are configured', function () {
    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')->once()->with('homepage_cms_sections')->andReturn(null);

    expect((new HomepageCmsSections($settings))->load())->toBe(['managed' => false, 'sections' => []]);
});

it('allows editors to hide every CMS block with an explicit empty list', function () {
    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')->once()->with('homepage_cms_sections')->andReturn('[]');

    expect((new HomepageCmsSections($settings))->load())->toBe(['managed' => true, 'sections' => []]);
});

it('keeps the existing blocks if CMS section settings are malformed', function () {
    $settings = Mockery::mock(WebsiteSettingsService::class);
    $settings->shouldReceive('get')->once()->with('homepage_cms_sections')->andReturn('{invalid');

    expect((new HomepageCmsSections($settings))->load())->toBe(['managed' => false, 'sections' => []]);
});
