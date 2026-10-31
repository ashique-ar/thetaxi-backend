<?php

it('lists collection work and submissions installation-wide with permission-scoped profiles', function (): void {
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

    expect($controller)
        ->not->toContain('collectionCompanyOptions', 'company_id', 'resolveCompanyId')
        ->and($work)
        ->toContain('$this->scheduleProfileIds($request)')
        ->toContain("whereIn('assigned_sales_profile_id', \$profileIds)")
        ->not->toContain('company_id', 'assertSelectedCollectionCompany')
        ->and($submissions)
        ->toContain('$this->scheduleProfileIds($request)')
        ->toContain("whereIn('submitted_by_sales_profile_id', \$profileIds)")
        ->not->toContain('company_id', 'assertSelectedCollectionCompany')
        ->and($controller)
        ->toContain('(string) $schedule->booking_id === (string) $row->booking_id')
        ->and($routes)
        ->not->toContain('collection-work-company-options')
        ->and($template)
        ->not->toContain('collection-work-company-options', 'companyId', 'Select an authorized active company')
        ->and($component)
        ->toContain('this.api.collectionWorkItems({ status: this.workStatus')
        ->toContain('this.api.collectionSubmissions({ status: this.submissionStatus')
        ->not->toContain('company_id', 'companyId');
});
