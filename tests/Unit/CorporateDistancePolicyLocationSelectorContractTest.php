<?php

it('replaces contractual location IDs inside route-sequence JSON with authorized managed selectors', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Corporate/CorporateDistancePolicyController.php'));
    $editor = file_get_contents(base_path('../portal-thetaxi/src/app/modules/vehicle/components/vehicle-pricing/components/corporate-distance-policy-editor/corporate-distance-policy-editor.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/vehicle/components/vehicle-pricing/components/corporate-distance-policy-editor/corporate-distance-policy-editor.component.html'));

    expect($routes)->toContain("'location-options'")
        ->and($controller)->toContain("permission:corporates.edit|corporates.manage")
        ->and($controller)->toContain("Rule::in(['operator_location', 'corporate_location'])")
        ->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->toContain("where('corporate_id', \$corporate->id)")
        ->and($editor)->toContain('UiManagedRecordSelectComponent')
        ->toContain('anchorPayload(anchor)')
        ->and($template)->toContain('locationEndpoint()')
        ->toContain('locationRecordType(anchor.type.value)')
        ->not->toContain('custom_sequence_json')
        ->not->toContain('location_id');
});
