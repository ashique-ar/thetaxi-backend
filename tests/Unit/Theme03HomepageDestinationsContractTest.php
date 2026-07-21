<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->destinations = file_get_contents($projectRoot . '/resources/views/partials/themes/theme-03/destinations.blade.php');
    $this->cmsCard = file_get_contents($projectRoot . '/resources/views/components/cms-card.blade.php');
    $this->homeController = file_get_contents($projectRoot . '/app/Http/Controllers/Website/HomeController.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
});

it('selects a Theme 03 destination presenter without replacing released-theme markup', function () {
    expect($this->home)
        ->toContain('@if ($destinations->count() > 0)')
        ->toContain("@include('partials.themes.theme-03.destinations')")
        ->toContain(':items="$destinations" type="taxi"')
        ->toContain(':showPrice="false"')
        ->toContain(':showDuration="false"')
        ->toContain(':showRating="true"')
        ->toContain('viewAllText="View All Destinations"')
        ->toContain('sectionId="destinations-section"')
        ->toContain(':limit="6"');
});

it('keeps the destination collection, settings, type and item limit unchanged', function () {
    expect($this->homeController)
        ->toContain("'destinations' => collect(\$allCmsContent->get('taxi', []))->take(6)")
        ->and($this->destinations)
        ->toContain("\$settings['destinations_section_title']")
        ->toContain("\$settings['destinations_section_description']")
        ->toContain('$destinations->take(6)')
        ->toContain(':item="$item"')
        ->toContain(':itemIndex="$index + 1"')
        ->toContain('type="taxi"')
        ->toContain(':showPrice="false"')
        ->toContain(':showDuration="false"')
        ->toContain(':showRating="true"')
        ->toContain('template="theme-03-destination-index"')
        ->toContain("route('cms.index', ['contentType' => 'taxi'])")
        ->not->toContain('<script')
        ->not->toContain('<form');
});

it('consumes normalized CMS fields and retains every visible destination state', function () {
    $normalizationPosition = strpos($this->cmsCard, '// Extract data from item');
    $templatePosition = strpos($this->cmsCard, "@elseif (\$template === 'theme-03-destination-index')");

    expect($this->cmsCard)
        ->toContain('src="{{ $imageUrl }}"')
        ->toContain('alt="{{ $title }}"')
        ->toContain('@if ($isSpecialOffer && $discount > 0)')
        ->toContain('@if ($location)')
        ->toContain('@if ($category)')
        ->toContain('@if ($showRating && $rating > 0)')
        ->toContain("aria-label=\"{{ \$rating }} out of 5 stars\"")
        ->toContain('@if ($reviewsCount > 0)')
        ->toContain('<h3><a href="{{ $detailLink }}">{{ $title }}</a></h3>')
        ->toContain('<p>{{ $excerpt }}</p>')
        ->toContain('@if ($pickupLocation)')
        ->toContain('@if ($minDays)')
        ->toContain('href="{{ $detailLink }}" class="t3-destinations__detail"')
        ->and($normalizationPosition)->not->toBeFalse()
        ->and($templatePosition)->not->toBeFalse()
        ->and($templatePosition)->toBeGreaterThan($normalizationPosition);
});

it('keeps detail and category URLs on the shared CMS routes', function () {
    expect($this->cmsCard)
        ->toContain("route('cms.show', ['contentType' => \$type, 'content' => \$slug])")
        ->toContain("route('cms.index', ['contentType' => \$type]) . '?category=' . urlencode(\$category)")
        ->and($this->destinations)
        ->not->toContain("route('booking.search')")
        ->not->toContain('url(');
});

it('provides a numbered panoramic responsive index scoped to Theme 03', function () {
    expect($this->styles)
        ->toContain('body.theme-theme-03 .t3-destinations')
        ->toContain('body.theme-theme-03 .t3-destinations__item:nth-child(even)')
        ->toContain('body.theme-theme-03 .t3-destinations__image::after')
        ->toContain('body.theme-theme-03 .t3-destinations__index')
        ->toContain('body.theme-theme-03 .t3-destinations__rating i.is-filled')
        ->toContain('body.theme-theme-03 .t3-destinations__facts')
        ->toContain('body.theme-theme-03 .t3-destinations__detail')
        ->toContain('@media (max-width: 767px)');
});

