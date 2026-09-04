<?php

it('provides a permission-aware preview reconciliation commit and export workspace', function () {
    $component=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-directory/people-directory.component.ts'));
    $template=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-directory/people-directory.component.html'));
    $service=file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/hr-people.service.ts'));
    expect($component)->toContain("hasPermission('hr.people.manage')")
        ->toContain("hasPermission('hr.people.view-all')")
        ->toContain('previewImport(file,crypto.randomUUID())')
        ->toContain('commitImport(preview.job.id,preview.job.file_checksum)')
        ->and($template)->toContain('Ambiguous rows are never merged automatically.')
        ->toContain('Correct every rejected source row')
        ->toContain('preview.job.rejected_count > 0')
        ->and($service)->toContain("makePostCallImageUpload('/hr/people/imports/preview'")
        ->toContain('makeGetCallBlob(`/hr/people/exports/${id}/download`)');
});
