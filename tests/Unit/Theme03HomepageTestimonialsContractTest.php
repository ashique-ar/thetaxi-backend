<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->testimonials = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/testimonials.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->customScript = file_get_contents($projectRoot . '/public/assets/js/custom.js');
    $this->homeController = file_get_contents($projectRoot . '/app/Http/Controllers/Website/HomeController.php');
    $this->settingsComposer = file_get_contents($projectRoot . '/app/Http/ViewComposers/SettingsViewComposer.php');
    $this->settingsService = file_get_contents($projectRoot . '/app/Services/WebsiteSettingsService.php');
});

it('selects a Theme 03 testimonial presenter behind the existing non-empty condition', function () {
    expect($this->home)
        ->toContain('@if ($testimonials && $testimonials->count() > 0)')
        ->toContain("@include('partials.themes.theme-03.testimonials')")
        ->toContain('class="home4-testimonial-section mb-100"')
        ->toContain('class="testimonial-wrap"')
        ->toContain('class="testimonial-card five"');
});

it('keeps the testimonial records and existing display fields as the only quote source', function () {
    expect($this->testimonials)
        ->toContain('@foreach ($testimonials as $testimonial)')
        ->toContain('$testimonial->rating')
        ->toContain("\$testimonial->position ?? 'Customer Review'")
        ->toContain('$testimonial->content')
        ->toContain('$testimonial->name')
        ->toContain('$testimonial->company ?? $testimonial->location')
        ->not->toContain('<form')
        ->not->toContain('<script');
});

it('preserves managed section copy, five author images and the decorative vector', function () {
    expect($this->testimonials)
        ->toContain("\$settings['testimonials_section_title']")
        ->toContain("\$settings['testimonials_section_description']")
        ->toContain("\$settings['testimonial_vector']")
        ->and(substr_count($this->testimonials, "\$settings['testimonial_author_img_"))->toBe(5)
        ->and($this->settingsComposer)
        ->toContain("'testimonials_section_title'")
        ->toContain("'testimonials_section_description'")
        ->toContain("'testimonial_author_img_1'")
        ->toContain("'testimonial_author_img_5'")
        ->toContain("'testimonial_vector'")
        ->and($this->settingsService)
        ->toContain("'testimonials_section_title'")
        ->toContain("'testimonials_section_description'")
        ->toContain("'testimonial_author_img_1'")
        ->toContain("'testimonial_author_img_5'")
        ->toContain("'testimonial_vector'");
});

it('retains the paired Swiper selectors, controls and existing shared initialization', function () {
    expect($this->testimonials)
        ->toContain('swiper home4-testimonial-slider')
        ->toContain('swiper home4-testimonial-img-slider')
        ->toContain('testimonial-slider-prev')
        ->toContain('testimonial-slider-next')
        ->toContain('type="button"')
        ->toContain('aria-label="Previous testimonial"')
        ->toContain('aria-label="Next testimonial"')
        ->and($this->customScript)
        ->toContain('new Swiper(".home4-testimonial-img-slider"')
        ->toContain('new Swiper(".home4-testimonial-slider"')
        ->toContain('nextEl: ".testimonial-slider-next"')
        ->toContain('prevEl: ".testimonial-slider-prev"')
        ->toContain('thumbs:')
        ->toContain('swiper: swiper3');
});

it('provides an accessible dominant quote and compact author navigator scoped to Theme 03', function () {
    expect($this->testimonials)
        ->toContain('aria-labelledby="t3-testimonials-title"')
        ->toContain('out of 5 stars')
        ->toContain('<blockquote>')
        ->toContain('aria-label="Testimonial authors"')
        ->toContain('width="160"')
        ->toContain('height="160"')
        ->toContain('loading="lazy"')
        ->and($this->styles)
        ->toContain('body.theme-theme-03 .t3-testimonials')
        ->toContain('body.theme-theme-03 .t3-testimonials__heading')
        ->toContain('body.theme-theme-03 .t3-testimonials__stage')
        ->toContain('body.theme-theme-03 .t3-testimonials__quote.testimonial-card.five')
        ->toContain('body.theme-theme-03 .t3-testimonials__controls.slider-btn-grp')
        ->toContain('body.theme-theme-03 .t3-testimonials__navigator')
        ->toContain('body.theme-theme-03 .t3-testimonials__authors')
        ->toContain('@media (max-width: 767px)');
});

it('does not reactivate the pre-existing dormant testimonial query', function () {
    expect($this->homeController)
        ->toContain('// Query 2: Get testimonials')
        ->toContain('$testimonials = collect();')
        ->and(substr_count($this->homeController, '$testimonials = collect();'))->toBe(1);
});
