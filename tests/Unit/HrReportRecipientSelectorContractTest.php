<?php

it('uses bounded authorized report-recipient search and exact hydration', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/HrReportingController.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-engagement/components/delivery-administration/report-schedule-dialog.component.ts'));

    expect($controller)->toContain("'selected_ids'=>['nullable','array','max:100']", "'per_page'=>['nullable','integer','min:1','max:50']", "where('s.company_id',\$a->company_id)", "permission('hr.reporting.export')", "'value'=>(string)\$u->id", "foreach(\$d['recipient_user_ids']as\$userId)", "foreach(\$recipients as\$userId)")
        ->and($dialog)->toContain('UiManagedRecordMultiSelectComponent', 'report-recipient-options', '[recordType]="selectedReportKind()"', '[maximum]="100"', 'recipient_user_ids.setValue([])')
        ->not->toContain('recipients = signal', 'reportRecipientOptions(');
});
