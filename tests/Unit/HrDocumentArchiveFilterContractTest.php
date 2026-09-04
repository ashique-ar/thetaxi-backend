<?php

it('filters the document listing endpoint by employment spell and a date range without expanding row scope', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/DocumentController.php'));
    $index = substr($controller, strpos($controller, 'public function index('));
    $index = substr($index, 0, strpos($index, 'public function stats('));

    expect($index)
        ->toContain("'employment_spell_id' => ['nullable', 'uuid', 'exists:hr_employment_spells,id'],")
        ->toContain("'date_from' => ['nullable', 'date'],")
        ->toContain("'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],")
        ->toContain("\$query->where('employment_spell_id', \$data['employment_spell_id']);")
        ->toContain("\$query->whereDate('created_at', '>=', \$data['date_from']);")
        ->toContain("\$query->whereDate('created_at', '<=', \$data['date_to']);");

    // The employment_spell_id/date filters only narrow an already-scoped query; the
    // existing staff row-scope block above them (assertOwnerAccess/staffAccess->scope)
    // is untouched, so this cannot widen a caller's authorized document set.
    expect(substr_count($index, 'staffAccess->scope'))->toBe(2);
});

it('exposes the same filters on the Employee 360 document archive panel using the governed spell and document-type registers', function () {
    $component = file_get_contents(base_path('../portal-thetaxi/src/app/modules/hr-people/people-detail.component.ts'));
    $documentTypes = file_get_contents(base_path('../portal-thetaxi/src/app/core/types/document.types.ts'));

    expect($component)
        ->toContain('title="Document archive"')
        ->toContain("*hasPermission=\"'staff-sensitive-documents.view'\"")
        ->toContain('archiveFilterForm=this.fb.group({employment_spell_id:[\'\'],document_type:[\'\'],date_from:[\'\'],date_to:[\'\']});')
        ->toContain('async loadArchive(page:number):Promise<void>')
        ->toContain('this.documents.getDocuments({owner_type:\'staff\',owner_id:this.staffId,employment_spell_id:v.employment_spell_id||undefined,document_type:v.document_type||undefined,date_from:v.date_from||undefined,date_to:v.date_to||undefined,page,per_page:10})');

    expect($documentTypes)
        ->toContain('employment_spell_id?: string;')
        ->toContain('date_from?: string;')
        ->toContain('date_to?: string;');
});
