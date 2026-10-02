<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesPolicySetting;
use App\Models\Sales\SalesCompanyFeatureSetting;
use App\Models\Sales\SalesStaffCategoryDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Governed, database-backed replacement for the business-policy values that used to live as static
 * config/sales.php entries (FX correction policy, commission-dispute response window, profile-export
 * retention, business timezone) plus the approved Sales staff-category register. Any authorized Staff
 * member holding the relevant permission creates a draft; a different authorized approver approves it.
 * No value is invented — an unconfigured policy_kind simply has no approved row, and every accessor
 * below returns null/empty exactly as the equivalent config() call used to when unset.
 */
class SalesPolicySettingsService
{
    public const KINDS = ['fx_corrections', 'commission_dispute', 'profile_export_retention', 'business_timezone'];
    // Expose a tenant toggle only after every runtime path for it is company-aware.
    public const FEATURES = ['fx_corrections', 'rolling_payment_schedules', 'enforce_payment_finality', 'statements', 'payouts', 'performance_snapshots', 'performance_alert_evaluations', 'performance_alert_actions', 'crm', 'commission_notifications'];

    public function featureHistory(string $companyId): array
    {
        return SalesCompanyFeatureSetting::query()->where('company_id', $companyId)
            ->orderBy('feature_key')->orderByDesc('version')->get()->all();
    }

    public function featureEnabled(string $companyId, string $feature): bool
    {
        if (!in_array($feature, self::FEATURES, true) || config("sales.features.{$feature}", false) !== true
            || ! Schema::hasTable('sales_company_feature_settings')) {
            return false;
        }

        return (bool) SalesCompanyFeatureSetting::query()->where('company_id', $companyId)
            ->where('feature_key', $feature)->where('status', 'approved')
            ->orderByDesc('version')->value('enabled');
    }

    public function featureAvailability(): array
    {
        return collect(self::FEATURES)->mapWithKeys(fn(string $feature) => [
            $feature => config("sales.features.{$feature}", false) === true,
        ])->all();
    }

