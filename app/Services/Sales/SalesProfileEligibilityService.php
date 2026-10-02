<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesProfile;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

class SalesProfileEligibilityService
{
    private const ELIGIBILITIES = ['acquisition', 'collection', 'commission'];

    public function forStaffAt(Staff $staff, string $eligibility, CarbonInterface $at): ?SalesProfile
    {
        $matches = SalesProfile::query()
            ->withTrashed()
            ->where('staff_id', $staff->id)
            ->effectiveAt($at)
            ->orderBy('id')
            ->get()
            ->filter(fn (SalesProfile $profile) => $this->isEligibleAt($profile, [$eligibility], $at))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function byIdAt(
        ?string $profileId,
        array $eligibilities,
        CarbonInterface $at,
        ?string $companyId = null,
    ): ?SalesProfile {
        if (! $profileId) {
            return null;
        }

        $profile = SalesProfile::query()
            ->withTrashed()
            ->whereKey($profileId)
            ->when($companyId, fn ($query, string $id) => $query->where('company_id', $id))
            ->effectiveAt($at)
            ->first();

        return $profile && $this->isEligibleAt($profile, $eligibilities, $at) ? $profile : null;
    }

    public function isEligibleAt(SalesProfile $profile, array $eligibilities, CarbonInterface $at): bool
    {
        foreach ($eligibilities as $eligibility) {
            if (! in_array($eligibility, self::ELIGIBILITIES, true)) {
                throw new InvalidArgumentException('Unsupported Sales Profile eligibility.');
            }
        }

        if (! $profile->effective_from
            || $profile->effective_from->gt($at)
            || ($profile->effective_until && $profile->effective_until->lte($at))) {
            return false;
        }

        $profile->loadMissing('staff');
        $staff = $profile->staff;
        if (! $staff
            || ($staff->employment_ended_at && $staff->employment_ended_at->lte($at))
            || ($staff->deleted_at && $staff->deleted_at->lte($at))) {
            return false;
        }

        $configuration = $this->configurationAt($profile, $at);
        if (! $configuration
            || ($configuration['company_id'] ?? null) !== $profile->company_id
            || ($configuration['staff_id'] ?? null) !== $profile->staff_id
            || ! is_string($configuration['staff_category_snapshot'] ?? null)
            || trim($configuration['staff_category_snapshot']) === ''
            || ($configuration['staff_category_snapshot'] ?? null) !== $profile->staff_category_snapshot
            || ($configuration['status'] ?? null) !== 'active'
            || ! is_string($configuration['effective_from'] ?? null)
            || trim($configuration['effective_from']) === ''
            || (($configuration['effective_until'] ?? null) !== null
                && ! is_string($configuration['effective_until']))
            || preg_match('/^[A-Z]{3}$/', (string) ($configuration['reporting_currency'] ?? '')) !== 1) {
            return false;
        }

        try {
            $effectiveFrom = CarbonImmutable::parse($configuration['effective_from']);
            $effectiveUntil = ($configuration['effective_until'] ?? null)
                ? CarbonImmutable::parse((string) $configuration['effective_until'])
                : null;
        } catch (\Throwable) {
            return false;
        }

        if ($effectiveFrom->gt($at) || ($effectiveUntil && $effectiveUntil->lte($at))) {
            return false;
        }

        foreach ($eligibilities as $eligibility) {
            if (($configuration["{$eligibility}_eligible"] ?? null) !== true) {
                return false;
            }
        }

        return true;
    }

    private function configurationAt(SalesProfile $profile, CarbonInterface $at): ?array
    {
        $event = DB::table('sales_profile_events')
            ->where('sales_profile_id', $profile->id)
            ->where('occurred_at', '<=', $at)
            ->orderByDesc('occurred_at')
            ->orderByDesc('profile_version')
            ->orderByDesc('id')
            ->first(['after_configuration']);
        if (! $event || $event->after_configuration === null) {
            return null;
        }

        if (is_array($event->after_configuration)) {
            return $event->after_configuration;
        }

        try {
            $configuration = json_decode((string) $event->after_configuration, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($configuration) ? $configuration : null;
    }
}
