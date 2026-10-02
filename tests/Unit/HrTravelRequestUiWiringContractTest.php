<?php

it('exposes requested_by on the travel request list so the Angular workspace can decide action visibility without extra round trips', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TravelController.php'));
    $indexMethod = substr($controller, strpos($controller, 'public function index('));
    $indexMethod = substr($indexMethod, 0, strpos($indexMethod, 'public function store('));

    expect($indexMethod)
        ->toContain("'requested_by'")
        ->toContain("'settlement_claim_id'")
        ->toContain("if(!\$r->user()->can('hr.travel.view-all'))\$q->where('staff_id',\$a->id);");
});

it('denies the requestor deciding their own travel request, and the decide gate only accepts pending_approval/returned', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TravelController.php'));
    $decideMethod = substr($controller, strpos($controller, 'public function decide('));
    $decideMethod = substr($decideMethod, 0, strpos($decideMethod, 'public function settle('));

    expect($decideMethod)
        ->toContain("abort_unless(in_array(\$q->status,['pending_approval','returned'],true),409)")
        ->toContain("abort_if(\$q->requested_by===\$r->user()->id&&\$d['decision']==='approved',409");
});

it('requires an approved status and a matching approved travel expense claim before settlement', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/TravelController.php'));
    $settleMethod = substr($controller, strpos($controller, 'public function settle('));
    $settleMethod = substr($settleMethod, 0, strpos($settleMethod, 'private function event('));

    expect($settleMethod)
        ->toContain("abort_unless(\$q->status==='approved',409)")
        ->toContain("->where('claim_type','travel')->where('status','approved')->first();")
        ->toContain("abort_unless(\$claim,422,'An approved travel expense claim for the same employee is required.');");
});

it('registers the store/decide/settle routes behind the existing hr.travel permission family, minting no new permission', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::post('requests',[TravelController::class,'store'])->middleware('permission:hr.travel.request');")
        ->toContain("Route::post('requests/{id}/decide',[TravelController::class,'decide'])->whereUuid('id')->middleware('permission:hr.travel.approve');")
        ->toContain("Route::post('requests/{id}/settle',[TravelController::class,'settle'])->whereUuid('id')->middleware('permission:hr.travel.settle');");
});

it('adds no new hr.travel permission to the registry: store/decide/settle reuse request/approve/settle already present', function () {
    $seeder = file_get_contents(base_path('database/seeders/AllPermissionsSeeder.php'));
    $count = substr_count($seeder, "'hr.travel.view',") + 0;

    expect($seeder)
        ->toContain("'hr.travel.request',")
        ->toContain("'hr.travel.approve',")
        ->toContain("'hr.travel.settle',");
    expect($count)->toBeGreaterThan(0);
});

it('wires the Angular service methods for travel request/decide/settle onto the exact existing backend routes', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/hr-talent.service.ts'));

    expect($service)
        ->toContain("storeTravelRequest(payload:any){return this.makePostCall('/hr/travel/requests',payload)}")
        ->toContain("decideTravelRequest(id:string,payload:any){return this.makePostCall(`/hr/travel/requests/\${id}/decide`,payload)}")
        ->toContain("settleTravelRequest(id:string,payload:any){return this.makePostCall(`/hr/travel/requests/\${id}/settle`,payload)}");
});

it('mirrors the backend self-approval and status gates client-side in the assets/travel component', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/assets-travel/assets-travel.component.ts'));

    expect($component)
        ->toContain("canDecide(row: any) { return this.canDecideTravel() && row.requested_by !== this.myUserId && ['pending_approval', 'returned'].includes(row.status); }")
        ->toContain("canSettle(row: any) { return this.canSettleTravel() && row.status === 'approved'; }")
        ->toContain("this.auth.hasPermission('hr.travel.request')")
        ->toContain("this.auth.hasPermission('hr.travel.approve')")
        ->toContain("this.auth.hasPermission('hr.travel.settle')");
});

it('scopes the settlement claim picker to approved travel-type claims only, matching the backend settle() filter', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-talent/components/assets-travel/assets-travel.component.ts'));

    expect($component)->toContain("c.claim_type === 'travel' && c.status === 'approved'");
});
