<?php

beforeEach(function () {
    $projectRoot = dirname(__DIR__, 2);

    $this->home = file_get_contents($projectRoot . '/resources/views/home.blade.php');
    $this->theme04 = file_get_contents($projectRoot . '/public/assets/css/themes/theme-04/theme-04.css');
    $this->sections = [
        'partners' => 't4-partners',
        'featuredVehicles' => 't4-fleet',
        'inspirations' => 't4-services',
        'destinations' => 't4-destinations',
        'packages' => 't4-activities',
        'offer' => 't4-offers',
        'proof' => 't4-proof',
        'testimonials' => 't4-testimonials',
        'blogs' => 't4-stories',
        'faqs' => 't4-faq',
    ];
});

it('covers all hidden and visible combinations and every possible surviving neighbor pair', function () {
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

        foreach (array_slice($visible, 1) as $index => $sectionName) {
            $observedAdjacentPairs[$visible[$index] . '>' . $sectionName] = true;
        }

        if ($visible !== []) {
            expect($this->theme04)->toContain('.' . $this->sections[$visible[0]]);
        }
    }

    expect($combinationCount)->toBe(1024);

    foreach ($sectionNames as $leftIndex => $left) {
        foreach (array_slice($sectionNames, $leftIndex + 1) as $right) {
            expect($observedAdjacentPairs)->toHaveKey($left . '>' . $right);
        }
    }
});

it('keeps every managed homepage section independently conditional', function () {
    expect($this->home)
        ->toContain("isset(\$partners) && \$partners->count() > 0")
        ->toContain("isset(\$featuredVehicles) && count(\$featuredVehicles['data']) > 0")
        ->toContain("\$inspirations->count() > 0")
        ->toContain("\$destinations->count() > 0")
        ->toContain("\$packages->count() > 0")
        ->toContain("\$settings['offer_slider_img_1']")
        ->toContain("\$settings['why_video_image']")
        ->toContain("\$testimonials && \$testimonials->count() > 0")
        ->toContain("\$blogs->count() > 0")
        ->toContain("\$faqs->count() > 0");
});

it('clears the overlapping journey desk for whichever managed section renders first', function () {
    expect($this->theme04)
        ->toContain('body.theme-theme-04.theme-page-home .t4-booking-panel+:where(')
        ->toContain('.t4-partners,')
        ->toContain('.t4-fleet,')
        ->toContain('.t4-services,')
        ->toContain('.t4-destinations,')
        ->toContain('.t4-activities,')
        ->toContain('.t4-offers,')
        ->toContain('.t4-proof,')
        ->toContain('.t4-testimonials,')
        ->toContain('.t4-stories,')
        ->toContain('.t4-faq')
        ->toContain('padding-top: 54px;');
});

it('does not expose Theme 04 while the shared release gate is incomplete', function () {
    $projectRoot = dirname(__DIR__, 2);
    $manifest = file_get_contents($projectRoot . '/config/website_themes.php');

    expect($manifest)->toContain("env('WEBSITE_THEME_04_ENABLED', false)");
});
