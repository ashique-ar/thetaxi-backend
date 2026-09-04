<?php

it('keeps held decisions immutable and appends one rollback-protected release', function () {
    $model = file_get_contents(app_path('Models/Sales/SalesCommissionHoldRelease.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_139000_create_sales_commission_hold_releases.php'));

    expect($model)->toContain('Commission hold releases are immutable.')
        ->toContain('Commission hold releases cannot be deleted.')
        ->and($migration)->toContain("foreignUuid('commission_decision_id')->unique()")
        ->toContain("string('idempotency_key', 160)->unique()")
        ->toContain("char('request_payload_checksum', 64)")
        ->toContain('Refusing to drop immutable commission hold release evidence while rows exist.');
});

it('replays only formula holds from original frozen receipt-time evidence and fails closed', function () {
    // The tier/effective-date replay lookup lives in CommissionFormulaReplayService,
    // called by CommissionHoldService with the decision's frozen plan_family_id.
    $service = file_get_contents(app_path('Services/Sales/CommissionHoldService.php'));
    $formulaReplay = file_get_contents(app_path('Services/Sales/CommissionFormulaReplayService.php'));
    $remediation = file_get_contents(app_path('Services/Sales/CommissionHoldRemediationService.php'));

    expect($service)->toContain('CommissionHoldRemediationService::isFormulaReplayable')
        ->toContain('requires governed attribution, identity, eligibility, finality, or FX correction')
        ->toContain('$decision->plan_family_id')
        ->and($formulaReplay)
        ->toContain("where('effective_from', '<=', \$effectiveAt)")
        ->toContain("where('plan_family_id', \$planFamilyId)")
        ->toContain("\$tiers->count() !== 1")
        ->and($service)
        ->toContain('frozen_calculation_snapshot')
        ->toContain('calculation_checksum')
        ->toContain('lockForUpdate()')
        ->toContain('A beneficiary cannot release their own commission hold.')
        ->and($remediation)->toContain('FORMULA_REPLAYABLE_HOLDS')
        ->toContain("'linked_adjustment_required'")
        ->toContain("'automatic_finality_release'")
        ->toContain("'external_employment_review_required'");
});

it('returns permission-aware canonical remediation without exposing unsafe historical mutation', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionHoldController.php'));
    $remediation = file_get_contents(app_path('Services/Sales/CommissionHoldRemediationService.php'));

    expect($controller)->toContain("'category' => ['nullable', Rule::in(\$this->remediation->categories())]")
        ->toContain("whereIn('hold_code', \$this->remediation->codesForCategory(\$category))")
        ->toContain("whereNotIn('hold_code', \$this->remediation->knownCodes())")
        ->toContain("setAttribute('remediation'")
        ->and($remediation)->toContain("'historical_decision_immutable' => true")
        ->toContain("'action_path' => \$authorized ? \$actionPath : null")
        ->toContain('userHasAnyForInternalContext')
        ->toContain('Existing reporting-FX correction commands cannot invent the missing original snapshot')
        ->toContain('Sales cannot reactivate Staff or rewrite the historical decision');
});

it('uses central internal self-team-all scope and a separate release permission', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionHoldController.php'));
    $routes = file_get_contents(base_path('routes/api.php'));
    $permissions = file_get_contents(database_path('seeders/AllPermissionsSeeder.php'));

    expect($controller)->toContain('private readonly SalesAccessScope $scope')
        ->toContain("'sales.commission-decisions.view-all'")
        ->toContain("'sales.commission-decisions.view-team'")
        ->toContain("whereIn('beneficiary_sales_profile_id', \$ids)")
        ->and($routes)->toContain("Route::prefix('sales')->middleware(['ensure.internal', 'sales.feature:sales_profiles'])")
        ->toContain("commission-earnings/{earning}/release")
        ->toContain("permission:sales.commission_holds.release")
        ->and($permissions)->toContain("'sales.commission_holds.release'");
});

it('projects a released hold once into metrics and statements without rewriting the source hold', function () {
    $metrics = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $statements = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));

    expect($metrics)->toContain('projectCommissionHoldRelease')
        ->toContain("'source_type' => 'commission_hold_release'")
        ->toContain("'source_event' => 'released'")
        ->and($statements)->toContain("whereColumn('line.source_id', 'sales_commission_hold_releases.id')")
        ->toContain("'commission_hold_release'")
        ->toContain("'Released commission hold: '");
});