    public function createFeature(string $companyId, string $feature, bool $enabled, string $reason, string $actorUserId): SalesCompanyFeatureSetting
    {
        if (!in_array($feature, self::FEATURES, true)) {
            throw ValidationException::withMessages(['feature_key' => ['Unknown Sales feature.']]);
        }

        return DB::transaction(function () use ($companyId, $feature, $enabled, $reason, $actorUserId) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->firstOrFail();
            $version = (int) SalesCompanyFeatureSetting::query()->where('company_id', $companyId)
                ->where('feature_key', $feature)->lockForUpdate()->max('version') + 1;

            return SalesCompanyFeatureSetting::create([
                'company_id' => $companyId,
                'feature_key' => $feature,
                'version' => $version,
                'enabled' => $enabled,
                'status' => 'draft',
                'reason' => trim($reason),
                'created_by' => $actorUserId,
            ]);
        });
    }

    public function approveFeature(string $id, string $actorUserId): SalesCompanyFeatureSetting
    {
        return DB::transaction(function () use ($id, $actorUserId) {
            $setting = SalesCompanyFeatureSetting::query()->lockForUpdate()->findOrFail($id);
            abort_unless($setting->status === 'draft', 422, 'Only a draft company feature setting can be approved.');
            abort_if($setting->created_by === $actorUserId, 409, 'The feature-setting creator cannot approve the same version.');
            abort_if(
                $setting->enabled && config("sales.features.{$setting->feature_key}", false) !== true,
                409,
                'This capability is not available in the current deployment and cannot be enabled for a company.'
            );
            SalesCompanyFeatureSetting::query()->where('company_id', $setting->company_id)
                ->where('feature_key', $setting->feature_key)->where('status', 'approved')->lockForUpdate()
                ->update(['status' => 'retired']);
            $setting->update(['status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => now()]);

            return $setting->fresh();
        });
    }

    public function current(string $companyId, string $kind): ?SalesPolicySetting
    {
        return SalesPolicySetting::query()->where('company_id', $companyId)->where('policy_kind', $kind)->where('status', 'approved')
            ->orderByDesc('version')->first();
    }

    public function history(string $companyId, string $kind): array
    {
        return SalesPolicySetting::query()->where('company_id', $companyId)->where('policy_kind', $kind)
            ->orderByDesc('version')->get()->all();
    }

    public function fxCorrectionsPolicy(string $companyId): array
    {
        $policy = $this->current($companyId, 'fx_corrections');

        return [
            'approved_quote_base' => $policy?->fx_quote_base,
            'calculation_mode' => $policy?->fx_calculation_mode,
            'max_rate_age_hours' => $policy?->fx_max_rate_age_hours,
            'rounding_scale' => $policy?->fx_rounding_scale,
        ];
    }

    public function disputeResponseDays(string $companyId): ?int
    {
        return $this->current($companyId, 'commission_dispute')?->dispute_response_days;
    }

    public function profileExportRetentionDays(string $companyId): ?int
    {
        return $this->current($companyId, 'profile_export_retention')?->profile_export_retention_days;
    }

    public function businessTimezone(string $companyId): ?string
    {
        return $this->current($companyId, 'business_timezone')?->business_timezone;
    }

    /** @return array<int, string> */
    public function approvedStaffCategories(string $companyId): array
    {
        return SalesStaffCategoryDefinition::query()->where('company_id', $companyId)->where('status', 'approved')
            ->orderBy('category_name')->pluck('category_name')->all();
    }

    public function create(string $companyId, string $kind, array $data, string $actorUserId): SalesPolicySetting
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['policy_kind' => ['Unknown governed policy kind.']]);
        }
        $values = $this->kindValues($kind, $data);

        return DB::transaction(function () use ($companyId, $kind, $values, $data, $actorUserId) {
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->firstOrFail();
            $version = (int) SalesPolicySetting::query()->where('company_id', $companyId)->where('policy_kind', $kind)->lockForUpdate()->max('version') + 1;

            return SalesPolicySetting::create($values + [
                'policy_kind' => $kind,
                'company_id' => $companyId,
                'version' => $version,
                'status' => 'draft',
                'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
                'created_by' => $actorUserId,
            ]);
        });
    }

    public function approve(string $id, string $actorUserId): SalesPolicySetting
    {
        return DB::transaction(function () use ($id, $actorUserId) {
            $setting = SalesPolicySetting::query()->lockForUpdate()->findOrFail($id);
            abort_unless($setting->status === 'draft', 422, 'Only a draft governed policy setting can be approved.');
            abort_if($setting->created_by === $actorUserId, 409, 'The policy-setting creator cannot approve the same version.');

            SalesPolicySetting::query()->where('company_id', $setting->company_id)->where('policy_kind', $setting->policy_kind)->where('status', 'approved')
                ->lockForUpdate()->update(['status' => 'retired']);
            $setting->update(['status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => now()]);

            return $setting->fresh();
        });
    }

    private function kindValues(string $kind, array $data): array
    {
        return match ($kind) {
            'fx_corrections' => [
                'fx_quote_base' => trim((string) $data['fx_quote_base']),
                'fx_calculation_mode' => $data['fx_calculation_mode'],
                'fx_max_rate_age_hours' => (int) $data['fx_max_rate_age_hours'],
                'fx_rounding_scale' => (int) $data['fx_rounding_scale'],
            ],
            'commission_dispute' => ['dispute_response_days' => (int) $data['dispute_response_days']],
            'profile_export_retention' => ['profile_export_retention_days' => (int) $data['profile_export_retention_days']],
            'business_timezone' => ['business_timezone' => (string) $data['business_timezone']],
        };
    }

    public function createStaffCategory(string $companyId, string $name, ?string $reason, string $actorUserId): SalesStaffCategoryDefinition
    {
        $normalized = trim($name);
        if ($normalized === '') {
            throw ValidationException::withMessages(['category_name' => ['A category name is required.']]);
        }
        if (SalesStaffCategoryDefinition::query()->where('company_id', $companyId)->whereRaw('lower(category_name) = ?', [mb_strtolower($normalized)])->exists()) {
            throw ValidationException::withMessages(['category_name' => ['This Sales staff category already exists.']]);
        }

        return SalesStaffCategoryDefinition::create([
            'company_id' => $companyId,
            'category_name' => $normalized,
            'status' => 'draft',
            'reason' => $reason ? trim($reason) : null,
            'created_by' => $actorUserId,
        ]);
    }

    public function approveStaffCategory(string $id, string $actorUserId): SalesStaffCategoryDefinition
    {
        return DB::transaction(function () use ($id, $actorUserId) {
            $category = SalesStaffCategoryDefinition::query()->lockForUpdate()->findOrFail($id);
            abort_unless($category->status === 'draft', 422, 'Only a draft Sales staff category can be approved.');
            abort_if($category->created_by === $actorUserId, 409, 'The category creator cannot approve the same category.');
            $category->update(['status' => 'approved', 'approved_by' => $actorUserId, 'approved_at' => now()]);

            return $category->fresh();
        });
    }

    public function retireStaffCategory(string $id, string $reason, string $actorUserId): SalesStaffCategoryDefinition
    {
        return DB::transaction(function () use ($id, $reason, $actorUserId) {
            $category = SalesStaffCategoryDefinition::query()->lockForUpdate()->findOrFail($id);
            abort_unless($category->status === 'approved', 422, 'Only an approved Sales staff category can be retired.');
            $category->update([
                'status' => 'retired',
                'reason' => trim($reason),
                'retired_by' => $actorUserId,
                'retired_at' => now(),
            ]);

            return $category->fresh();
        });
    }
}
