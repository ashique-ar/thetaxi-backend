<?php

it('revalidates organization unit staff references inside the locked service transaction', function () {
    $service = file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php'));

    expect($service)
        ->toContain('$this->assertUnitReferences($payload, $companyId);')
        ->toContain('$this->assertUnitReferences($effectivePayload, $companyId, $unitId);')
        ->toContain("whereIn('id',$staffIds)->where('company_id',$companyId)")
        ->toContain("whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get(['id'])")
        ->toContain('Organization manager and HR partner must be active Staff in the same legal entity.');
});

it('requires the locked parent unit to own and cover the child interval', function () {
    $service = file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php'));

    expect($service)
        ->toContain("where('id',$cursor)->where('company_id',$companyId)->lockForUpdate()")
        ->toContain("$parent->status==='active'&&$parent->effective_from<=$from")
        ->toContain('Parent organization unit must be active and cover the child unit interval.');
});
