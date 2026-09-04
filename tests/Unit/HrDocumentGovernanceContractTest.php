<?php

it('extends the existing generic Document model additively rather than creating a parallel HR document store', function () {
    $model = file_get_contents(app_path('Models/Document.php'));
    $migration = file_get_contents(base_path('database/migrations/2026_08_15_101000_govern_hr_document_types_and_legal_hold.php'));

    expect($model)->toContain("'employment_spell_id',")->toContain("'legal_hold',");
    expect($migration)->toContain("Schema::table('documents', function (Blueprint \$table) {");
});

it('refuses to delete a document under legal hold', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/DocumentController.php'));

    expect($controller)->toContain("abort_if(\$document->legal_hold, Response::HTTP_CONFLICT, 'This document is under legal hold and cannot be deleted.');");
});

it('refuses migration rollback while any document type event or legal hold is retained', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_08_15_101000_govern_hr_document_types_and_legal_hold.php'));

    expect($migration)
        ->toContain("where('aggregate_type', 'document_type')")
        ->toContain("where('legal_hold', true)");
});

it('governs document types as a versioned legal-entity register reusing the shared organization event ledger', function () {
    $service = file_get_contents(app_path('Services/Hr/OrganizationAdministrationService.php'));

    expect($service)
        ->toContain('public function createDocumentType(array $data,string $companyId,string $actorUserId):array')
        ->toContain('public function updateDocumentType(string $typeId,array $data,string $companyId,string $actorUserId):array')
        ->toContain("if(\$replay=\$this->replay(\$data['idempotency_key'],\$checksum,\$companyId,'document_type'))return\$replay;");
});

it('scopes required-document rules to an approved category list without inventing statutory document requirements', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/PeopleCoreController.php'));

    expect($controller)->toContain("Rule::in(['contract', 'appointment_letter', 'policy_acknowledgement', 'certificate', 'identification', 'bank_evidence', 'disciplinary_document', 'exit_document', 'other'])");
});
