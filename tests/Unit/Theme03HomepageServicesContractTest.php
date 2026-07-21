<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->services = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/services.blade.php');
    $this->cmsCard = file_get_contents($projectRoot . '/resources/views/components/cms-card.blade.php');
    $this->homeController = file_get_contents($projectRoot . '/app/Http/Controllers/Website/HomeController.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
});

it('uses a Theme 03 services presenter while preserving the released-theme CMS section', function () {
    expect($this->home)
        ->toContain('@if ($inspirations->count() > 0)')
        ->toContain("@include('partials.themes.theme-03.services')")
        ->toContain('<x-cms-section')
        ->toContain(':items="$inspirations" type="services"')
        ->toContain('viewAllText="View All Services"')
        ->toContain('sectionId="services-section"')
        ->toContain(':limit="6" customTemplate="blog-card2"');
});

it('keeps the services section on existing CMS and Website Settings owners', function () {
    expect($this->homeController)
        ->toContain("'inspirations' => collect(\$allCmsContent->get('services', []))->take(3)")
        ->and($this->services)
        ->toContain("\$settings['inspirations_section_title']")
        ->toContain("\$settings['inspirations_section_description']")
        ->toContain('$inspirations->take(6)')
        ->toContain(':item="$item"')
        ->toContain(':itemIndex="$index + 1"')
        ->toContain('type="services"')
        ->toContain(':showPrice="true"')
        ->toContain(':showDuration="false"')
        ->toContain(':showRating="false"')
        ->toContain('template="theme-03-editorial-service"')
        ->toContain("route('cms.index', ['contentType' => 'services'])")
        ->not->toContain('<script')
        ->not->toContain('<form');
});

it('adds a presentation template after the shared CMS record normalization', function () {
    $normalizationPosition = strpos($this->cmsCard, '// Extract data from item');
    $templatePosition = strpos($this->cmsCard, "@if (\$template === 'theme-03-editorial-service')");

    expect($this->cmsCard)
        ->toContain("'template' => null")
        ->toContain('if (is_object($item))')
        ->toContain('// Array fallback')
        ->toContain("route('cms.show', ['contentType' => \$type, 'content' => \$slug])")
        ->toContain("route('cms.index', ['contentType' => \$type])")
        ->and($normalizationPosition)->not->toBeFalse()
        ->and($templatePosition)->not->toBeFalse()
        ->and($templatePosition)->toBeGreaterThan($normalizationPosition);
});

it('retains all service-card content and link states in the editorial template', function () {
    expect($this->cmsCard)
        ->toContain('src="{{ $imageUrl }}"')
        ->toContain('alt="{{ $title }}"')
        ->toContain('@if ($isSpecialOffer && $discount > 0)')
        ->toContain('@if ($location)')
        ->toContain('@if ($category)')
        ->toContain('<h3><a href="{{ $detailLink }}">{{ $title }}</a></h3>')
        ->toContain('<p>{{ $excerpt }}</p>')
        ->toContain('@if ($pickupLocation)')
        ->toContain('@if ($showPrice && $price)')
        ->toContain('{{ $currency }} {{ $price }}')
        ->toContain('@if ($duration)')
        ->toContain('href="{{ $detailLink }}" class="t3-services__detail"');
});

it('provides an alternating responsive composition scoped to Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-services')
        ->toContain('body.theme-theme-03 .t3-services__heading')
        ->toContain('body.theme-theme-03 .t3-services__item:nth-child(even)')
        ->toContain('body.theme-theme-03 .t3-services__item:nth-child(even) .t3-services__media')
        ->toContain('body.theme-theme-03 .t3-services__facts')
        ->toContain('body.theme-theme-03 .t3-services__detail')
        ->toContain('@media (max-width: 767px)');
});
