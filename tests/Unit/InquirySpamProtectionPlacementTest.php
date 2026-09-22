<?php

test('Cloudflare protection renders at the bottom of every inquiry form', function () {
    expect(file_get_contents(dirname(__DIR__, 2).'/resources/views/inquiry/partials/spam-protection.blade.php'))
        ->toContain('class="inquiry-spam-protection"');

    foreach ([
        'inquiry/partials/form.blade.php',
        'contact.blade.php',
        'corporate-transfers.blade.php',
        'components/dynamic-booking-form.blade.php',
    ] as $view) {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/'.$view);
        $protection = strpos($source, 'inquiry.partials.spam-protection');
        $submit = strpos($source, 'type="submit"');

        expect($protection)->toBeLessThan($submit)
            ->and(substr_count(substr($source, $protection, $submit - $protection), "\n"))->toBeLessThan(4);
    }
});
