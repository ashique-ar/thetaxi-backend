<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->theme03 = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/faq.blade.php');
    $this->theme04 = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-04/faq.blade.php');
    $this->theme03Styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->theme04Styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/theme-04.css');
});

it('keeps the existing FAQ visibility condition and released theme branch', function () {
    expect($this->home)
        ->toContain('@if ($faqs->count() > 0)')
        ->toContain("@include('partials.themes.theme-03.faq')")
        ->toContain("@include('partials.themes.theme-04.faq')")
        ->toContain('<div class="home4-faq-section mb-100">');
});

it('preserves the same managed FAQ content and Bootstrap collapse behavior in both themes', function () {
    foreach ([$this->theme03, $this->theme04] as $partial) {
        expect($partial)
            ->toContain("\$settings['faq_section_title']")
            ->toContain('@foreach ($faqs as $index => $faq)')
            ->toContain('{{ $faq->question }}')
            ->toContain('{!! $faq->answer !!}')
            ->toContain('id="accordionFlushExample"')
            ->toContain('id="flush-heading{{ \\Str::camel($faq->id) }}"')
            ->toContain('data-bs-toggle="collapse"')
            ->toContain('data-bs-target="#flush-collapse{{ \\Str::camel($faq->id) }}"')
            ->toContain("{{ \$index === 0 ? 'show' : '' }}")
            ->toContain('data-bs-parent="#accordionFlushExample"')
            ->toContain("\$settings['faq_section_vector']")
            ->not->toContain('<script');
    }
});

it('isolates each FAQ presentation to its own theme body', function () {
    expect($this->theme03Styles)
        ->toContain('body.theme-theme-03 .t3-faq')
        ->not->toContain('body.theme-theme-04')
        ->and($this->theme04Styles)
        ->toContain('body.theme-theme-04 .t4-faq')
        ->not->toContain('body.theme-theme-03');
});
