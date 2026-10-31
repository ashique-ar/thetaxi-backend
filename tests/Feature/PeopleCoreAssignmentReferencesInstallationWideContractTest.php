<?php

it('validates active employment types across the installation for hire and rehire', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain("\$this->assertEmploymentType(\$input['employment_type_id'] ?? null);")
        ->toContain("\$this->assertEmploymentType(\$assignment['employment_type_id']??null);")
        ->toContain("where('id',\$employmentTypeId)")
        ->toContain("where('status','active')->lockForUpdate()->first()")
        ->not->toContain('company_id');
});

it('validates effective assignment references and Staff identity without company scope', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain('$spell->staff_id===$staff->id')
        ->toContain('$this->assertAssignmentReferences($staff,$data,$start);')
        ->toContain("where('id',\$data['position_id'])")
        ->toContain("where('id',\$data['organization_unit_id'])")
        ->toContain("where('effective_from','<=',\$effectiveAt)")
        ->toContain('$position->organization_unit_id!==$data[\'organization_unit_id\']')
        ->not->toContain('company_id');
});

it('requires assignment managers and HR partners to be active non-self Staff', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain("\$data['dotted_line_manager_staff_id']??null")
        ->toContain("\$data['hr_partner_staff_id']??null")
        ->toContain('in_array($staff->id,$managerIds,true)')
        ->toContain("whereIn('id',\$managerIds)")
        ->toContain("whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get(['id'])")
        ->not->toContain('company_id');
});
