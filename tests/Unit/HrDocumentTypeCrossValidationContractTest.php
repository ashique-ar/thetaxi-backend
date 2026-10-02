<?php

it('cross-validates an employment-spell-linked document upload against the governed hr_document_types register', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/DocumentController.php'));

    expect($controller)
        ->toContain("DB::table('hr_document_types')->where('company_id', \$owner->company_id)->where('code', \$data['document_type'])->where('status', 'active')->exists(),")
        ->toContain('This document type is not an approved code in the governed HR document-type register.');
});

it('does not cross-validate document_type for uploads that are not linked to a governed employment spell', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/DocumentController.php'));
    $store = substr($controller, strpos($controller, 'public function store('));
    $store = substr($store, 0, strpos($store, 'public function show('));

    expect(substr_count($store, "if (! empty(\$data['employment_spell_id'])) {"))->toBe(1)
        ->and(substr_count($store, "DB::table('hr_document_types')"))->toBe(1);
});

it('wires the employment-spell document upload into the Employee 360 page using the same governed type register already used for organization administration', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-detail/people-detail.component.ts'));
    $template = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/components/people-detail/people-detail.component.html'));
    $documentService = file_get_contents(base_path('../portal-thetaxi/src/app/core/services/document.service.ts'));
    $documentTypes = file_get_contents(base_path('../portal-thetaxi/src/app/core/types/document.types.ts'));

    expect($component)
        ->toContain("import { DocumentService } from '@core/services/document.service';")
        ->toContain("this.documents.uploadDocument({owner_type:'staff',owner_id:this.staffId,employment_spell_id:spellId,");
    expect($template)->toContain("*hasPermission=\"'staff-sensitive-documents.create'\"");
    expect($documentService)->toContain("if (payload.employment_spell_id) {");
    expect($documentTypes)->toContain('employment_spell_id?: string;');
});
