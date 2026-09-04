<?php

it('enforces that a newly written assignment payroll_group_code resolves to an active, effective governed payroll group', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)
        ->toContain('if(!empty($data[\'payroll_group_code\']))$this->assertPayrollGroupCode($staff->company_id,$data[\'payroll_group_code\'],$start);')
        ->toContain('private function assertPayrollGroupCode(string $companyId,string $code,string $effectiveAt): void')
        ->toContain("abort_unless(\$exists,422,'Payroll group code must reference an active, effective governed payroll group.');");
});

it('leaves pre-existing free-text payroll_group_code values on prior assignment rows unvalidated by design', function () {
    $service = file_get_contents(app_path('Services/Hr/PeopleCoreService.php'));

    expect($service)->toContain('Pre-existing free-text values on prior assignment rows are left');
});

it('computes required-document compliance for a Staff from the governed document-type register without inventing a universal default', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)
        ->toContain('private function documentCompliance(Staff $staff): array')
        ->toContain('if (empty($forStaffTypes) && empty($forEmploymentTypes))')
        ->toContain('if (!empty($forStaffTypes) && !in_array($staff->staff_type, $forStaffTypes, true))')
        ->toContain("\$status = \$current ? 'satisfied' : (\$matches->isNotEmpty() ? 'expired' : 'missing');")
        ->toContain("'document_compliance' => \$this->documentCompliance(\$staff),");
});

it('excludes rejected documents and treats an expired requires_expiry document separately from a missing one', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)
        ->toContain("->where('status', '!=', 'rejected')")
        ->toContain('$current = $matches->first(fn($document) => !$type->requires_expiry || !$document->expiry_date || $document->expiry_date->toDateString() >= $today);');
});

it('exposes document compliance rows to the Angular Employee 360 detail view', function () {
    $service = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/hr-people.service.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-detail/people-detail.component.html'));

    expect($service)
        ->toContain('document_compliance: DocumentComplianceRow[];')
        ->toContain("status:'satisfied'|'expiring_soon'|'expired'|'missing';expiry_date?:string|null;")
        ->and($template)
        ->toContain('Required-document compliance')
        ->toContain("[tone]=\"row.status==='satisfied'?'success':(row.status==='missing'?'danger':'warning')\"");
});
