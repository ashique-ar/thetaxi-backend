<?php

it('adds list endpoints for asset types, requests, phone subscriptions, and phone usage, closing create-but-no-read gaps', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/AssetOperationsController.php'));

    expect($controller)->toContain('public function types(Request$r):JsonResponse')
        ->toContain('public function requests(Request$r):JsonResponse')
        ->toContain('public function phoneSubscriptions(Request$r):JsonResponse')
        ->toContain('public function phoneUsage(Request$r):JsonResponse')
        ->toContain("'i.asset_type_id'")
        ->toContain("if(!\$r->user()->can('hr.assets.approve')&&!\$r->user()->can('hr.assets.view-all'))\$q->where('q.staff_id',\$a->id);")
        ->toContain("if(!\$r->user()->can('hr.assets.phone.approve')&&!\$r->user()->can('hr.assets.view-all'))\$q->where('u.staff_id',\$a->id);");
});

it('registers the new list routes under the existing hr.assets.view permission, minting no new permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)->toContain("Route::get('types',[AssetOperationsController::class,'types'])->middleware('permission:hr.assets.view');")
        ->toContain("Route::get('requests',[AssetOperationsController::class,'requests'])->middleware('permission:hr.assets.view');")
        ->toContain("Route::get('phone-subscriptions',[AssetOperationsController::class,'phoneSubscriptions'])->middleware('permission:hr.assets.view');")
        ->toContain("Route::get('phone-usage',[AssetOperationsController::class,'phoneUsage'])->middleware('permission:hr.assets.view');");
});

it('wires the previously-missing asset request/decide/issue/return/exception and phone subscription/usage UI onto existing endpoints', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/assets-travel/assets-travel.component.ts'));
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/hr-talent.service.ts'));

    expect($service)->toContain("requestAsset(payload:any){return this.makePostCall('/hr/assets/requests',payload)}")
        ->toContain("decideAssetRequest(id:string,payload:any){return this.makePostCall(`/hr/assets/requests/\${id}/decide`,payload)}")
        ->toContain("issueAsset(id:string,payload:any){return this.makePostCall(`/hr/assets/requests/\${id}/issue`,payload)}")
        ->toContain("acknowledgeCustody(id:string){return this.makePostCall(`/hr/assets/custody/\${id}/acknowledge`,{})}")
        ->toContain("returnCustody(id:string,payload:any){return this.makePostCall(`/hr/assets/custody/\${id}/return`,payload)}")
        ->toContain("decideReturnException(id:string,payload:any){return this.makePostCall(`/hr/assets/custody/\${id}/exception-decision`,payload)}")
        ->toContain("storePhoneSubscription(payload:any){return this.makePostCall('/hr/assets/phone-subscriptions',payload)}")
        ->toContain("storePhoneUsage(payload:any){return this.makePostCall('/hr/assets/phone-usage',payload)}")
        ->toContain("decidePhoneUsage(id:string,payload:any){return this.makePostCall(`/hr/assets/phone-usage/\${id}/decide`,payload)}")
        ->and($component)->toContain('canDecideAssetRequest(row: any) { return this.canDecideAsset() && row.requested_by !== this.myUserId && row.status === \'pending_approval\'; }')
        ->toContain('canIssueAssetRequest(row: any) { return this.canIssueAsset() && row.status === \'approved\' && !!row.allocated_asset_item_id; }')
        ->toContain('canReturnCustodyRow(row: any) { return this.canReturnAsset() && row.status === \'assigned\'; }')
        ->toContain('canDecideException(row: any) { return this.canDecideAsset() && row.status === \'exception_pending\'; }')
        ->toContain('canDecidePhoneUsage(row: any) { return this.canApprovePhone() && [\'pending_review\', \'disputed\'].includes(row.status); }');
});
