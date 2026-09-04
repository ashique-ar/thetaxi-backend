<?php

it('registers explicit team roster permissions without granting team management to the sales manager baseline', function () {
    $seeder = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));
    preg_match("/'sales-manager'\s*=>\s*\[(.*?)\n\s*\],/s", $seeder, $matches);

    expect($seeder)
        ->toContain('sales.profiles.view-team')
        ->toContain('sales.profiles.manage-team')
        ->and($matches[1] ?? '')
        ->toContain("'sales.profiles.view-team'")
        ->not->toContain("'sales.profiles.manage-team'");
});

it('scopes reporting edges and direct mutations through the internal Sales access evaluator under stable locks', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesReportingAssignmentController.php'));
    $scope = file_get_contents(app_path('Services/Sales/SalesAccessScope.php'));

    expect($controller)
        ->toContain('profileIds(')
        ->toContain("'sales.profiles.view-team'")
        ->toContain("whereIn('manager_sales_profile_id', \$profileIds)")
        ->toContain("whereIn('member_sales_profile_id', \$profileIds)")
        ->toContain('assertProfile(')
        ->toContain("'sales.profiles.manage-team'")
        ->toContain("'manager' => \$this->profileReference")
        ->toContain("'can_manage' => \$manageableIds === null")
        ->toContain("DB::table('companies')")
        ->toContain('lockForUpdate()')
        ->and($scope)
        ->toContain('PermissionEvaluator')
        ->toContain('userHasAnyForInternalContext')
        ->toContain('authorizedProfileIds')
        ->toContain("whereNull('deleted_at')");
});

it('serves searchable paged Profile options from the same manage scope used by mutations', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));

    expect($controller)
        ->toContain("'search' => ['nullable', 'string', 'max:100']")
        ->toContain("'manageable' => ['nullable', 'boolean']")
        ->toContain("\$manageableOnly ? 'sales.profiles.manage-all' : 'sales.profiles.view-all'")
        ->toContain("\$payload['can_manage'] = \$canManage;");
});

it('resolves eligibility and reporting currency from the versioned profile state effective at the business event time', function () {
    $eligibility = file_get_contents(app_path('Services/Sales/SalesProfileEligibilityService.php'));
    $attribution = file_get_contents(app_path('Services/Sales/BookingAttributionService.php'));
    $mutation = file_get_contents(app_path('Services/Sales/BookingAttributionMutationService.php'));

    expect($eligibility)
        ->toContain("DB::table('sales_profile_events')")
        ->toContain("where('occurred_at', '<=', \$at)")
        ->toContain("first(['after_configuration'])")
        ->toContain("preg_match('/^[A-Z]{3}$/'")
        ->toContain('{$eligibility}_eligible')
        ->and($attribution)
        ->toContain("forStaffAt(\$staff, 'acquisition', \$securedAt)")
        ->toContain("['collection']")
        ->toContain('collection_handler_ineligible')
        ->and($mutation)
        ->toContain("? 'acquisition' : 'collection'")
        ->toContain('governed reporting currency at the effective time')
        ->toContain('projectionStatus(');
});

it('holds commission when frozen acquisition, collection, or beneficiary eligibility is absent at its historical event time', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionDecisionService.php'));

    expect($service)
        ->toContain("['acquisition'], \$attribution->secured_at")
        ->toContain("['collection'], \$receipt->received_at")
        ->toContain("['commission'], \$receipt->received_at")
        ->toContain('acquisition_profile_ineligible')
        ->toContain('collection_handler_ineligible')
        ->toContain('commission_beneficiary_ineligible')
        ->toContain('legal_entity_mismatch');
});

it('requires reasoned optimistic profile configuration changes with versioned before and after evidence', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));

    expect($controller)
        ->toContain("'expected_version' => ['required'")
        ->toContain('CONCURRENCY_CONFLICT')
        ->toContain("'profile_version' => \$profile->version")
        ->toContain("'before_configuration'")
        ->toContain("'after_configuration'")
        ->toContain("'reason' => ['required'");
});

it('keeps all employees in Staff while Sales remains an explicitly configured Staff category and Profile', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));
    $eligibility = file_get_contents(app_path('Services/Sales/SalesProfileEligibilityService.php'));
    $staffController = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));
    $policySettings = file_get_contents(app_path('Services/Sales/SalesPolicySettingsService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_144000_freeze_sales_staff_category.php'));

    expect($controller)->toContain('Approved Sales Staff categories are not configured.')
        ->toContain('Only Staff in an approved Sales category may be explicitly enrolled as a Sales Profile.')
        ->toContain('Reconcile the linked Staff category through Profile configuration before reactivation.')
        ->toContain("'staff_category_snapshot' => trim((string) \$staff->staff_type)")
        ->toContain("'sales_staff_category_ready_by_company' => \$categoriesByCompany->map(fn (array \$rows) => \$rows !== [])")
        ->and($eligibility)->toContain("\$configuration['staff_category_snapshot']")
        ->and($staffController)->toContain('End the current/future Sales Profile before changing this Staff category.')
        ->and($policySettings)->toContain('approvedStaffCategories')
        ->toContain('SalesStaffCategoryDefinition::query()')
        ->and($migration)->toContain("string('staff_category_snapshot', 100)")
        ->toContain('Rollback refused: export and reconcile Sales Profile Staff-category snapshots first.');
});

