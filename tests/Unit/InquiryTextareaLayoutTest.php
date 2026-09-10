<?php

test('dynamic inquiry textareas use the dedicated full-width field layout', function () {
    $form = file_get_contents(dirname(__DIR__, 2).'/resources/views/inquiry/partials/form.blade.php');
    $page = file_get_contents(dirname(__DIR__, 2).'/resources/views/inquiry/service-page.blade.php');

    expect($form)
        ->toContain("\$wrapperClasses .= ' inquiry-textarea-wrap'")
        ->toContain("\$isTextarea ? ' inquiry-textarea-box' : ''")
        ->and($page)
        ->toContain('.inquiry-form-card .inquiry-textarea-box > textarea')
        ->toContain('grid-template-columns: 18px minmax(0, 1fr) !important;')
        ->toContain('width: 100% !important;');
});
