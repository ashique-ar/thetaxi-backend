<?php

test('theme four home services grid follows shared responsive columns', function () {
    $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/themes/theme-04/theme-04.css');

    expect($css)
        ->not->toContain('body.theme-theme-04 .t4-services__grid {')
        ->toContain('body.theme-theme-04 :where(.t4-services__grid, .t4-destinations__grid, .t4-activities__grid, .t4-stories__grid) {')
        ->toContain('grid-template-columns: repeat(2, minmax(0, 1fr));')
        ->toContain('grid-template-columns: 1fr;');
});