it('appends a maker-checker late-attribution adjustment without changing the original hold', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionHoldAdjustmentService.php'));
    $formula = file_get_contents(app_path('Services/Sales/CommissionFormulaReplayService.php'));
    $model = file_get_contents(app_path('Models/Sales/SalesCommissionHoldAdjustment.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_142000_create_sales_commission_hold_adjustments.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($service)->toContain("'attribution_missing', 'acquisition_profile_missing', 'acquisition_profile_ineligible'")
        ->toContain("\$attribution->version !== 1")
        ->toContain("where('event_type', 'confirmed')")
        ->toContain("['collection', 'commission']")
        ->toContain("where('status', 'approved')")
        ->toContain('complete governed source-currency and frozen FX snapshot')
        ->toContain("\$preview['source']['prohibited_approver_ids']")
        ->toContain('The beneficiary cannot approve their own linked commission adjustment.')
        ->toContain("SalesCommissionPlanVersion::query()->whereKey(\$calculation['plan_version_id'])->lockForUpdate()")
        ->toContain("SalesCommissionStaffOverride::query()->whereKey(\$calculation['staff_override_id'])->lockForUpdate()")
        ->toContain("hash_equals(\$lockedPreview['calculation_checksum'], \$previewChecksum)")
        ->and($formula)->toContain('full_eligible_receipt_lkr')
        ->toContain("\$tiers->count() !== 1")
        ->and($model)->toContain('Commission hold adjustments are immutable.')
        ->and($migration)->toContain("foreignUuid('commission_decision_id')->unique()")
        ->toContain('sales_commission_hold_adjustment_checker')
        ->toContain('Refusing to drop linked commission hold adjustment evidence while rows exist.')
        ->and($routes)->toContain('commission-earnings/{earning}/adjustment-preview')
        ->toContain('commission-earnings/{earning}/adjustment')
        ->toContain('permission:sales.commission-recoveries.decide');
});

it('projects the late-attribution adjustment once into occurrence-period metrics and statements', function () {
    $metrics = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $statements = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));

    expect($metrics)->toContain('projectCommissionHoldAdjustment')
        ->toContain("'source_type' => 'commission_hold_adjustment'")
        ->toContain("'source_event' => \$adjustment->adjustment_kind.'_approved'")
        ->and($statements)->toContain("whereColumn('line.source_id', 'sales_commission_hold_adjustments.id')")
        ->toContain("'commission_hold_adjustment'")
        ->toContain('Approved late-attribution commission adjustment');
});

it('appends a typed acquisition-owner correction only for missing ineligible or acquisition-side entity mismatch evidence', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionHoldAdjustmentService.php'));
    $remediation = file_get_contents(app_path('Services/Sales/CommissionHoldRemediationService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_146000_add_late_finality_hold_adjustments.php'));
    $metrics = file_get_contents(app_path('Services/Sales/SalesMetricFactService.php'));
    $statements = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));

    expect($service)->toContain('previewLateAcquisitionOwner')
        ->toContain("'acquisition_profile_missing'")
        ->toContain("'acquisition_profile_ineligible'")
        ->toContain("'legal_entity_mismatch'")
        ->toContain("'acquisition_owner_corrected'")
        ->toContain("'collection_sales_profile_id'")
        ->toContain('collectionProfileEvidenceAt')
        ->toContain('This legal-entity mismatch belongs to the receipt beneficiary')
        ->toContain('Exactly one immutable acquisition-owner correction must replace the original missing, ineligible, or wrong-entity Profile reference.')
        ->toContain("['collection', 'commission']")
        ->toContain("'adjustment_kind' => 'late_acquisition_owner_entitlement'")
        ->toContain("'beneficiary_attribution_event_id' => \$beneficiaryEvent->id")
        ->toContain("\$correction->actor_user_id, \$beneficiaryEvent->actor_user_id")
        ->and($remediation)->toContain("['attribution_missing', 'acquisition_profile_missing']")
        ->toContain("\$code === 'acquisition_profile_ineligible'")
        ->toContain("\$code === 'legal_entity_mismatch'")
        ->toContain('A beneficiary mismatch remains blocked for its own correction workflow.')
        ->toContain('A current Profile edit cannot rewrite original eligibility.')
        ->and($migration)->toContain("foreignUuid('beneficiary_attribution_event_id')")
        ->toContain("original_hold_code IN ('acquisition_profile_missing', 'acquisition_profile_ineligible', 'legal_entity_mismatch')")
        ->toContain('late acquisition/finality commission entitlement evidence')
        ->and($metrics)->toContain("'beneficiary_attribution_event_id' => \$adjustment->beneficiary_attribution_event_id")
        ->and($statements)->toContain('Approved corrected acquisition-owner commission entitlement');
});

