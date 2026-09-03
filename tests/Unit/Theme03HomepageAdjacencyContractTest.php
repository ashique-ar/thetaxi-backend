<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->styles = file_get_contents($projectRoot . '/public/assets/css/themes/theme-03/theme-03.css');
    $this->sections = [
        'partners' => [
            'condition' => 'isset($partners) && $partners->count() > 0',
            'partial' => 'partner-register',
            'root' => 't3-partner-register',
        ],
        'featuredVehicles' => [
            'condition' => "isset(\$featuredVehicles) && count(\$featuredVehicles['data']) > 0",
            'partial' => 'featured-vehicles',
            'root' => 't3-featured-fleet',
        ],
        'inspirations' => [
            'condition' => '$inspirations->count() > 0',
            'partial' => 'services',
            'root' => 't3-services',
        ],
        'destinations' => [
            'condition' => '$destinations->count() > 0',
            'partial' => 'destinations',
            'root' => 't3-destinations',
        ],
        'packages' => [
            'condition' => '$packages->count() > 0',
            'partial' => 'packages',
            'root' => 't3-itinerary',
        ],
        'offer' => [
            'condition' => "\$settings['offer_slider_img_1']",
            'partial' => 'offer-slider',
            'root' => 't3-campaigns',
        ],
        'proof' => [
            'condition' => "\$settings['why_video_image']",
            'partial' => 'why-choose-us',
            'root' => 't3-proof',
        ],
        'testimonials' => [
            'condition' => '$testimonials && $testimonials->count() > 0',
            'partial' => 'testimonials',
            'root' => 't3-testimonials',
        ],
        'blogs' => [
            'condition' => '$blogs->count() > 0',
            'partial' => 'blog-editorial',
            'root' => 't3-journal',
        ],
        'faqs' => [
            'condition' => '$faqs->count() > 0',
            'partial' => 'faq',
            'root' => 't3-faq',
        ],
    ];
});

it('keeps every optional Theme 03 section behind its existing independent owner condition', function () {
    $lastPosition = -1;

    foreach ($this->sections as $section) {
        $condition = '@if (' . $section['condition'] . ')';
        $partial = "@include('partials.themes.theme-03.{$section['partial']}')";
        $conditionPosition = strpos($this->home, $condition);
        $partialPosition = strpos($this->home, $partial);

        expect($conditionPosition)->not->toBeFalse()
            ->and($partialPosition)->not->toBeFalse()
            ->and($partialPosition)->toBeGreaterThan($conditionPosition)
            ->and($conditionPosition)->toBeGreaterThan($lastPosition);

        $lastPosition = $conditionPosition;
    }
});

it('supports every hidden and visible combination without changing order or coupling neighbors', function () {
    $sectionNames = array_keys($this->sections);
    $combinationCount = 1 << count($sectionNames);
    $observedAdjacentPairs = [];

    for ($mask = 0; $mask < $combinationCount; $mask++) {
        $visible = [];

        foreach ($sectionNames as $index => $sectionName) {
            if (($mask & (1 << $index)) !== 0) {
                $visible[] = $sectionName;
            }
        }

        expect($visible)->toBe(array_values(array_filter(
            $sectionNames,
            fn (string $sectionName): bool => ($mask & (1 << array_search($sectionName, $sectionNames, true))) !== 0,
        )));

        foreach (array_slice($visible, 1) as $index => $sectionName) {
            $observedAdjacentPairs[$visible[$index] . '>' . $sectionName] = true;
        }
    }

    expect($combinationCount)->toBe(1024);

    foreach ($sectionNames as $leftIndex => $left) {
        foreach (array_slice($sectionNames, $leftIndex + 1) as $right) {
            expect($observedAdjacentPairs)->toHaveKey($left . '>' . $right);
        }
    }
});

it('gives every optional section self-owned spacing with no sibling-dependent Theme 03 selectors', function () {
    foreach ($this->sections as $section) {
        $selector = 'body.theme-theme-03 .' . $section['root'];
        $start = strpos($this->styles, $selector . ' {');

        expect($start)->not->toBeFalse();

        $end = strpos($this->styles, '}', $start);
        $declarations = substr($this->styles, $start, $end - $start);

        expect($declarations)->toMatch('/padding(?:-block)?:/');
    }

    expect($this->styles)
        ->not->toMatch('/\.t3-[a-z0-9_-]+\s*\+\s*\.t3-[a-z0-9_-]+/i')
        ->not->toMatch('/\.t3-[a-z0-9_-]+:has\(\s*\+\s*\.t3-[a-z0-9_-]+/i');
});
