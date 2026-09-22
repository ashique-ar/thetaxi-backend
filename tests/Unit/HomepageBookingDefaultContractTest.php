<?php

it('lets Website Settings choose the initial booking service in every theme', function () {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root . '/app/Http/Controllers/Website/HomeController.php');
    $home = file_get_contents($root . '/resources/views/home.blade.php');
    $bookingForm = file_get_contents($root . '/resources/views/components/booking-form.blade.php');

    expect($controller)
        ->not->toContain("'service_type' => 'airport_transfers'")
        ->and($home)
        ->toContain("@include('partials.themes.theme-03.booking-form')")
        ->toContain("@include('partials.themes.theme-04.booking-form')")
        ->toContain("@include('components.booking-form')")
        ->and($bookingForm)
        ->toContain("data_get(\$tab->metadata, 'is_default', false)")
        ->toContain("\$getSearchProp('service_type', \$defaultTabCode)");
});
