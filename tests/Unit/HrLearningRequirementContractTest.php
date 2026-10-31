<?php

it('governs installation-wide required-learning definitions by Staff type and organization unit', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Hr/LearningController.php'));

    expect($controller)
        ->toContain('public function requirements(Request$request):JsonResponse{')
        ->toContain('public function storeRequirement(Request$request):JsonResponse{')
        ->not->toContain('company_id', 'companyId', 'legal entity');
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