it('derives internal authority from an active Staff identity and excludes roles owned only by external contexts', function () {
    $evaluator = file_get_contents(app_path('Services/PermissionEvaluator.php'));
    $internal = file_get_contents(app_path('Http/Middleware/EnsureInternalContext.php'));
    $permissionMiddleware = file_get_contents(app_path('Http/Middleware/PermissionMiddleware.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesProfileController.php'));

    expect($evaluator)
        ->toContain('hasActiveStaffIdentity')
        ->toContain("where('context_type', 'staff')")
        ->toContain("where('context_type', '!=', 'staff')")
        ->toContain("\$externalContexts = \$user->contexts()")
        ->toContain('! $externalRoleIds->contains($role->id)')
        ->and($internal)
        ->toContain("resolved_active_context_type', 'internal'")
        ->and($permissionMiddleware)
        ->toContain("attributes->get('resolved_active_context_type'")
        ->and($controller)
        ->toContain("'can_manage_profiles' => \$this->scope->hasPermission")
        ->toContain("'can_export_profiles' => \$this->scope->hasPermission");
});

it('records an idempotent versioned Profile event when closing a leaver portfolio', function () {
    $mutation = file_get_contents(app_path('Services/Sales/BookingAttributionMutationService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_122000_extend_sales_profile_configuration_and_export_scope.php'));

    expect($mutation)
        ->toContain(':profile-closed')
        ->toContain("'event_type' => 'portfolio_closed'")
        ->toContain("\$after['status'] = 'ended'")
        ->toContain("'profile_version' => \$profile->version")
        ->toContain("'idempotency_key' => \$eventKey")
        ->and($migration)
        ->toContain('sales_profile_events_idempotency_unique');
});

it('applies the internal current transitive Sales scope to attribution queries, dry runs, and mutation targets', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));

    expect($controller)
        ->toContain('private readonly SalesAccessScope $scope')
        ->toContain('actorProfileIds(')
        ->toContain('scopedAttribution(')
        ->toContain("'sales.attributions.view-team'")
        ->toContain("'Attribution was not found in the current scope.'")
        ->toContain("->filter(function (array \$row) use (\$profileIds, \$data): bool")
        ->not->toContain("\$request->user()->can('sales.attributions");
});

it('serves minimal booking attribution rows and non-enumerating scoped mutation choices', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($controller)
        ->toContain("'booking:id,booking_number,payment_status,payment_collection_status'")
        ->toContain('public function administrationContext')
        ->toContain("'can_correct' => \$this->scope->hasPermission")
        ->toContain('private function scopedProfile')
        ->toContain("abort_unless(\$profile, 404")
        ->not->toContain("'to_sales_profile_id' => ['required', 'uuid', 'exists:sales_profiles,id']")
        ->and($routes)
        ->toContain("Route::get('attribution-administration-context'");
});

it('retains resolved exception history while allowing only one open exception per booking and type', function () {
    $mutation = file_get_contents(app_path('Services/Sales/BookingAttributionMutationService.php'));
    $projection = file_get_contents(app_path('Services/Sales/SalesEffectiveProjectionService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_124000_preserve_sales_attribution_exception_history.php'));

    expect($mutation)
        ->toContain('private function resolveExceptions')
        ->toContain("'status' => 'resolved'")
        ->toContain("'resolved_by' => \$actorUserId")
        ->and($projection)
        ->toContain("'resolved_by' => \$event->actor_user_id")
        ->and($migration)
        ->toContain("WHERE status = 'open'")
        ->toContain('Cannot restore the legacy attribution-exception uniqueness constraint without discarding history.');
});

it('projects future-dated handler transfers and Profile closures when their effective time becomes due', function () {
    $service = file_get_contents(app_path('Services/Sales/SalesEffectiveProjectionService.php'));
    $command = file_get_contents(app_path('Console/Commands/ProjectEffectiveSalesChanges.php'));
    $schedule = file_get_contents(base_path('routes/console.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_123000_add_sales_effective_projection_state.php'));

    expect($service)
        ->toContain("'collection_sales_profile_id' => \$eligibleTarget?->id")
        ->toContain("DB::table('booking_collection_work_items')")
        ->toContain("'assigned_sales_profile_id' => \$eligibleTarget?->id")
        ->toContain("'collection_handler_unassigned'")
        ->toContain("'status' => 'ended'")
        ->and($command)
        ->toContain("'sales:project-effective-changes'")
        ->and($schedule)
        ->toContain("Schedule::command('sales:project-effective-changes')")
        ->and($migration)
        ->toContain("'projected_at'");
});

it('preserves historical Sales eligibility after a later legal-entity move while blocking moves with open Profiles', function () {
    $eligibility = file_get_contents(app_path('Services/Sales/SalesProfileEligibilityService.php'));
    $staffController = file_get_contents(app_path('Http/Controllers/Api/StaffController.php'));

    expect($eligibility)
        ->not->toContain("\$staff->company_id !== \$profile->company_id")
        ->not->toContain("where('company_id', \$staff->company_id)")
        ->and($staffController)
        ->toContain("'End or transfer every current/future Sales Profile before changing the Staff legal entity.'")
        ->toContain("SalesProfile::query()")
        ->toContain('lockForUpdate()');
});
