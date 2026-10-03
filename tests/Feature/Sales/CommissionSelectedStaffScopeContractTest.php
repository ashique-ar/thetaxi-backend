<?php

it('scopes legacy commission self and team reads to the selected Staff company', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/CollectionCommissionController.php'));

    expect($controller)
        ->toContain('app(StaffAccessService::class)->currentActorStaff($request->user())')
        ->toContain("where('company_id', \$staff->company_id)")
        ->not->toContain("Staff::query()->where('user_id', \$request->user()->id)->firstOrFail()");
});

it('keeps legacy direct commission payouts disabled until Finance approval controls are used', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/CollectionCommissionController.php'));
    $markPaid = substr($controller, strpos($controller, 'public function markPaid'), strpos($controller, 'private function newEngineIndex') - strpos($controller, 'public function markPaid'));

    expect($markPaid)
        ->toContain('abort(409,')
        ->not->toContain('CollectionCommissionPayout::create')
        ->and($controller)->toContain("'direct_payment_available' => false");
});
