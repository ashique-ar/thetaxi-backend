<?php

it('revalidates active Staff references inside the organization write transaction', function () {
    $service = file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php'));

    expect($service)
        ->toContain('$this->assertUnitReferences($payload);')
        ->toContain('$this->assertUnitReferences($effectivePayload, $unitId);')
        ->toContain("whereIn('id',\$staffIds)")
        ->toContain("whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get(['id'])")
        ->toContain('Organization manager and HR partner must be active Staff.')
        ->not->toContain('company_id');
});

it('requires locked parent units to cover the child interval without company scope', function () {
    $service = file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php'));

    expect($service)
        ->toContain("where('id',\$cursor)->lockForUpdate()")
        ->toContain("\$parent->status==='active'&&\$parent->effective_from<=\$from")
        ->toContain('Parent organization unit must be active and cover the child unit interval.')
        ->not->toContain('company_id');
});
