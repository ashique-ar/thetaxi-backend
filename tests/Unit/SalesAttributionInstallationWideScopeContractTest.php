<?php

it('scopes attribution lists and writes by authorized Sales Profiles across the installation', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));

    expect($controller)
        ->toContain('private function actorProfileIds(', '$this->scope->profileIds(', 'private function applyActorScope(', 'private function scopedAttribution(')
        ->not->toContain('resolveCompanyId(', 'activeDefaultCompany', 'company_id', 'assertCompanyScope(');
});
