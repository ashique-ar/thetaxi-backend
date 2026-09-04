<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesCommissionDecision;
use App\Models\User;
use App\Services\PermissionEvaluator;

class CommissionHoldRemediationService
{
    public function __construct(private readonly PermissionEvaluator $permissions) {}

    public const FORMULA_REPLAYABLE_HOLDS = [
        'rate_missing', 'override_ambiguous', 'plan_version_ambiguous',
        'tier_missing_or_ambiguous', 'formula_unsupported',
    ];

    private const CATEGORIES = [
        'formula_configuration' => self::FORMULA_REPLAYABLE_HOLDS,
        'attribution_identity' => [
            'attribution_missing', 'legal_entity_missing', 'acquisition_profile_missing',
            'legal_entity_mismatch', 'beneficiary_missing', 'plan_family_missing',
        ],
        'profile_eligibility' => [
            'acquisition_profile_ineligible', 'collection_handler_ineligible',
            'commission_beneficiary_ineligible', 'employment_inactive',
        ],
        'fx_evidence' => ['fx_snapshot_missing'],
        'payment_finality' => [
            'cash_clearance_pending', 'finality_policy_missing', 'finality_policy_invalid',
            'payment_finality_failed', 'payment_finality_unknown',
        ],
    ];

    public function categories(): array
    {
        return [...array_keys(self::CATEGORIES), 'unclassified'];
    }

    public function codesForCategory(string $category): array
    {
        return self::CATEGORIES[$category] ?? [];
    }

