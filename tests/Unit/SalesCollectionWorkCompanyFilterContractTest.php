<?php

it('preselects an authorized default company and scopes collection work and submissions to it', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CollectionScheduleWorkflowController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $portal = dirname(base_path()).'/portal-thetaxi/src/app/modules/sales/components/sales-collections';
    $template = file_get_contents($portal.'/sales-collections.component.html');
    $component = file_get_contents($portal.'/sales-collections.component.ts');
    $workStart = strpos($controller, 'public function workItems(');
    $workEnd = strpos($controller, 'public function submit(', $workStart);
    $work = substr($controller, $workStart, $workEnd - $workStart);
    $submissionStart = strpos($controller, 'public function submissions(');
    $submissionEnd = strpos($controller, 'public function verify(', $submissionStart);
    $submissions = substr($controller, $submissionStart, $submissionEnd - $submissionStart);
    $integrityStart = strpos($controller, 'private function assertCollectionPageCompanyIntegrity(');
    $integrityEnd = strpos($controller, 'private function assertBookingManagementScope(', $integrityStart);
    $pageIntegrity = substr($controller, $integrityStart, $integrityEnd - $integrityStart);

    expect($controller)
        ->toContain('public function collectionCompanyOptions(')
        ->toContain('activeDefaultCompany()?->id')
        ->toContain("'default_company_id' => \$defaultCompanyId")
        ->toContain("->whereNull('deleted_at')->where('is_active', true)")
        ->toContain("->orderByDesc('is_default')")
        ->toContain("'metadata' => array_filter(['city' => \$company->city]) + ['is_default' => (bool) \$company->is_default]")
        ->and($work)
        ->toContain("'company_id' => ['required', 'uuid', 'exists:companies,id']")
        ->toContain("->where('company_id', \$companyId)")
        ->toContain('assertSelectedCollectionCompany($request, $companyId)')
        ->and($submissions)
        ->toContain("'company_id' => ['required', 'uuid', 'exists:companies,id']")
        ->toContain("\$query->where('company_id', \$companyId)")
        ->toContain('assertSelectedCollectionCompany($request, $companyId)')
        ->and($pageIntegrity)
        ->toContain("whereIn('id', \$scheduleIds)")
        ->toContain("(string) \$schedule->booking_id === (string) \$row->booking_id")
        ->toContain("(string) \$schedule->company_id === (string) \$row->company_id")
        ->toContain('references a schedule outside its booking and legal entity.')
        ->and($routes)
        ->toContain("collection-work-company-options', [CollectionScheduleWorkflowController::class, 'collectionCompanyOptions']")
        ->and($template)
        ->toContain('endpoint="/sales/collection-work-company-options"')
        ->toContain('[(ngModel)]="companyId"')
        ->and($component)
        ->toContain('collectionWorkItems({ company_id: companyId,')
        ->toContain('collectionSubmissions({ company_id: companyId,')
        ->toContain('revision !== this.workRevision || companyId !== this.companyId')
        ->toContain('revision !== this.submissionsRevision || companyId !== this.companyId');
});
