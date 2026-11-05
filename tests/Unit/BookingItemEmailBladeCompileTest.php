<?php

it('compiles the booking item email into valid PHP', function () {
    $template = file_get_contents(resource_path('views/components/booking-item-email.blade.php'));
    $compiled = app('blade.compiler')->compileString($template);

    token_get_all($compiled, TOKEN_PARSE);
})->throwsNoExceptions();
