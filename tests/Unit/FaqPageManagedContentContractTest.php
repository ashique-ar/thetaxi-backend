<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->routes = file_get_contents($projectRoot . '/routes/web.php');
    $this->controller = file_get_contents($projectRoot . '/app/Http/Controllers/FAQController.php');
    $this->view = file_get_contents($projectRoot . '/resources/views/faq.blade.php');
    $this->accordion = file_get_contents($projectRoot . '/resources/views/partials/faq-accordion.blade.php');
    $this->theme04Pages = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/pages.css');
});

it('keeps the public FAQ URI owned by its controller and established route name', function () {
    expect($this->routes)
        ->toContain("Route::get('/faq', [FAQController::class, 'index'])->name('faq')")
        ->toContain("Route::get('/faq/category/{category}', [FAQController::class, 'category'])->name('faq.category')")
        ->not->toContain("name('faq.index')")
        ->not->toContain("Route::get('/faq', function");

    expect($this->view)->toContain("action=\"{{ route('faq') }}\"");
});

it('renders FAQ questions and answers from the managed FAQ records', function () {
    expect($this->view)
        ->toContain("@include('partials.faq-accordion'")
        ->toContain('$featuredFaqs->isNotEmpty()')
        ->toContain('$faqs->isNotEmpty()')
        ->toContain('$faqs->links()')
        ->toContain("\$settings['faq_no_results_text']")
        ->not->toContain('Visa & Documentation')
        ->not->toContain('What Services Does Your Travel Agency Provide?')
        ->not->toContain('Do You Provide Visa Assistance?');

    expect($this->accordion)
        ->toContain('@foreach ($faqItems as $faq)')
        ->toContain('{{ $faq->question }}')
        ->toContain('{!! $faq->answer !!}')
        ->toContain('data-bs-toggle="collapse"')
        ->toContain('data-bs-parent="#{{ $accordionId }}"');
});

it('honors the established FAQ page display settings', function () {
    expect($this->controller)
        ->toContain("\$settings['faq_show_featured']")
        ->toContain("\$settings['faq_featured_show_count']")
        ->toContain("\$settings['faq_items_per_page']")
        ->toContain('withQueryString()')
        ->and($this->view)
        ->toContain("\$settings['faq_page_title']")
        ->toContain("\$settings['faq_show_search']")
        ->toContain("\$settings['faq_show_categories']")
        ->toContain("\$settings['faq_search_placeholder']")
        ->toContain("\$settings['faq_auto_expand']")
        ->toContain("\$settings['faq_page_banner_image']");
});

it('keeps FAQ page presentation isolated within the Theme 04 route shell', function () {
    expect($this->theme04Pages)
        ->toContain('body.theme-theme-04.theme-page-faq .faq-page')
        ->toContain('body.theme-theme-04.theme-page-faq .faq-search-wrap')
        ->toContain('body.theme-theme-04.theme-page-faq .faq-wrap')
        ->not->toContain('body.theme-theme-03');
});