it('appends a typed late-finality entitlement only from approved original-time policy and confirmed ledger evidence', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionHoldAdjustmentService.php'));
    $remediation = file_get_contents(app_path('Services/Sales/CommissionHoldRemediationService.php'));
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $holds = file_get_contents(app_path('Services/Sales/CommissionHoldService.php'));
    $eventModel = file_get_contents(app_path('Models/Booking/BookingPaymentReceiptFinalityEvent.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_146000_add_late_finality_hold_adjustments.php'));
    $statements = file_get_contents(app_path('Services/Sales/CommissionStatementService.php'));

    expect($service)->toContain('previewLateFinalityPolicy')
        ->toContain("'finality_policy_missing'")
        ->toContain("'finality_policy_invalid'")
        ->toContain("? 'pending_clearance' : 'policy_missing'")
        ->toContain("where('from_status', \$originalFinalityStatus)->where('to_status', 'confirmed')")
        ->toContain("where('finality_policy_id', \$policy->id)")
        ->toContain('same frozen policy that caused the invalid-payout hold')
        ->toContain('Exactly one approved payment-finality policy must cover the original receipt timestamp.')
        ->toContain('The finality policy lacks maker-checker approval evidence.')
        ->toContain("'prohibited_approver_ids'")
        ->toContain("'adjustment_kind' => 'late_finality_policy_entitlement'")
        ->toContain("BookingPaymentReceiptFinalityEvent::query()->whereKey(\$snapshot['receipt_finality_event_id'])")
        ->and($remediation)->toContain("['finality_policy_missing', 'finality_policy_invalid']")
        ->toContain('missing-policy and legacy invalid-policy holds become adjustment-previewable')
        ->and($migration)->toContain('commission_hold_adjustment_finality_event_idx')
        ->toContain("Schema::table('booking_payment_receipt_finality_events'")
        ->toContain('receipt_finality_event_policy_idx')
        ->toContain('late acquisition/finality commission entitlement evidence')
        ->and($ledger)->toContain("in_array(\$receipt->finality_status, ['policy_missing', 'pending_clearance'], true)")
        ->toContain("'finality_policy_id' => \$finalityPolicy?->id")
        ->toContain('Exactly one approved payment-finality policy must cover the original receipt timestamp.')
        ->and($holds)->toContain("\$finalityEvent->finality_policy_id === \$decision->finality_policy_id")
        ->and($eventModel)->toContain("'finality_policy_id'")
        ->and($statements)->toContain('Approved late finality-policy commission entitlement');
});

it('creates a named decision for pending finality and freezes the policy without premature payout', function () {
    $decision = file_get_contents(app_path('Services/Sales/CommissionDecisionService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_140000_freeze_commission_finality_policy_snapshot.php'));

    expect($decision)->not->toContain("\$receipt->finality_status !== 'confirmed'")
        ->toContain("'cash_clearance_pending'")
        ->toContain("'finality_policy_missing'")
        ->toContain("'payment_finality_failed'")
        ->toContain("'finality_policy_id' => \$finalityPolicy?->id")
        ->toContain("\$receipt->finality_status === 'confirmed' && \$policy")
        ->toContain("\$facts['commission_amount_lkr'] = null")
        ->and($migration)->toContain("foreignUuid('finality_policy_id')")
        ->toContain("string('rounding_mode_snapshot', 30)")
        ->toContain("unsignedSmallInteger('rounding_scale_snapshot')")
        ->toContain('Refusing to discard frozen commission finality-policy evidence');
});

it('releases a clearance hold only from the immutable confirmed finality event', function () {
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $holds = file_get_contents(app_path('Services/Sales/CommissionHoldService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_139000_create_sales_commission_hold_releases.php'));

    expect($ledger)->toContain("components()->where('is_commission_eligible', true)->each")
        ->toContain('releaseForFinality($decision, $event)')
        ->and($holds)->toContain('public function releaseForFinality')
        ->toContain("\$finalityEvent->to_status !== 'confirmed'")
        ->toContain("\$decision->hold_code !== 'cash_clearance_pending'")
        ->toContain('! $decision->finality_policy_id')
        ->toContain('roundSnapshot')
        ->toContain("'release_kind' => 'finality_confirmation'")
        ->toContain('frozenCalculation($decision)')
        ->and($migration)->toContain("foreignUuid('receipt_finality_event_id')->nullable()")
        ->toContain("index('receipt_finality_event_id', 'commission_hold_release_finality_event_idx')")
        ->toContain("string('release_kind', 40)");
    expect($migration)->not->toContain("foreignUuid('receipt_finality_event_id')->nullable()->unique()");
});

