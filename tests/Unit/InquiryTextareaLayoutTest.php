<?php

test('dynamic inquiry fields have persistent labels and full-width textareas', function () {
    $form = file_get_contents(dirname(__DIR__, 2).'/resources/views/inquiry/partials/form.blade.php');
    $page = file_get_contents(dirname(__DIR__, 2).'/resources/views/inquiry/service-page.blade.php');

    expect($form)
        ->toContain("\$wrapperClasses .= ' inquiry-textarea-wrap'")
        ->toContain('class="inquiry-field-label"')
        ->toContain("!empty(\$field->icon) && !\$isTextarea && !\$isTelephone")
        ->toContain("\$isTextarea ? ' inquiry-control--textarea' : ''")
        ->and($page)
        ->toContain('.inquiry-form-card .inquiry-control--textarea > textarea')
        ->toContain('display: block !important;')
        ->toContain('width: 100% !important;');
});
