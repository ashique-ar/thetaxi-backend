<?php

namespace Tests\Unit;

use App\Http\ViewComposers\SettingsViewComposer;
use App\Services\WebsiteSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Mockery;
use Tests\TestCase;

class Theme04FooterSettingsTest extends TestCase
{
    public function test_uploaded_banner_and_content_settings_reach_public_views(): void
    {
        Cache::forget('global_settings_flattened_footer-test');
        $service = Mockery::mock(WebsiteSettingsService::class);
        $service->shouldReceive('resolveCompanyId')->once()->andReturn('footer-test');
        $service->shouldReceive('getMultiple')->once()->andReturnUsing(function (array $keys) {
            foreach (['footer_cta_enabled', 'footer_cta_image', 'footer_cta_heading', 'footer_cta_description', 'footer_cta_book_url', 'footer_cta_location'] as $key) {
                $this->assertContains($key, $keys);
            }
            return ['footer_cta_image' => 'footer/uploaded-banner.webp', 'footer_cta_heading' => 'Our Journey'];
        });
        $service->shouldReceive('withCanonicalBranding')->once()->andReturnUsing(fn (array $settings) => $settings);
        $service->shouldReceive('withSeoDefaults')->once()->andReturnUsing(fn (array $settings) => $settings);
        $view = Mockery::mock(View::class);
        $view->shouldReceive('with')->once()->with('settings', Mockery::on(function (array $settings) {
            return $settings['footer_cta_image'] === 'footer/uploaded-banner.webp'
                && $settings['footer_cta_heading'] === 'Our Journey';
        }));

        (new SettingsViewComposer($service))->compose($view);
    }
}
