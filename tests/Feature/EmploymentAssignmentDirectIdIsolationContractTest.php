<?php

it('transactionally validates employment type for initial hire and rehire', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain("\$this->assertEmploymentType(\$input['employment_type_id'] ?? null, \$staff->company_id);")
        ->toContain("\$this->assertEmploymentType(\$assignment['employment_type_id']??null,\$case->company_id);")
        ->toContain("where('id',\$employmentTypeId)->where('company_id',\$companyId)")
        ->toContain("where('status','active')->lockForUpdate()->first()");
});

it('binds assignment references to legal entity effective date and subject', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain('$spell->staff_id===$staff->id&&$spell->company_id===$staff->company_id')
        ->toContain('$this->assertAssignmentReferences($staff,$data,$start);')
        ->toContain("where('id',\$data['position_id'])->where('company_id',\$staff->company_id)")
        ->toContain("where('id',\$data['organization_unit_id'])->where('company_id',\$staff->company_id)")
        ->toContain('$position->organization_unit_id!==$data[\'organization_unit_id\']');
});

it('requires every assignment manager to be active same-company and non-self', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain("\$data['dotted_line_manager_staff_id']??null")
        ->toContain("\$data['hr_partner_staff_id']??null")
        ->toContain('in_array($staff->id,$managerIds,true)')
        ->toContain("whereIn('id',\$managerIds)->where('company_id',\$staff->company_id)")
        ->toContain("whereNull('employment_ended_at')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get(['id'])");
});
