<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Sales\SalesBookingAttribution;
use App\Models\Sales\SalesCommissionPlanAssignment;
use App\Models\Sales\SalesProfile;
use App\Support\Foundation\CanonicalJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CommissionPlanResolver
{
    public function __construct(private readonly DomainEventPublisher $events) {}

    public function freezeFamilyForAttribution(SalesBookingAttribution $attribution): ?SalesBookingAttribution
    {
        if (! config('sales.features.commission_shadow', false) && ! config('sales.features.commission_accrual', false)) {
            return $attribution;
        }
        if ($attribution->commission_plan_family_id) {
            return $attribution;
        }
        $profile = $attribution->acquisition_sales_profile_id
            ? SalesProfile::query()->with('staff')->find($attribution->acquisition_sales_profile_id)
            : null;
        if (! $profile) {
            return null;
        }
        $matches = $this->assignmentCandidates($attribution, $profile);
        if ($matches->isEmpty()) {
            return null;
        }
        $highest = (int) $matches->max('precedence');
        $winners = $matches->where('precedence', $highest)->values();
        if ($winners->count() !== 1) {
            return null;
        }
        $assignment = $winners->first();
        $attribution->update([
            'commission_plan_family_id' => $assignment->plan_family_id,
            'commission_plan_assignment_id' => $assignment->id,
            'commission_plan_resolved_at' => $attribution->secured_at,
        ]);

        return $attribution->fresh();
    }

    public function previewMissingFamily(SalesBookingAttribution $attribution): array
    {
        if ($attribution->commission_plan_family_id || $attribution->commission_plan_assignment_id) {
            return ['correction_allowed' => false, 'blocker' => 'The attribution already has frozen commission plan evidence.', 'write_performed' => false];
        }
        if (! $attribution->company_id || ! $attribution->acquisition_sales_profile_id || ! $attribution->secured_at) {
            return ['correction_allowed' => false, 'blocker' => 'Legal entity, acquisition Profile, and secured time are required before plan-family correction.', 'write_performed' => false];
        }
        $profile = SalesProfile::query()->withTrashed()->with('staff')->find($attribution->acquisition_sales_profile_id);
        if (! $profile || $profile->company_id !== $attribution->company_id) {
            return ['correction_allowed' => false, 'blocker' => 'The frozen acquisition Profile does not reconcile to the attribution legal entity.', 'write_performed' => false];
        }
        $matches = $this->assignmentCandidates($attribution, $profile);
        if ($matches->isEmpty()) {
            return ['correction_allowed' => false, 'blocker' => 'No approved plan assignment covered the original secured time.', 'write_performed' => false];
        }
        $highest = (int) $matches->max('precedence');
        $winners = $matches->where('precedence', $highest)->values();
        if ($winners->count() !== 1) {
            return ['correction_allowed' => false, 'blocker' => 'Plan assignment precedence is ambiguous at the original secured time.', 'write_performed' => false];
        }
        $assignment = $winners->first();
        if (! $assignment->created_by || ! $assignment->approved_by
            || $assignment->created_by === $assignment->approved_by || ! $assignment->approved_at) {
            return ['correction_allowed' => false, 'blocker' => 'The winning plan assignment lacks maker-checker approval evidence.', 'write_performed' => false];
        }
        $snapshot = [
            'attribution_id' => $attribution->id,
            'attribution_version' => $attribution->version,
            'company_id' => $attribution->company_id,
            'secured_at' => $attribution->secured_at->toIso8601String(),
            'commission_category' => $attribution->commission_category,
            'acquisition_sales_profile_id' => $profile->id,
            'plan_family_id' => $assignment->plan_family_id,
            'plan_assignment_id' => $assignment->id,
            'assignment_precedence' => $assignment->precedence,
            'assignment_created_by' => $assignment->created_by,
            'assignment_approved_by' => $assignment->approved_by,
            'assignment_approved_at' => $assignment->approved_at?->toIso8601String(),
        ];

        return [
            'correction_allowed' => true,
            'blocker' => null,
            'frozen_correction_snapshot' => $snapshot,
            'correction_checksum' => hash('sha256', CanonicalJson::encode($snapshot)),
            'prohibited_approver_ids' => [$assignment->created_by, $assignment->approved_by],
            'write_performed' => false,
        ];
    }

    public function correctMissingFamily(
        string $attributionId,
        int $expectedVersion,
        string $previewChecksum,
        string $reason,
        string $idempotencyKey,
        string $actorUserId,
    ): SalesBookingAttribution {
        return DB::transaction(function () use ($attributionId, $expectedVersion, $previewChecksum, $reason, $idempotencyKey, $actorUserId) {
            $attribution = SalesBookingAttribution::query()->lockForUpdate()->findOrFail($attributionId);
            $existing = DB::table('sales_booking_attribution_events')
                ->where('attribution_id', $attribution->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $outbox = DB::table('domain_outbox_events')->where('domain', 'sales')
                    ->where('aggregate_type', 'booking_attribution')->where('aggregate_id', $attribution->id)
                    ->where('correlation_id', $idempotencyKey)->first();
                $outboxPayload = $outbox ? json_decode((string) $outbox->payload, true) : null;
                if ($existing->event_type !== 'commission_plan_family_corrected'
                    || $existing->reason !== trim($reason)
                    || $existing->actor_user_id !== $actorUserId
                    || (int) $existing->version !== $expectedVersion + 1
                    || ! is_array($outboxPayload)
                    || ! hash_equals((string) ($outboxPayload['correction_checksum'] ?? ''), $previewChecksum)) {
                    throw new RuntimeException('The attribution idempotency key was reused with different correction evidence.');
                }
                return $attribution;
            }
            if ((int) $attribution->version !== $expectedVersion) {
                throw new RuntimeException('The attribution version changed; refresh the plan-family preview.');
            }
            $preview = $this->previewMissingFamily($attribution);
            if (! ($preview['correction_allowed'] ?? false)) {
                throw new RuntimeException($preview['blocker'] ?? 'The plan-family correction is blocked.');
            }
            if (! hash_equals($preview['correction_checksum'], $previewChecksum)) {
                throw new RuntimeException('The approved plan-assignment evidence changed; refresh the preview.');
            }
            if (in_array($actorUserId, $preview['prohibited_approver_ids'], true)) {
                throw new RuntimeException('The plan assignment maker or approver cannot approve its historical attribution correction.');
            }
            $snapshot = $preview['frozen_correction_snapshot'];
            SalesCommissionPlanAssignment::query()->whereKey($snapshot['plan_assignment_id'])->lockForUpdate()->firstOrFail();
            $version = $attribution->version + 1;
            DB::table('sales_booking_attribution_events')->insert([
                'id' => (string) Str::uuid(), 'attribution_id' => $attribution->id, 'version' => $version,
                'event_type' => 'commission_plan_family_corrected', 'field_name' => 'commission_plan_family_id',
                'from_value' => null, 'to_value' => $snapshot['plan_family_id'],
                'from_sales_profile_id' => null, 'to_sales_profile_id' => null,
                'effective_at' => $attribution->secured_at, 'reason' => trim($reason),
                'idempotency_key' => $idempotencyKey, 'actor_user_id' => $actorUserId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $attribution->update([
                'commission_plan_family_id' => $snapshot['plan_family_id'],
                'commission_plan_assignment_id' => $snapshot['plan_assignment_id'],
                'commission_plan_resolved_at' => $attribution->secured_at,
                'version' => $version, 'updated_user_id' => $actorUserId,
            ]);
            $this->events->record('sales', $attribution->company_id, 'booking_attribution', $attribution->id,
                'sales.attribution.commission_plan_family_corrected', $version, 1, [
                    'booking_id' => $attribution->booking_id,
                    'plan_family_id' => $snapshot['plan_family_id'],
                    'plan_assignment_id' => $snapshot['plan_assignment_id'],
                    'correction_checksum' => $previewChecksum,
                ], $attribution->secured_at, $idempotencyKey);

            return $attribution->fresh();
        }, 3);
    }

    /** @return Collection<int, SalesCommissionPlanAssignment> */
    private function assignmentCandidates(SalesBookingAttribution $attribution, SalesProfile $profile): Collection
    {
        $staff = $profile->staff;
        return SalesCommissionPlanAssignment::query()
            ->where('sales_commission_plan_assignments.company_id', $attribution->company_id)
            ->where('sales_commission_plan_assignments.status', 'approved')
            ->where('sales_commission_plan_assignments.effective_from', '<=', $attribution->secured_at)
            ->where(fn ($q) => $q->whereNull('sales_commission_plan_assignments.effective_until')
                ->orWhere('sales_commission_plan_assignments.effective_until', '>', $attribution->secured_at))
            ->whereHas('planFamily', fn ($q) => $q->where('status', 'approved')
                ->where('commission_category', $attribution->commission_category))
            ->where(function ($q) use ($profile, $staff) {
                $q->where('scope_type', 'company')
                    ->orWhere(fn ($profileScope) => $profileScope->where('scope_type', 'sales_profile')->where('sales_profile_id', $profile->id))
                    ->orWhere(fn ($employeeScope) => $employeeScope->where('scope_type', 'employee')->where('staff_id', $staff?->id))
                    ->orWhere(fn ($categoryScope) => $categoryScope->where('scope_type', 'staff_category')->where('staff_category', $staff?->staff_type));
            })
            ->get();
    }
}
