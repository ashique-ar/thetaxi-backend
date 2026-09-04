<?php

it('governs required-learning definitions scoped to Staff type and organization unit within the actor legal entity', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LearningController.php'));

    expect($controller)
        ->toContain('public function requirements(Request$request):JsonResponse{')
        ->toContain('public function storeRequirement(Request$request):JsonResponse{')
        ->toContain("abort_unless(\$course,422,'The course must belong to your legal entity.');")
        ->toContain("abort_unless(DB::table('hr_organization_units')->where('company_id',\$actor->company_id)->whereIn('id',\$d['applies_to_organization_unit_ids'])->count()===count(\$d['applies_to_organization_unit_ids']),422,");
});

it('reuses the existing hr.learning permissions rather than minting new ones', function () {
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($routes)
        ->toContain("Route::get('requirements',[LearningController::class,'requirements'])->middleware('permission:hr.learning.view');")
        ->toContain("Route::post('requirements',[LearningController::class,'storeRequirement'])->middleware('permission:hr.learning.manage');");
});

it('stores only the requirement definition, not compliance/reminder state, matching the deliberately narrow scope of this slice', function () {
    $migration = file_get_contents(base_path('database/migrations/2026_08_15_103000_create_hr_learning_requirements.php'));
    $schema = substr($migration, strpos($migration, 'public function up()'));

    expect($schema)
        ->toContain("\$table->unsignedSmallInteger('due_days');")
        ->toContain("\$table->unsignedSmallInteger('refresher_interval_days')->nullable();")
        ->not->toContain('reminder')
        ->not->toContain('compliance_status');
});
