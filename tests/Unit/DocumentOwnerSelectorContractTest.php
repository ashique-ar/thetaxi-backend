<?php

it('keeps document owner selection authorized bounded readable and retry safe', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/DocumentController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $migration = file_get_contents(database_path('migrations/2026_09_07_090000_add_upload_idempotency_to_documents.php'));
    $dialog = file_get_contents(base_path('../portal-thetaxi/src/app/modules/customer/components/documents/document-upload-dialog.component.html'));
    $dialogController = file_get_contents(base_path('../portal-thetaxi/src/app/modules/customer/components/documents/document-upload-dialog.component.ts'));
    $list = file_get_contents(base_path('../portal-thetaxi/src/app/modules/customer/components/documents/document-management.component.html'));

    expect($routes)->toContain("Route::get('documents/owner-options'")
        ->and($controller)->toContain("'record_type' => ['required'")
        ->and($controller)->toContain("'per_page' => ['nullable', 'integer', 'min:1', 'max:50']")
        ->and($controller)->toContain('$this->staffAccess->scope($query, $request->user())')
        ->and($controller)->toContain("'value' => (string) \$owner->getKey()")
        ->and($controller)->toContain("'label' => \$this->ownerLabel(\$owner)")
        ->and($controller)->toContain('The upload retry key was already used for a different request.')
        ->and($controller)->toContain('$owner::query()->lockForUpdate()->findOrFail')
        ->and($controller)->toContain('$this->audit($request, $document, \'document_uploaded\')')
        ->and($controller)->toContain('Storage::disk($disk)->delete($path)')
        ->and($migration)->toContain("unique('upload_idempotency_key'")
        ->and($dialog)->toContain('app-ui-managed-record-select')
        ->and($dialog)->toContain('endpoint="/documents/owner-options"')
        ->and($dialog)->not->toContain('Paste the owner UUID')
        ->and($dialogController)->toContain('private readonly uploadIdempotencyKey = crypto.randomUUID()')
        ->and($list)->not->toContain('doc.owner_label || doc.owner_id');
});
