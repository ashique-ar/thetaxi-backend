<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->proof = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/why-choose-us.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->customScript = file_get_contents($projectRoot . '/public/assets/js/custom.js');
    $this->settingsComposer = file_get_contents($projectRoot . '/app/Http/ViewComposers/SettingsViewComposer.php');
    $this->settingsService = file_get_contents($projectRoot . '/app/Services/WebsiteSettingsService.php');
});

it('selects a Theme 03 proof presenter while preserving released-theme markup', function () {
    expect($this->home)
        ->toContain("@if (\$settings['why_video_image'])")
        ->toContain("@include('partials.themes.theme-03.why-choose-us')")
        ->toContain('class="home4-why-choose-us-section"')
        ->toContain('class="why-choose-video-area mb-100"')
        ->toContain('class="why-choose-video-wrap"');
});

it('keeps the existing heading, description and four proof setting owners', function () {
    expect($this->proof)
        ->toContain("\$settings['why_section_title']")
        ->toContain("\$settings['offer_section_description']")
        ->not->toContain("\$settings['why_section_description']")
        ->and(substr_count($this->proof, "'label' =>"))->toBe(4)
        ->and($this->proof)
        ->toContain("\$settings['why_feature_1']")
        ->toContain("\$settings['why_feature_2']")
        ->toContain("\$settings['why_feature_3']")
        ->toContain("\$settings['why_feature_4']")
        ->toContain("\$settings['why_feature_icon_1']")
        ->toContain("\$settings['why_feature_icon_2']")
        ->toContain("\$settings['why_feature_icon_3']")
        ->toContain("\$settings['why_feature_icon_4']");
});

it('retains the fixed TripAdvisor evidence and managed assets', function () {
    expect($this->proof)
        ->toContain('href="https://www.tripadvisor.com/"')
        ->toContain('<strong>4.5</strong>')
        ->toContain("\$settings['tripadvisor_logo']")
        ->toContain("\$settings['tripadvisor_stars']")
        ->toContain('<small>Reviews</small>')
        ->and($this->settingsComposer)
        ->toContain("'tripadvisor_logo'")
        ->toContain("'tripadvisor_stars'")
        ->and($this->settingsService)
        ->toContain("'tripadvisor_logo'")
        ->toContain("'tripadvisor_stars'");
});

it('preserves the media condition, video URL and shared Fancybox hook', function () {
    expect($this->proof)
        ->toContain("\$settings['why_video_image'] ? s3_asset(\$settings['why_video_image'])")
        ->toContain("asset('assets/img/home4/why-choose-video-img.jpg')")
        ->toContain('width="1600"')
        ->toContain('height="1080"')
        ->toContain('loading="lazy"')
        ->toContain('data-fancybox="video-player"')
        ->toContain('href="https://www.youtube.com/watch?v=u31qwQUeGuM"')
        ->and($this->customScript)
        ->toContain("$('[data-fancybox=\"video-player\"]')")
        ->toContain('buttons: ["close"]')
        ->toContain('loop: false')
        ->toContain('protect: true');
});

it('keeps the help copy and sanitized telephone action unchanged', function () {
    expect($this->proof)
        ->toContain("\$settings['why_help_text']")
        ->toContain("\$settings['header_help_label']")
        ->toContain("preg_replace('/[^0-9+]/', '', \$settings['company_phone'] ?? '')")
        ->toContain("\$settings['company_phone'] ?? 'Call us'")
        ->not->toContain('<form')
        ->not->toContain('<script');
});

it('provides a responsive split media and proof ledger scoped to Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-proof')
        ->toContain('body.theme-theme-03 .t3-proof__heading')
        ->toContain('body.theme-theme-03 .t3-proof__rating.single-rating')
        ->toContain('body.theme-theme-03 .t3-proof__layout')
        ->toContain('body.theme-theme-03 .t3-proof__media')
        ->toContain('body.theme-theme-03 .t3-proof__play.play-btn')
        ->toContain('body.theme-theme-03 .t3-proof__ledger li')
        ->toContain('body.theme-theme-03 .t3-proof__contact.contact-area')
        ->toContain('@media (max-width: 767px)');
});

