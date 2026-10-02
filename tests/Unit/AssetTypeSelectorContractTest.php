<?php

it('uses active company asset type choices in the request form and rechecks writes', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AssetOperationsController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $portal = base_path('../portal-thetaxi/src/app/modules/hr-talent/components/assets-travel');
    $dialog = file_get_contents($portal.'/dialogs/asset-request-dialog.component.ts');
    $workspace = file_get_contents($portal.'/assets-travel.component.ts');

    expect($routes)->toContain("Route::get('type-options', [AssetOperationsController::class, 'typeOptions'])->middleware('permission:hr.assets.view|hr.assets.request');")
        ->and($controller)->toContain("where('company_id', \$a->company_id)->where('status', 'active')")
        ->toContain("where('status','active')->exists(),422,'The selected asset type is no longer active")
        ->and($dialog)->toContain('app-ui-managed-record-select')->toContain('endpoint="/hr/assets/type-options"')
        ->not->toContain('data.assetTypes')
        ->and($workspace)->not->toContain('this.api.assetTypes()')->not->toContain('assetTypes: this.assetTypes()');
});