    public function knownCodes(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CATEGORIES))));
    }

    public static function isFormulaReplayable(?string $holdCode): bool
    {
        return in_array($holdCode, self::FORMULA_REPLAYABLE_HOLDS, true);
    }

    public function describe(SalesCommissionDecision $decision, User $actor): array
    {
        $code = (string) $decision->hold_code;

        if ($decision->holdResolution?->resolution_kind === 'failed_finality_no_entitlement') {
            return $this->contract(
                'payment_finality', 'booking_payment_ledger', 'terminal_no_entitlement',
                $actor, 'sales.payment-finality.transition', '/sales/payment-finality',
                'Review failed finality evidence',
                'The canonical failed-finality event closed this immutable hold with zero entitlement. A replacement payment must use a new receipt lineage.',
            );
        }

        if ($decision->holdResolution?->resolution_kind === 'employment_exit_no_entitlement') {
            return $this->contract(
                'profile_eligibility', 'people_core', 'terminal_no_entitlement',
                $actor, null, null, null,
                'The canonical Staff employment end predates this receipt. The immutable decision is closed with zero entitlement and creates no metric, statement line, payout, or recovery.',
            );
        }

        if (self::isFormulaReplayable($code)) {
            return $this->contract(
                'formula_configuration', 'sales_commission_configuration', 'formula_release_preview',
                $actor, 'sales.commission-config.view', '/sales/commission-configuration',
                'Review approved commission configuration',
                'After approved configuration uniquely covers the original receipt timestamp, an authorized checker may use the no-write release preview. The held decision remains immutable.',
                true,
            );
        }

        if ($code === 'legal_entity_mismatch' && ($decision->beneficiary_sales_profile_id || $decision->beneficiary_staff_id)) {
            return $this->contract(
                'attribution_identity', 'sales_attribution', 'linked_adjustment_required',
                $actor, 'sales.attributions.view', '/sales/attribution-operations',
                'Review collection-handler attribution evidence',
                'A beneficiary mismatch remains blocked for its own correction workflow. A beneficiary-side legal-entity mismatch becomes adjustment-previewable only after one governed, actor-owned collection-handler correction resolves this receipt timestamp to a same-entity, collection- and commission-eligible Profile.',
                false,
                true,
            );
        }

        if ($code === 'legal_entity_mismatch') {
            return $this->contract(
                'attribution_identity', 'sales_attribution', 'linked_adjustment_required',
                $actor, 'sales.attributions.view', '/sales/attribution-operations',
                'Review legal-entity attribution evidence',
                'An acquisition-owner mismatch becomes adjustment-previewable only after one governed same-entity owner correction.',
                false,
                true,
            );
        }

        if (in_array($code, [
            'beneficiary_missing', 'collection_handler_ineligible', 'commission_beneficiary_ineligible',
        ], true)) {
            return $this->contract(
                in_array($code, self::CATEGORIES['profile_eligibility'], true)
                    ? 'profile_eligibility' : 'attribution_identity',
                'sales_attribution', 'linked_adjustment_required',
                $actor, 'sales.attributions.view', '/sales/attribution-operations',
                'Review collection-handler attribution evidence',
                'A missing or ineligible collection beneficiary becomes adjustment-previewable only after one governed, actor-owned collection-handler correction now resolves this receipt timestamp to a same-entity, collection- and commission-eligible Profile.',
                false,
                true,
            );
        }

        if ($code === 'legal_entity_missing') {
            return $this->contract(
                'attribution_identity', 'sales_attribution', 'linked_adjustment_required',
                $actor, 'sales.attributions.correct', '/sales/attribution-operations',
                'Establish missing legal entity',
                'Choose the correct acquisition-owner Sales Profile; its own legal entity becomes the frozen attribution entity. This governed command is backdated to the original secured time and requires a subsequent plan-family resolution before the linked commission adjustment becomes previewable.',
                false,
                true,
            );
        }

        if ($code === 'plan_family_missing') {
            return $this->contract(
                'attribution_identity', 'sales_attribution', 'linked_adjustment_required',
                $actor, 'sales.attributions.correct', '/sales/attribution-operations',
                'Resolve missing frozen plan family',
                'Preview the approved assignment precedence at the original secured time, append one audited plan-family correction, then preview the linked commission adjustment. Missing or ambiguous approved assignment evidence remains blocked.',
                false,
                true,
            );
        }

        if (in_array($code, self::CATEGORIES['attribution_identity'], true)) {
            return $this->contract(
                'attribution_identity', 'sales_attribution', 'linked_adjustment_required',
                $actor, 'sales.attributions.view', '/sales/attribution-operations',
                'Review canonical attribution',
                'Correct the canonical attribution for future facts where authorized. Historical commission entitlement requires a separate governed adjustment; this held decision cannot be edited or formula-released.',
                false,
                in_array($code, ['attribution_missing', 'acquisition_profile_missing'], true),
            );
        }

        if ($code === 'employment_inactive') {
            return $this->contract(
                'profile_eligibility', 'people_core', 'external_employment_review_required',
                $actor, null, null, null,
                'People Core must review the employment fact. Sales cannot reactivate Staff or rewrite the historical decision; any valid entitlement requires a governed commission adjustment.',
            );
        }

        if ($code === 'acquisition_profile_ineligible') {
            return $this->contract(
                'profile_eligibility', 'sales_attribution', 'linked_adjustment_required',
                $actor, 'sales.attributions.view', '/sales/attribution-operations',
                'Review governed acquisition-owner correction',
                'A current Profile edit cannot rewrite original eligibility. The adjustment preview becomes available only after one actor-owned acquisition-owner correction selects a Profile eligible at the original secured time.',
                false,
                true,
            );
        }

        if (in_array($code, self::CATEGORIES['profile_eligibility'], true)) {
            return $this->contract(
                'profile_eligibility', 'sales_profile', 'linked_adjustment_required',
                $actor, 'sales.profiles.view', '/sales/profile-administration',
                'Review Sales Profile eligibility',
                'Review effective Profile eligibility and reporting currency. A current configuration change affects permitted effective periods only and never rewrites this held decision.',
            );
        }

        if ($code === 'fx_snapshot_missing') {
            return $this->contract(
                'fx_evidence', 'booking_payment_ledger', 'linked_adjustment_required',
                $actor, 'sales.payment-adjustments.create', '/sales/payment-adjustments',
                'Establish missing FX/LKR snapshot',
                'The canonical receipt component lacks governed historical FX/LKR evidence. The existing reporting-FX correction command cannot invent it — it only corrects a component that already has complete evidence. A dedicated Finance-policy-reproducible establishment command records one immutable snapshot for the current unreversed component balance; the linked commission-adjustment preview becomes available only after it exists and reconciles.',
                false,
                true,
            );
        }

        if ($code === 'cash_clearance_pending') {
            return $this->contract(
                'payment_finality', 'booking_payment_ledger', 'automatic_finality_release',
                $actor, 'sales.payment-finality.transition', '/sales/payment-finality',
                'Record canonical clearance evidence',
                'Accounts records the immutable confirmed finality event. The system then appends the release automatically from frozen policy, formula, and rounding evidence.',
            );
        }

        if (in_array($code, ['finality_policy_missing', 'finality_policy_invalid'], true)) {
            return $this->contract(
                'payment_finality', 'booking_payment_ledger', 'policy_and_adjustment_required',
                $actor, 'sales.payment-finality.manage', '/sales/payment-finality',
                'Review payment-finality policy',
                'Create and approve an effective payment-method policy where required. Historical evidence stays fail-closed; missing-policy and legacy invalid-policy holds become adjustment-previewable only after the applicable original policy and a policy-linked canonical confirmed finality event reconcile.',
                false,
                true,
            );
        }

        if ($code === 'payment_finality_failed') {
            return $this->contract(
                'payment_finality', 'booking_payment_ledger', 'terminal_no_entitlement',
                $actor, 'sales.payment-finality.transition', '/sales/payment-finality',
                'Review failed finality evidence',
                'The canonical failed-finality event creates an immutable zero-value resolution. It never releases commission or creates a metric, statement line, or payout.',
            );
        }

        if ($code === 'payment_finality_unknown') {
            return $this->contract(
                'payment_finality', 'booking_payment_ledger', 'linked_adjustment_required',
                $actor, 'sales.payment-finality.transition', '/sales/payment-finality',
                'Transition to a recognized finality state',
                'An unknown finality state cannot be formula-released. Governed-transition the receipt to a recognized confirmed state under an approved policy, then preview the linked commission adjustment; this decision remains immutable historical evidence.',
                false,
                true,
            );
        }

        return $this->contract(
            'unclassified', 'sales_commission', 'manual_investigation_required',
            $actor, null, null, null,
            'No source-safe automated remediation is registered for this hold code. Keep payout blocked and escalate through the commission hold runbook.',
        );
    }

    private function contract(
        string $category,
        string $canonicalOwner,
        string $resolutionMode,
        User $actor,
        ?string $requiredPermission,
        ?string $actionPath,
        ?string $actionLabel,
        string $guidance,
        bool $releasePreviewApplicable = false,
        bool $linkedAdjustmentPreviewApplicable = false,
    ): array {
        $authorized = $requiredPermission !== null
            && $this->permissions->userHasAnyForInternalContext($actor, [$requiredPermission]);

        return [
            'category' => $category,
            'canonical_owner' => $canonicalOwner,
            'resolution_mode' => $resolutionMode,
            'required_permission' => $requiredPermission,
            'action_authorized' => $authorized,
            'action_path' => $authorized ? $actionPath : null,
            'action_label' => $authorized ? $actionLabel : null,
            'release_preview_applicable' => $releasePreviewApplicable,
            'linked_adjustment_preview_applicable' => $linkedAdjustmentPreviewApplicable,
            'historical_decision_immutable' => true,
            'guidance' => $guidance,
        ];
    }
}
