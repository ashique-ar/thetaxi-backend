<?php

use App\Services\WebsiteSettingsService;
use Illuminate\Support\Facades\Cache;

it('renders the same saved banner format and copy in every theme', function (string $view, string $mode) {
    $settings = [
        'hero_display_mode' => $mode,
        'hero_slides' => json_encode([
            ['type' => 'image', 'desktop' => 'first.jpg', 'mobile' => 'phone.jpg', 'video' => 'first.mp4', 'poster' => 'poster.jpg',
                'heading' => 'Our shared heading', 'subheading' => 'Our shared description', 'caption' => 'Shared caption',
                'eyebrow' => 'Shared introduction', 'location_label' => 'Colombo', 'cta_text' => 'Our fleet', 'cta_url' => '/vehicles'],
            ['type' => 'image', 'desktop' => 'second.jpg', 'heading' => 'Second shared heading'],
        ]),
        'banner_heading' => 'Outdated heading', 'theme_02_slider_images' => '[{"desktop":"outdated.jpg"}]',
    ];
    Cache::put('global_settings_flattened_hero-render-test', ['banner_image' => 'outdated.jpg'], 86400);
    $service = Mockery::mock(WebsiteSettingsService::class);
    $service->shouldReceive('resolveCompanyId')->andReturn('hero-render-test');
    $service->shouldReceive('getMultiple')->andReturnUsing(function (array $keys) use ($settings) {
        expect($keys)->toContain('hero_slides', 'hero_display_mode');
        return $settings;
    });
    $service->shouldReceive('withCanonicalBranding', 'withSeoDefaults')->andReturnUsing(fn ($values) => $values);
    $this->app->instance(WebsiteSettingsService::class, $service);
    view()->setDispatcher(new Illuminate\Events\Dispatcher($this->app));
    view()->composer('*', App\Http\ViewComposers\SettingsViewComposer::class);

    $html = view($view)->render();
    expect($html)->toContain('Our shared heading', 'Our shared description', 'Shared caption', 'Shared introduction', 'Colombo', '/vehicles', 'Our fleet')
        ->not->toContain('outdated.jpg', 'Outdated heading');
    expect(substr_count($html, 'class="swiper-slide'))->toBe($mode === 'carousel' ? 2 : 1);
    if ($mode === 'video') {
        expect($html)->toContain('<video', 'first.mp4', 'poster.jpg')->not->toContain('<picture>');
    } else {
        expect($html)->toContain('<picture>', 'first.jpg', 'phone.jpg')->not->toContain('<video');
    }
    if ($mode === 'carousel') {
        expect($html)->toContain('Second shared heading', 'second.jpg', 'shared-hero-pagination');
    } else {
        expect($html)->not->toContain('Second shared heading', 'second.jpg', 'class="shared-hero-pagination');
    }
})->with(['partials.hero', 'partials.themes.theme-02.hero', 'partials.themes.theme-03.hero', 'partials.themes.theme-04.hero'])
    ->with(['image', 'video', 'carousel']);