it('closes failed finality as immutable zero-value non-entitlement without financial projection', function () {
    $ledger = file_get_contents(app_path('Services/BookingPaymentLedgerService.php'));
    $holds = file_get_contents(app_path('Services/Sales/CommissionHoldService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/CommissionHoldController.php'));
    $finalityController = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentFinalityController.php'));
    $model = file_get_contents(app_path('Models/Sales/SalesCommissionHoldResolution.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_13_147000_create_sales_commission_hold_resolutions.php'));

    expect($ledger)->toContain("\$toStatus === 'failed'")
        ->toContain('resolveForFailedFinality($decision, $event)')
        ->and($holds)->toContain('public function resolveForFailedFinality')
        ->toContain("'resolution_kind' => 'failed_finality_no_entitlement'")
        ->toContain("'commission_entitlement_lkr' => '0.0000'")
        ->toContain("'creates_metric_fact' => false")
        ->toContain("'creates_statement_line' => false")
        ->toContain("'creates_payout' => false")
        ->toContain('A payable release or entitlement adjustment already exists')
        ->and($controller)->toContain("whereDoesntHave('holdResolution')")
        ->toContain("orWhereHas('holdResolution')")
        ->and($finalityController)->toContain("'commission_terminal_resolution_count'")
        ->toContain("whereNull('adjustment.id')->whereNull('resolution.id')")
        ->and($model)->toContain('Commission hold resolutions are immutable.')
        ->toContain('Commission hold resolutions cannot be deleted.')
        ->and($migration)->toContain("foreignUuid('commission_decision_id')->unique()")
        ->toContain("resolution_kind = 'failed_finality_no_entitlement'")
        ->toContain('Rollback refused: export and reconcile immutable failed-finality commission hold resolutions first.');
    expect($holds)->not->toContain('projectCommissionHoldResolution');
});

it('rejects a pending-clearance policy that could expose commission to payout', function () {
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/PaymentFinalityController.php'));

    expect($controller)->toContain('validatePendingClearancePolicy')
        ->toContain('A pending-clearance policy must hold commission payout until confirmed finality.')
        ->toContain('An approved payment-method finality policy is required before confirmation.')
        ->toContain("'commission_decision_count'")
        ->toContain("'commission_open_hold_count'")
        ->toContain("'commission_finality_release_count'")
        ->toContain("'commission_decision_status'")
        ->toContain("'commission_hold_code'")
        ->toContain("'commission_release_kind'");
});

it('appends a typed beneficiary-correction entitlement only for a missing or wrong-entity collection handler now resolved by a governed correction', function () {
    $service = file_get_contents(app_path('Services/Sales/CommissionHoldAdjustmentService.php'));
    $mutation = file_get_contents(app_path('Services/Sales/BookingAttributionMutationService.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Api/Sales/SalesBookingAttributionController.php'));
    $remediation = file_get_contents(app_path('Services/Sales/CommissionHoldRemediationService.php'));
    $migration = file_get_contents(database_path('migrations/2026_08_24_101000_add_late_beneficiary_correction_hold_adjustments.php'));
    $routes = file_get_contents(base_path('routes/api.php'));

    expect($service)->toContain("'beneficiary_missing', 'finality_policy_missing', 'finality_policy_invalid'")
        ->toContain("previewLateBeneficiaryCorrection")
        ->toContain("\$decision->hold_code === 'beneficiary_missing'")
        ->toContain("&& (\$decision->beneficiary_sales_profile_id || \$decision->beneficiary_staff_id)")
        ->toContain("'collection_handler_corrected'")
        ->toContain("collectionProfileEvidenceAt(\$attribution, \$receipt->received_at)")
        ->toContain("['collection', 'commission']")
        ->toContain("'adjustment_kind' => 'late_beneficiary_correction_entitlement'")
        ->and($mutation)->toContain('public function correctCollectionHandler(')
        ->toContain("'collection_sales_profile_id',")
        ->toContain("'collection_handler_corrected',")
        ->and($controller)->toContain('public function correctCollectionHandler(Request $request, string $attribution)')
        ->toContain('$this->mutations->correctCollectionHandler(')
        ->and($routes)->toContain('attributions/{attribution}/correct-collection-handler')
        ->toContain("permission:sales.attributions.correct")
        ->and($remediation)->toContain("A beneficiary-side legal-entity mismatch becomes adjustment-previewable only after one governed, actor-owned collection-handler correction")
        ->toContain("if (\$code === 'beneficiary_missing')")
        ->and($migration)->toContain("adjustment_kind = 'late_beneficiary_correction_entitlement' AND original_hold_code IN ('beneficiary_missing', 'legal_entity_mismatch') AND beneficiary_attribution_event_id IS NULL AND finality_policy_id IS NOT NULL AND receipt_finality_event_id IS NULL")
        ->toContain('Rollback refused: export and reconcile late beneficiary-correction commission entitlement evidence first.');
});
