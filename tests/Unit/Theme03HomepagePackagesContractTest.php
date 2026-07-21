<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->packages = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/packages.blade.php');
    $this->cmsCard = file_get_contents($projectRoot . '/resources/views/components/cms-card.blade.php');
    $this->homeController = file_get_contents($projectRoot . '/app/Http/Controllers/Website/HomeController.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
});

it('selects a Theme 03 package presenter while retaining released-theme markup', function () {
    expect($this->home)
        ->toContain('@if ($packages->count() > 0)')
        ->toContain("@include('partials.themes.theme-03.packages')")
        ->toContain(':items="$packages" type="things-to-do"')
        ->toContain(':showPrice="true"')
        ->toContain(':showDuration="true"')
        ->toContain(':showRating="true"')
        ->toContain('viewAllText="View All Activities"')
        ->toContain('sectionId="things-to-do-section"')
        ->toContain(':limit="6"');
});

it('keeps the package collection, settings, CMS type and limit unchanged', function () {
    expect($this->homeController)
        ->toContain("'packages' => collect(\$allCmsContent->get('things-to-do', []))->take(6)")
        ->and($this->packages)
        ->toContain("\$settings['packages_section_title']")
        ->toContain("\$settings['packages_section_description']")
        ->toContain('$packages->take(6)')
        ->toContain(':item="$item"')
        ->toContain(':itemIndex="$index + 1"')
        ->toContain('type="things-to-do"')
        ->toContain(':showPrice="true"')
        ->toContain(':showDuration="true"')
        ->toContain(':showRating="true"')
        ->toContain('template="theme-03-itinerary"')
        ->toContain("route('cms.index', ['contentType' => 'things-to-do'])")
        ->not->toContain('<script')
        ->not->toContain('<form');
});

it('renders the itinerary only after shared CMS normalization and route creation', function () {
    $normalizationPosition = strpos($this->cmsCard, '// Extract data from item');
    $templatePosition = strpos($this->cmsCard, "@elseif (\$template === 'theme-03-itinerary')");

    expect($this->cmsCard)
        ->toContain("route('cms.show', ['contentType' => \$type, 'content' => \$slug])")
        ->toContain("route('cms.index', ['contentType' => \$type]) . '?category=' . urlencode(\$category)")
        ->and($normalizationPosition)->not->toBeFalse()
        ->and($templatePosition)->not->toBeFalse()
        ->and($templatePosition)->toBeGreaterThan($normalizationPosition);
});

it('retains package media, pricing, duration, rating and optional metadata states', function () {
    expect($this->cmsCard)
        ->toContain('src="{{ $imageUrl }}"')
        ->toContain('alt="{{ $title }}"')
        ->toContain('@if ($isSpecialOffer && $discount > 0)')
        ->toContain('@if ($location)')
        ->toContain('@if ($category)')
        ->toContain('<h3><a href="{{ $detailLink }}">{{ $title }}</a></h3>')
        ->toContain('<p>{{ $excerpt }}</p>')
        ->toContain('@if ($showRating && $rating > 0)')
        ->toContain("aria-label=\"{{ \$rating }} out of 5 stars\"")
        ->toContain('@if ($reviewsCount > 0)')
        ->toContain('@if ($pickupLocation)')
        ->toContain('@if ($minDays)')
        ->toContain('@if ($showPrice && $price)')
        ->toContain('{{ $currency }} {{ $price }}')
        ->toContain('@if ($showDuration && $duration)')
        ->toContain('href="{{ $detailLink }}" aria-label="{{ $title }}"');
});

it('provides a responsive itinerary ledger scoped to Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-itinerary')
        ->toContain('body.theme-theme-03 .t3-itinerary__item')
        ->toContain('body.theme-theme-03 .t3-itinerary__marker')
        ->toContain('body.theme-theme-03 .t3-itinerary__media')
        ->toContain('body.theme-theme-03 .t3-itinerary__rating i.is-filled')
        ->toContain('body.theme-theme-03 .t3-itinerary__action')
        ->toContain('body.theme-theme-03 .t3-itinerary__all')
        ->toContain('@media (max-width: 767px)');
});

