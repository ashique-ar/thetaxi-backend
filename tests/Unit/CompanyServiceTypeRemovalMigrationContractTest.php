<?php

it('makes company-owned service types installation-wide and archives collisions without dropping their rows', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_30_000071_make_company_owned_service_types_installation_wide.php'));

    expect($migration)
        ->toContain('$this->renameRetiredGlobalCollisionsWithActiveCompanyServices();')
        ->toContain("->whereNull('deleted_at')->where('is_active', true)", "->whereNotNull('deleted_at')->orWhere('is_active', false)")
        ->toContain("where('owner_type', 'company')")
        ->toContain("'owner_type' => ''")
        ->toContain("'owner_id' => ''")
        ->toContain("\$values['is_active'] = false")
        ->toContain("where('parent_service_type_id', \$service->id)")
        ->toContain("'corporate_transport_routes', 'corporate_service_distance_policies', 'inquiry_service_pages'")
        ->toContain("'service_packages', 'terms_and_conditions', 'vehicle_pricing_common_rate_definitions'")
        ->toContain("'vehicle_addons', 'vehicle_pricing_slab_definitions', 'vehicle_pricing_slabs'")
        ->toContain('moveVehicleGroupServiceSettings($sourceId, $canonicalId)')
        ->toContain("'is_inquiry_only' => \$existing->is_inquiry_only || \$setting->is_inquiry_only")
        ->toContain("'is_hidden' => \$existing->is_hidden || \$setting->is_hidden")
        ->toContain('makeScopedNamesUnique($tableName, $sourceId, $canonicalId, [\'name\', \'code\'])')
        ->toContain("'service_type_id' => \$canonicalId")
        ->toContain('moveCorporateAssignments($service->id, $canonicalId)')
        ->toContain('mergeServiceTypeConfiguration($service, $canonicalId)')
        ->toContain("['form_config', 'workflow_config']")
        ->toContain("if (\$key !== 'options')")
        ->toContain('uniqueCode($service, $codes)')
        ->toContain('$this->retireDuplicate($service, $codes[$key], $codes, $slugs);')
        ->toContain("\$values['code'] = \$legacyCode")
        ->not->toContain('Company-owned service types collide when made installation-wide', '->delete()');
});

it('makes service types context-wide and removes the portal owner filter', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/ServiceTypeController.php'));
    $createRequest = file_get_contents(app_path('Http/Requests/ServiceType/CreateServiceTypeRequest.php'));
    $updateRequest = file_get_contents(app_path('Http/Requests/ServiceType/UpdateServiceTypeRequest.php'));
    $portalList = file_get_contents(base_path('../portal-thetaxi/src/app/modules/services-management/service-types/list/service-types-table.component.ts'));
    $portalForm = file_get_contents(base_path('../portal-thetaxi/src/app/modules/services-management/service-types/form/service-type-form.component.ts'));
    $portalService = file_get_contents(base_path('../portal-thetaxi/src/app/modules/services-management/service-types/service-types.service.ts'));

    expect($controller)
        ->not->toContain('owner_type', 'owner_id', 'resolveScope')
        ->and($createRequest)->not->toContain('owner_type', 'owner_id')
        ->and($updateRequest)->not->toContain('owner_type', 'owner_id')
        ->and($portalList)->not->toContain('currentOwnerType', "'owner_type'")
        ->and($portalForm)->not->toContain('owner_type', 'owner_id', 'applyOwnershipRules')
        ->and($portalService)->not->toContain('owner_type', 'owner_id', 'resolveScopeParams');
});

it('keeps booking service-type lookups context-wide and filters corporate services by corporate assignment', function () {
    $routes = file_get_contents(base_path('routes/api.php'));
    $bookingService = file_get_contents(base_path('../portal-thetaxi/src/app/modules/booking/services/booking.service.ts'));

    expect($routes)
        ->toContain("->forContext(\$context)")
        ->toContain("whereIn('id', \$corporate->serviceTypes()->select('service_types.id'))")
        ->not->toContain("input('owner_type'")
        ->not->toContain("input('owner_id'")
        ->and($bookingService)->not->toContain("queryParams['owner_type']", "queryParams['owner_id']");
});

it('drops service-type tenant owner columns after reconciling installation-wide keys', function () {
    $migration = file_get_contents(database_path('migrations/2026_10_31_000031_remove_service_type_company_owner_scope.php'));
    $model = file_get_contents(app_path('Models/Service/ServiceType.php'));
    $clone = file_get_contents(app_path('Services/ServiceTypeCloneService.php'));

    expect($migration)
        ->toContain('if ($hasOwnerType || $hasOwnerId)')
        ->not->toContain("DB::table('service_types')->update(['owner_type' => '', 'owner_id' => ''])")
        ->toContain("\$table->dropColumn('owner_id')")
        ->toContain("\$table->dropColumn('owner_type')")
        ->toContain("'service_types_code_context_unique' => ['code', 'context']")
        ->toContain("'service_types_slug_context_unique' => ['slug', 'context']")
        ->and($model)->not->toContain("'owner_type'", "'owner_id'")
        ->and($clone)->not->toContain('owner_type', 'owner_id');
});
