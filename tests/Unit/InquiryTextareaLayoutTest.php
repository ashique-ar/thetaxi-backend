<?php

test('dynamic inquiry fields have persistent labels and full-width textareas', function () {
    $form = file_get_contents(dirname(__DIR__, 2).'/resources/views/inquiry/partials/form.blade.php');
    $styles = file_get_contents(dirname(__DIR__, 2).'/public/assets/css/booking-form.css');

    expect($form)
        ->toContain("\$wrapperClasses .= ' inquiry-textarea-wrap'")
        ->toContain('class="inquiry-field-label"')
        ->toContain("!empty(\$field->icon) && !\$isTextarea && !\$isTelephone")
        ->toContain("\$isTextarea ? ' inquiry-control--textarea' : ''")
        ->and($styles)
        ->toContain('body form[data-inquiry-form] .inquiry-control--textarea > textarea')
        ->toContain('body form[data-inquiry-form] .inquiry-control > .iti input')
        ->toContain('display: block !important;')
        ->toContain('width: 100% !important;');
});
