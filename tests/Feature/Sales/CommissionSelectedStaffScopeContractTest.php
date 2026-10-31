<?php

it('scopes legacy commission self and team reads by Staff and reporting assignments', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/CollectionCommissionController.php'));

    expect($controller)
        ->toContain('app(StaffAccessService::class)->currentActorStaff($request->user())')
        ->toContain("where('beneficiary_staff_id', \$staff->id)")
        ->toContain("whereIn('beneficiary_staff_id', array_values(array_unique(\$ids)))")
        ->toContain('active Sales Profile exists for this Staff member')
        ->not->toContain('company_id', 'CompanyIdScope', 'lockActiveCompany')
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
