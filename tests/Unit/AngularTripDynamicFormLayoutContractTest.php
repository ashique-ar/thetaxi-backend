<?php

it('keeps Angular Add Trip responsive layout aligned with the Laravel dynamic form contract', function (): void {
    $backendRoot = dirname(__DIR__, 2);
    $portalRoot = dirname($backendRoot) . '/portal-thetaxi/src/app/modules/booking/components/booking-flow';
    $component = file_get_contents($portalRoot . '/trip-form-editor.component.ts');
    $template = file_get_contents($portalRoot . '/trip-form-editor.component.html');
    $styles = file_get_contents($portalRoot . '/trip-form-editor.component.scss');
    $laravelStyles = file_get_contents($backendRoot . '/public/assets/css/booking-form.css');

    expect($component)
        ->toContain("['full', 'half', 'third', 'auto']")
        ->toContain('config.tablet_width')
        ->toContain('config.mobile_width')
        ->toContain('getRowNumber')
        ->and($template)
        ->toContain('[attr.data-form-row]="getRowNumber(row)"')
        ->toContain('[attr.data-field-row]="field.config.row || 1"')
        ->and($styles)
        ->toContain('grid-template-columns: repeat(12, minmax(0, 1fr))')
        ->toContain('.field-width-auto')
        ->toContain('.field-tablet-auto { grid-column: span 6; }')
        ->toContain('.field-mobile-auto { grid-column: span 12; }')
        ->toContain('@container trip-editor')
        ->and($laravelStyles)
        ->toContain('.dynamic-field-width-full')
        ->toContain('.dynamic-field-tablet-auto')
        ->toContain('.dynamic-field-mobile-auto');
});
