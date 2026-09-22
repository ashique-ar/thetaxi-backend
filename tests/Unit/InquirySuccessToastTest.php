<?php

test('public form success messages use the global toast', function () {
    foreach ([
        'inquiry/partials/form.blade.php',
        'contact.blade.php',
        'corporate-transfers.blade.php',
        'point-to-point.blade.php',
        'components/booking-form.blade.php',
    ] as $view) {
        expect(file_get_contents(dirname(__DIR__, 2).'/resources/views/'.$view))
            ->not->toContain("session('success')");
    }

    expect(file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php'))
        ->toContain("window.showSuccessNotification(@json(session('success')), 5000)")
        ->toContain("notification.setAttribute('role', 'status')")
        ->toContain("notification.setAttribute('aria-live', 'polite')");
});
