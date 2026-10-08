<?php

namespace App\Services\Sales;

use App\Contracts\Foundation\DomainEventPublisher;
use App\Models\Booking\Booking;
use App\Models\Sales\SalesActivity;
use App\Models\Sales\SalesOpportunity;
use App\Models\Sales\SalesOpportunityStageEvent;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesTask;
use App\Models\Sales\SalesTaskEvent;
use App\Models\Inquiry;
use App\Models\PhoneCall;
use App\Support\Foundation\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesCrmService
{
    private const TRANSITIONS = [
        'new' => ['contacted', 'lost'], 'contacted' => ['qualified', 'lost'],
        'qualified' => ['quotation', 'lost'], 'quotation' => ['negotiation', 'won', 'lost'],
        'negotiation' => ['quotation', 'won', 'lost'], 'lost' => ['qualified'], 'won' => [],
    ];

    public function __construct(
        private readonly DomainEventPublisher $events,
        private readonly SalesMetricFactService $facts,
        private readonly SalesPolicySettingsService $policySettings,
    ) {}

    public function createOpportunity(array $data, string $actorUserId, ?array $sourceUserIds): SalesOpportunity
    {
        return DB::transaction(function () use ($data, $actorUserId, $sourceUserIds) {
            $this->lockActiveCompany((string) $data['company_id']);
            abort_if(! empty($data['inquiry_id']) && ! empty($data['source_phone_call_id']), 422,
                'An opportunity can have only one managed source record.');
            $owner = $this->lockActiveSalesProfile((string) $data['owner_sales_profile_id'], (string) $data['company_id']);
            if (! empty($data['inquiry_id'])) {
                $inquiry = Inquiry::query()->lockForUpdate()->findOrFail($data['inquiry_id']);
                $sourceOwnerId = $inquiry->assigned_to ?: $inquiry->created_user_id;
                abort_unless($sourceUserIds !== null && $sourceOwnerId
                    && in_array((string) $sourceOwnerId, $sourceUserIds, true), 403,
                    'The Inquiry source is outside your authorised Sales scope.');
                $data['source'] = $inquiry->source ?: 'inquiry';
                $data['customer_id'] = $data['customer_id'] ?? $inquiry->customer_id;
                $data['prospect_name'] = $data['prospect_name'] ?? $inquiry->name;
                $data['prospect_email'] = $data['prospect_email'] ?? $inquiry->email;
                $data['prospect_phone'] = $data['prospect_phone'] ?? $inquiry->phone;
            } elseif (! empty($data['source_phone_call_id'])) {
                $phoneCall = PhoneCall::query()->lockForUpdate()->findOrFail($data['source_phone_call_id']);
                abort_unless($sourceUserIds !== null && $phoneCall->created_user_id
                    && in_array((string) $phoneCall->created_user_id, $sourceUserIds, true), 403,
                    'The Phone Call source is outside your authorised Sales scope.');
                $data['source'] = 'phone_call';
                $data['prospect_name'] = $data['prospect_name'] ?? $phoneCall->client_name;
                $data['prospect_phone'] = $data['prospect_phone'] ?? $phoneCall->phone;
            }
            $this->assertNoSilentCustomerDuplicate($data);
            if (! empty($data['inquiry_id'])) {
                abort_if(SalesOpportunity::query()->where('inquiry_id', $data['inquiry_id'])->exists(), 409, 'This inquiry is already linked to an opportunity.');
            }
            if (! empty($data['source_phone_call_id'])) {
                abort_if(SalesOpportunity::query()->where('source_phone_call_id', $data['source_phone_call_id'])->exists(), 409, 'This Phone Call is already linked to an opportunity.');
            }
            $opportunity = SalesOpportunity::create($data + [
                'opportunity_number' => 'OPP-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
                'stage' => 'new', 'state_version' => 1, 'created_user_id' => $actorUserId,
            ]);
            $this->stageEvent($opportunity, null, 'new', null, 'Opportunity created.', 'opportunity-created:'.$opportunity->id, $actorUserId);
            return $opportunity;
        });
    }

    public function transition(SalesOpportunity $opportunity, string $toStage, int $expectedVersion, ?string $reasonCode, ?string $reason, string $key, string $actorUserId): SalesOpportunity
    {
        try {
            return DB::transaction(function () use ($opportunity, $toStage, $expectedVersion, $reasonCode, $reason, $key, $actorUserId) {
                $this->lockActiveCompany((string) $opportunity->company_id);
                $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    $this->assertOpportunityEventReplay($existing, $locked, $toStage, $reasonCode, $reason, $expectedVersion, $actorUserId);
                    return $locked->refresh();
                }
                abort_unless($locked->state_version === $expectedVersion, 409, 'Opportunity version changed; refresh before retrying.');
                abort_unless(in_array($toStage, self::TRANSITIONS[$locked->stage] ?? [], true), 422, "Invalid opportunity transition from {$locked->stage} to {$toStage}.");
                abort_if($toStage === 'won', 422, 'An opportunity becomes won only from a confirmed linked booking.');
                abort_if($toStage === 'lost' && (! $reasonCode || ! $reason), 422, 'A lost reason code and explanation are required.');
                $from = $locked->stage;
                $locked->update([
                    'stage' => $toStage, 'lost_reason_code' => $toStage === 'lost' ? $reasonCode : null,
                    'lost_reason' => $toStage === 'lost' ? $reason : null, 'closed_at' => $toStage === 'lost' ? now() : null,
                    'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId,
                ]);
                $this->stageEvent($locked, $from, $toStage, $reasonCode, $reason, $key, $actorUserId);
                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $opportunity, $toStage, $expectedVersion, $reasonCode, $reason, $key, $actorUserId) {
                $this->lockActiveCompany((string) $opportunity->company_id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if (! $existing) throw $exception;
                $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $this->assertOpportunityEventReplay($existing, $locked, $toStage, $reasonCode, $reason, $expectedVersion, $actorUserId);
                return $locked->refresh();
            });
        }
    }

    public function transfer(SalesOpportunity $opportunity, SalesProfile $newOwner, int $expectedVersion, string $reason, string $key, string $actorUserId): SalesOpportunity
    {
        try {
            return DB::transaction(function () use ($opportunity, $newOwner, $expectedVersion, $reason, $key, $actorUserId) {
                $this->lockActiveCompany((string) $opportunity->company_id);
                $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    $this->assertOpportunityEventReplay($existing, $locked, null, 'owner_transfer', $reason, $expectedVersion, $actorUserId, (string) $newOwner->id);
                    return $locked->refresh();
                }
                abort_unless($locked->state_version === $expectedVersion, 409, 'Opportunity version changed; refresh before retrying.');
                $newOwner = $this->lockActiveSalesProfile((string) $newOwner->id, (string) $locked->company_id);
                abort_unless($locked->company_id === $newOwner->company_id, 422, 'Opportunity transfers cannot cross legal entities.');
                $oldOwner = $locked->owner_sales_profile_id;
                $locked->update(['owner_sales_profile_id' => $newOwner->id, 'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId]);
                $this->stageEvent($locked, $locked->stage, $locked->stage, 'owner_transfer', $reason, $key, $actorUserId, $oldOwner);
                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $opportunity, $newOwner, $expectedVersion, $reason, $key, $actorUserId) {
                $this->lockActiveCompany((string) $opportunity->company_id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if (! $existing) throw $exception;
                $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $this->assertOpportunityEventReplay($existing, $locked, null, 'owner_transfer', $reason, $expectedVersion, $actorUserId, (string) $newOwner->id);
                return $locked->refresh();
            });
        }
    }

    private function assertOpportunityEventReplay(
        SalesOpportunityStageEvent $event,
        SalesOpportunity $opportunity,
        ?string $toStage,
        ?string $reasonCode,
        ?string $reason,
        int $expectedVersion,
        string $actorUserId,
        ?string $toOwnerId = null,
    ): void {
        $sameOwner = $event->from_owner_sales_profile_id === $event->to_owner_sales_profile_id;
        $sameOperation = $toOwnerId === null
            ? $sameOwner && $event->from_stage !== null && $event->from_stage !== $event->to_stage
                && $event->to_stage === $toStage
            : $event->from_stage === $event->to_stage
                && $event->to_owner_sales_profile_id === $toOwnerId;
        abort_unless($event->opportunity_id === $opportunity->id
            && $sameOperation
            && $event->reason_code === $reasonCode
            && $event->reason === $reason
            && $event->actor_user_id === $actorUserId
            && ($event->snapshot['state_version'] ?? null) === $expectedVersion + 1,
            409, 'The opportunity command key was already used for a different command.');
    }

    public function markWonFromBooking(Booking $booking, ?string $actorUserId = null): ?SalesOpportunity
    {
        if (! $booking->sales_opportunity_id) return null;
        $companyId = SalesOpportunity::query()->whereKey($booking->sales_opportunity_id)->value('company_id');
        abort_unless($companyId, 404, 'Linked Sales opportunity not found.');
        return DB::transaction(function () use ($booking, $actorUserId, $companyId) {
            $this->lockActiveCompany((string) $companyId);
            $opportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($booking->sales_opportunity_id);
            if ($opportunity->stage === 'won') {
                abort_unless($opportunity->won_booking_id === $booking->id, 409, 'Opportunity is already won by another booking.');
                return $opportunity;
            }
            abort_if($opportunity->stage === 'lost', 422, 'Reopen a lost opportunity before confirming its booking.');
            $actor = $actorUserId ?: $booking->updated_user_id ?: $booking->created_user_id;
            abort_unless($actor, 422, 'A booking confirmation actor is required.');
            $from = $opportunity->stage;
            $opportunity->update(['stage' => 'won', 'won_booking_id' => $booking->id, 'won_at' => now(), 'closed_at' => now(), 'state_version' => $opportunity->state_version + 1, 'updated_user_id' => $actor]);
            $this->stageEvent($opportunity, $from, 'won', 'booking_confirmed', 'Linked booking confirmed.', 'opportunity-won:'.$booking->id, $actor);
            return $opportunity->refresh();
        });
    }

    public function linkBooking(SalesOpportunity $opportunity, Booking $booking, string $key, string $actorUserId): SalesOpportunity
    {
        try {
            return DB::transaction(function () use ($opportunity, $booking, $key, $actorUserId) {
                $this->lockActiveCompany((string) $opportunity->company_id);
                $lockedOpportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    $this->assertOpportunityBookingLinkReplay($existing, $lockedOpportunity, $lockedBooking, $actorUserId);
                    return $lockedOpportunity->refresh();
                }

                $this->lockActiveSalesProfile((string) $lockedOpportunity->owner_sales_profile_id, (string) $lockedOpportunity->company_id);
                $bookingStaff = $this->lockBookingOwnerStaff($lockedBooking, (string) $lockedOpportunity->company_id);
                abort_unless($bookingStaff, 422, 'The draft booking must have an explicit commission owner or creator in the same Sales legal entity.');
                $bookingOwnerMatches = SalesProfile::query()->whereKey($lockedOpportunity->owner_sales_profile_id)
                    ->where('staff_id', $bookingStaff->id)->where('company_id', $lockedOpportunity->company_id)
                    ->activeAt(now())->lockForUpdate()->exists();
                abort_unless($bookingOwnerMatches, 422,
                    'The draft booking acquisition owner must match the opportunity owner before linkage.');
                abort_if(in_array($lockedOpportunity->stage, ['won', 'lost'], true), 422, 'Only an open opportunity can be linked to a booking.');
                abort_if($lockedBooking->confirmed || $lockedBooking->confirmed_at || $lockedBooking->status === 'confirmed', 422, 'Link the opportunity before booking confirmation.');
                abort_if(DB::table('sales_booking_attributions')->where('booking_id', $lockedBooking->id)->exists(), 422, 'The booking already has frozen Sales attribution.');
                abort_if($lockedBooking->sales_opportunity_id || $lockedOpportunity->won_booking_id, 409, 'The booking or opportunity is already linked; retry with the original command key.');
                if ($lockedOpportunity->customer_id && $lockedBooking->customer_id) {
                    abort_unless($lockedOpportunity->customer_id === $lockedBooking->customer_id, 422, 'Opportunity and booking Customers must match.');
                }
                $lockedBooking->update(['sales_opportunity_id' => $lockedOpportunity->id, 'updated_user_id' => $actorUserId]);
                $lockedOpportunity->update(['state_version' => $lockedOpportunity->state_version + 1, 'updated_user_id' => $actorUserId]);
                $this->stageEvent($lockedOpportunity, $lockedOpportunity->stage, $lockedOpportunity->stage, 'booking_linked', 'Draft booking linked.', $key, $actorUserId,
                    $lockedOpportunity->owner_sales_profile_id, [
                        'booking_id' => (string) $lockedBooking->id,
                        'booking_owner_staff_id' => (string) $bookingStaff->id,
                    ], 'booking_linked');
                return $lockedOpportunity->refresh();
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $opportunity, $booking, $key, $actorUserId) {
                $this->lockActiveCompany((string) $opportunity->company_id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if (! $existing) throw $exception;
                $lockedOpportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                $this->assertOpportunityBookingLinkReplay($existing, $lockedOpportunity, $lockedBooking, $actorUserId);
                return $lockedOpportunity->refresh();
            });
        }
    }

    private function assertOpportunityBookingLinkReplay(
        SalesOpportunityStageEvent $event,
        SalesOpportunity $opportunity,
        Booking $booking,
        string $actorUserId,
    ): void {
        $bookingStaff = $this->lockBookingOwnerStaff($booking, (string) $opportunity->company_id);
        abort_unless($event->opportunity_id === $opportunity->id
            && $event->reason_code === 'booking_linked'
            && $event->reason === 'Draft booking linked.'
            && $event->actor_user_id === $actorUserId
            && $event->from_stage === $event->to_stage
            && $event->from_owner_sales_profile_id === $event->to_owner_sales_profile_id
            && (string) ($event->snapshot['booking_id'] ?? '') === (string) $booking->id
            && $bookingStaff && (string) ($event->snapshot['booking_owner_staff_id'] ?? '') === (string) $bookingStaff->id
            && ($booking->sales_opportunity_id === $opportunity->id || $opportunity->won_booking_id === $booking->id),
            409, 'The opportunity booking-link key was already used for a different command.');
    }

    private function lockBookingOwnerStaff(Booking $booking, string $companyId): ?object
    {
        return DB::table('staff')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($booking->commission_owner_staff_id,
                fn ($query, $staffId) => $query->where('id', $staffId),
                fn ($query) => $query->where('user_id', $booking->created_user_id))
            ->lockForUpdate()->first(['id']);
    }

    public function recordActivity(array $data, string $actorUserId, ?array $authorizedProfileIds): SalesActivity
    {
        try {
            return DB::transaction(function () use ($data, $actorUserId, $authorizedProfileIds) {
                // ponytail: company lock serializes the source-key check; use per-source locks if throughput requires it.
                $this->lockActiveCompany((string) $data['company_id']);
                $profile = SalesProfile::query()->lockForUpdate()->findOrFail($data['sales_profile_id']);
                abort_unless($profile->company_id === $data['company_id'], 422, 'Activity profile and legal entity must match.');
                $activityStaff = DB::table('staff')->where('id', $profile->staff_id)->where('company_id', $profile->company_id)
                    ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                abort_unless($activityStaff, 422, 'Activity Profile Staff must belong to the same legal entity.');
                $opportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($data['opportunity_id']);
                abort_unless($opportunity->company_id === $profile->company_id, 422, 'Activity opportunity and Sales Profile must belong to the same legal entity.');
                $opportunityOwner = SalesProfile::query()->lockForUpdate()->find($opportunity->owner_sales_profile_id);
                abort_unless($opportunityOwner && $opportunityOwner->company_id === $opportunity->company_id, 422,
                    'Activity Opportunity owner must belong to the same legal entity.');
                $opportunityStaff = DB::table('staff')->where('id', $opportunityOwner->staff_id)
                    ->where('company_id', $opportunity->company_id)->whereNull('deleted_at')
                    ->lockForUpdate()->first(['id']);
                abort_unless($opportunityStaff, 422, 'Activity Opportunity Staff must belong to the same legal entity.');
                abort_unless($authorizedProfileIds === null || in_array($opportunity->owner_sales_profile_id, $authorizedProfileIds, true),
                    403, 'Activity Opportunity is outside your authorized Sales scope.');
                foreach ([
                    'customer_id' => 'customer_id', 'inquiry_id' => 'inquiry_id', 'phone_call_id' => 'source_phone_call_id',
                ] as $activityField => $opportunityField) {
                    if (! empty($data[$activityField])) {
                        abort_unless($data[$activityField] === $opportunity->{$opportunityField}, 422,
                            'Activity references must match the selected Opportunity.');
                    }
                }
                if (! empty($data['booking_id'])) {
                    $this->assertOpportunityBooking($data['booking_id'], $opportunity, (string) $profile->company_id);
                }
                if (! empty($data['booking_activity_id'])) {
                    $bookingActivity = DB::table('booking_activities')->where('id', $data['booking_activity_id'])
                        ->whereNull('deleted_at')->lockForUpdate()->first(['company_id', 'booking_id']);
                    abort_unless($bookingActivity && $bookingActivity->company_id === $profile->company_id
                        && $bookingActivity->booking_id
                        && (empty($data['booking_id']) || $data['booking_id'] === $bookingActivity->booking_id), 422,
                        'Activity booking event must belong to the same legal entity and selected booking.');
                    $this->assertOpportunityBooking($bookingActivity->booking_id, $opportunity, (string) $profile->company_id);
                }

                $existing = SalesActivity::query()->where('source_system', $data['source_system'])
                    ->where('source_reference', $data['source_reference'])->first();
                if ($existing) {
                    abort_unless($this->sameActivityRequest($existing, $data, $actorUserId), 409,
                        'The activity source reference was already used for different activity facts.');
                    $this->assertActivityCompanyLinks($existing);
                    return $existing;
                }

                $activity = SalesActivity::create($data + ['created_user_id' => $actorUserId]);
                $this->facts->record([
                    'company_id' => $activity->company_id, 'sales_profile_id' => $activity->sales_profile_id,
                    'metric_type' => 'sales_activity', 'quantity' => 1, 'amount_lkr' => 0,
                    'occurred_on' => $activity->occurred_at->toDateString(), 'occurred_at' => $activity->occurred_at,
                    'source_type' => 'sales_activity', 'source_id' => $activity->id, 'source_event' => 'recorded',
                    'dimensions' => ['activity_type' => $activity->activity_type, 'outcome' => $activity->outcome],
                ]);
                return $activity;
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $data, $actorUserId) {
                $this->lockActiveCompany((string) $data['company_id']);
                $existing = SalesActivity::query()->where('source_system', $data['source_system'])
                    ->where('source_reference', $data['source_reference'])->lockForUpdate()->first();
                if (! $existing) throw $exception;
                abort_unless($this->sameActivityRequest($existing, $data, $actorUserId), 409,
                    'The activity source reference was already used for different activity facts.');
                $this->assertActivityCompanyLinks($existing);
                return $existing;
            });
        }
    }

    private function sameActivityRequest(SalesActivity $activity, array $data, string $actorUserId): bool
    {
        foreach ([
            'company_id', 'sales_profile_id', 'opportunity_id', 'customer_id', 'inquiry_id', 'booking_id',
            'phone_call_id', 'booking_activity_id', 'activity_type', 'direction', 'subject', 'notes', 'outcome',
            'next_action', 'source_system', 'source_reference', 'evidence_file_id',
        ] as $field) {
            if (($activity->{$field} ?? null) !== ($data[$field] ?? null)) return false;
        }
        if ($activity->created_user_id !== $actorUserId) return false;
        foreach (['occurred_at', 'next_action_at'] as $field) {
            $stored = $activity->{$field};
            $submitted = $data[$field] ?? null;
            if (($stored === null) !== ($submitted === null)) return false;
            if ($stored && $stored->utc()->format('Y-m-d H:i:s') !== CarbonImmutable::parse($submitted)->utc()->format('Y-m-d H:i:s')) return false;
        }
        return true;
    }

    private function assertOpportunityBooking(string $bookingId, SalesOpportunity $opportunity, string $companyId, int $status = 422): void
    {
        $booking = Booking::query()->lockForUpdate()->find($bookingId);
        $bookingStaff = $booking ? $this->lockBookingOwnerStaff($booking, $companyId) : null;
        $ownerMatches = $bookingStaff && SalesProfile::query()->whereKey($opportunity->owner_sales_profile_id)
            ->where('company_id', $companyId)->where('staff_id', $bookingStaff->id)->exists();
        abort_unless($booking && $opportunity->company_id === $companyId && $ownerMatches
            && ($opportunity->won_booking_id === $bookingId || $booking->sales_opportunity_id === $opportunity->id), $status,
            'Booking owner Staff and opportunity must match the same legal entity.');
        $attributions = DB::table('sales_booking_attributions')->where('booking_id', $bookingId)
            ->lockForUpdate()->get(['company_id']);
        abort_unless($attributions->every(fn ($row) => $row->company_id === $companyId), $status,
            'Booking attribution must belong to the same legal entity.');
    }

    private function assertActivityCompanyLinks(SalesActivity $activity): void
    {
        $profile = SalesProfile::query()->lockForUpdate()->find($activity->sales_profile_id);
        abort_unless($profile && $profile->company_id === $activity->company_id, 409,
            'Activity Profile must belong to the activity legal entity.');
        $staff = DB::table('staff')->where('id', $profile->staff_id)->where('company_id', $activity->company_id)
            ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
        abort_unless($staff, 409, 'Activity Profile Staff must belong to the activity legal entity.');
        $opportunity = SalesOpportunity::query()->lockForUpdate()->find($activity->opportunity_id);
        abort_unless($opportunity && $opportunity->company_id === $activity->company_id, 409,
            'Activity Opportunity must belong to the activity legal entity.');
        $owner = SalesProfile::query()->lockForUpdate()->find($opportunity->owner_sales_profile_id);
        abort_unless($owner && $owner->company_id === $activity->company_id, 409,
            'Activity Opportunity owner must belong to the activity legal entity.');
        $ownerStaff = DB::table('staff')->where('id', $owner->staff_id)->where('company_id', $activity->company_id)
            ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
        abort_unless($ownerStaff, 409, 'Activity Opportunity Staff must belong to the activity legal entity.');
        foreach (['customer_id' => 'customer_id', 'inquiry_id' => 'inquiry_id', 'phone_call_id' => 'source_phone_call_id'] as $field => $linkedField) {
            abort_unless(empty($activity->{$field}) || $activity->{$field} === $opportunity->{$linkedField}, 409,
                'Activity references must match the linked Opportunity.');
        }
        if ($activity->booking_id) $this->assertOpportunityBooking($activity->booking_id, $opportunity, (string) $activity->company_id, 409);
        if ($activity->booking_activity_id) {
            $event = DB::table('booking_activities')->where('id', $activity->booking_activity_id)
                ->whereNull('deleted_at')->lockForUpdate()->first(['company_id', 'booking_id']);
            abort_unless($event && $event->company_id === $activity->company_id && $event->booking_id
                && (! $activity->booking_id || $activity->booking_id === $event->booking_id), 409,
                'Activity booking event must belong to the activity legal entity and selected booking.');
            $this->assertOpportunityBooking($event->booking_id, $opportunity, (string) $activity->company_id, 409);
        }
    }

    public function createTask(array $data, string $actorUserId, ?array $authorizedProfileIds): SalesTask
    {
        try {
            return DB::transaction(function () use ($data, $actorUserId, $authorizedProfileIds) {
                // ponytail: company lock serializes task create-key checks; use per-key locks if throughput requires it.
                $this->lockActiveCompany((string) $data['company_id']);
                $existing = SalesTask::query()->where('creation_idempotency_key', $data['creation_idempotency_key'])
                    ->lockForUpdate()->first();
                if ($existing) {
                    abort_unless($this->sameTaskRequest($existing, $data, $actorUserId), 409,
                        'The task creation key was already used for different task facts.');
                    $this->assertTaskCompanyLinks($existing);
                    return $existing;
                }

                $owner = $this->lockActiveSalesProfile((string) $data['owner_sales_profile_id'], (string) $data['company_id'], false);
                $opportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($data['opportunity_id']);
                abort_unless($opportunity->company_id === $owner->company_id, 422,
                    'Task Opportunity and owner must belong to the same legal entity.');
                abort_unless($authorizedProfileIds === null || in_array($opportunity->owner_sales_profile_id, $authorizedProfileIds, true),
                    403, 'Task Opportunity is outside your authorized Sales scope.');
                foreach ([
                    'customer_id' => 'customer_id', 'inquiry_id' => 'inquiry_id',
                ] as $taskField => $opportunityField) {
                    if (! empty($data[$taskField])) {
                        abort_unless($data[$taskField] === $opportunity->{$opportunityField}, 422,
                            'Task references must match the selected Opportunity.');
                    }
                }
                if (! empty($data['booking_id'])) {
                    $this->assertOpportunityBooking($data['booking_id'], $opportunity, (string) $owner->company_id);
                }

                $deadline = [
                    'contract_version' => 'explicit_utc_v1',
                    'due_at_utc' => CarbonImmutable::parse($data['due_at'])->utc()->toIso8601String(),
                    'remind_at_utc' => empty($data['remind_at']) ? null : CarbonImmutable::parse($data['remind_at'])->utc()->toIso8601String(),
                    'escalate_at_utc' => empty($data['escalate_at']) ? null : CarbonImmutable::parse($data['escalate_at'])->utc()->toIso8601String(),
                ];
                $task = SalesTask::create(array_merge($data, [
                    'due_at' => $deadline['due_at_utc'], 'remind_at' => $deadline['remind_at_utc'],
                    'escalate_at' => $deadline['escalate_at_utc'], 'deadline_contract_version' => $deadline['contract_version'],
                    'deadline_snapshot' => $deadline, 'deadline_checksum' => hash('sha256', CanonicalJson::encode($deadline)),
                    'status' => 'open', 'state_version' => 1, 'created_user_id' => $actorUserId,
                ]));
                $this->taskEvent($task, 'created', null, 'open', null, $owner->id,
                    null, 'task-created:'.$task->id, $actorUserId);
                return $task;
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $data, $actorUserId) {
                $this->lockActiveCompany((string) $data['company_id']);
                $existing = SalesTask::query()->where('creation_idempotency_key', $data['creation_idempotency_key'])
                    ->lockForUpdate()->first();
                if (! $existing) throw $exception;
                abort_unless($this->sameTaskRequest($existing, $data, $actorUserId), 409,
                    'The task creation key was already used for different task facts.');
                $this->assertTaskCompanyLinks($existing);
                return $existing;
            });
        }
    }

    private function sameTaskRequest(SalesTask $task, array $data, string $actorUserId): bool
    {
        foreach ([
            'company_id', 'opportunity_id', 'customer_id', 'inquiry_id', 'booking_id',
            'creation_idempotency_key', 'title', 'description', 'priority',
        ] as $field) {
            if (($task->{$field} ?? null) !== ($data[$field] ?? null)) return false;
        }
        if ($task->created_user_id !== $actorUserId) return false;
        $created = SalesTaskEvent::query()->where('task_id', $task->id)->where('event_type', 'created')->first();
        if (! $created || $created->to_owner_sales_profile_id !== $data['owner_sales_profile_id']
            || $created->actor_user_id !== $actorUserId) return false;
        foreach (['due_at', 'remind_at', 'escalate_at'] as $field) {
            $stored = $task->{$field};
            $submitted = $data[$field] ?? null;
            if (($stored === null) !== ($submitted === null)) return false;
            if ($stored && $stored->utc()->format('Y-m-d H:i:s') !== CarbonImmutable::parse($submitted)->utc()->format('Y-m-d H:i:s')) return false;
        }
        return true;
    }

    public function transitionTask(SalesTask $task, array $data, string $actorUserId): SalesTask
    {
        try {
            return DB::transaction(function () use ($task, $data, $actorUserId) {
                $this->lockActiveCompany((string) $task->company_id);
                $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
                $this->assertTaskCompanyLinks($locked);
                $existing = SalesTaskEvent::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_unless($existing->task_id === $locked->id && $existing->event_type === 'status_changed'
                        && $existing->to_status === $data['to_status'] && $existing->expected_version === $data['expected_version']
                        && $existing->reason === ($data['reason'] ?? null) && $existing->actor_user_id === $actorUserId,
                        409, 'The task transition key was already used for a different command.');
                    return $locked->refresh();
                }
                abort_unless($locked->state_version === $data['expected_version'], 409, 'Task version changed; refresh before retrying.');
                $allowed = ['open' => ['in_progress', 'completed', 'cancelled'], 'in_progress' => ['open', 'completed', 'cancelled'], 'completed' => ['open'], 'cancelled' => ['open']];
                abort_unless(in_array($data['to_status'], $allowed[$locked->status] ?? [], true), 422, 'Invalid task transition.');
                $from = $locked->status;
                $locked->update(['status' => $data['to_status'], 'completed_at' => $data['to_status'] === 'completed' ? now() : null, 'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId]);
                $this->taskEvent($locked, 'status_changed', $from, $data['to_status'], $locked->owner_sales_profile_id,
                    $locked->owner_sales_profile_id, $data['reason'] ?? null, $data['idempotency_key'], $actorUserId,
                    $data['expected_version']);
                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            return $this->recoverTaskEventReplay($exception, $task, $data['idempotency_key'], function ($event, $locked) use ($data, $actorUserId): void {
                abort_unless($event->task_id === $locked->id && $event->event_type === 'status_changed'
                    && $event->to_status === $data['to_status'] && $event->expected_version === $data['expected_version']
                    && $event->reason === ($data['reason'] ?? null) && $event->actor_user_id === $actorUserId,
                    409, 'The task transition key was already used for a different command.');
            });
        }
    }

    public function transferTask(SalesTask $task, SalesProfile $newOwner, int $expectedVersion, string $reason, string $key, string $actorUserId): SalesTask
    {
        try {
            return DB::transaction(function () use ($task, $newOwner, $expectedVersion, $reason, $key, $actorUserId) {
                $this->lockActiveCompany((string) $task->company_id);
                $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
                $this->assertTaskCompanyLinks($locked);
                $existing = SalesTaskEvent::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    abort_unless($existing->task_id === $locked->id && $existing->event_type === 'reassigned'
                        && $existing->to_owner_sales_profile_id === $newOwner->id && $existing->expected_version === $expectedVersion
                        && $existing->reason === $reason && $existing->actor_user_id === $actorUserId,
                        409, 'The task transfer key was already used for a different command.');
                    return $locked->refresh();
                }
                abort_unless($locked->state_version === $expectedVersion, 409, 'Task version changed; refresh before retrying.');
                $newOwner = $this->lockActiveSalesProfile((string) $newOwner->id, (string) $locked->company_id, false);
                $oldOwner = $locked->owner_sales_profile_id;
                $locked->update(['owner_sales_profile_id' => $newOwner->id, 'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId]);
                $this->taskEvent($locked, 'reassigned', $locked->status, $locked->status, $oldOwner,
                    $newOwner->id, $reason, $key, $actorUserId, $expectedVersion);
                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            return $this->recoverTaskEventReplay($exception, $task, $key, function ($event, $locked) use ($newOwner, $expectedVersion, $reason, $actorUserId): void {
                abort_unless($event->task_id === $locked->id && $event->event_type === 'reassigned'
                    && $event->to_owner_sales_profile_id === $newOwner->id && $event->expected_version === $expectedVersion
                    && $event->reason === $reason && $event->actor_user_id === $actorUserId,
                    409, 'The task transfer key was already used for a different command.');
            });
        }
    }

    private function recoverTaskEventReplay(QueryException $exception, SalesTask $task, string $key, \Closure $assertReplay): SalesTask
    {
        return DB::transaction(function () use ($exception, $task, $key, $assertReplay) {
            $this->lockActiveCompany((string) $task->company_id);
            $event = SalesTaskEvent::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if (! $event) throw $exception;
            $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
            $this->assertTaskCompanyLinks($locked);
            $assertReplay($event, $locked);
            return $locked->refresh();
        });
    }

    private function assertTaskCompanyLinks(SalesTask $task): void
    {
        $owner = SalesProfile::query()->lockForUpdate()->find($task->owner_sales_profile_id);
        abort_unless($owner && $owner->company_id === $task->company_id, 409,
            'Task owner must belong to the task legal entity.');
        $staff = DB::table('staff')->where('id', $owner->staff_id)->where('company_id', $task->company_id)
            ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
        abort_unless($staff, 409, 'Task owner Staff must belong to the task legal entity.');
        $opportunity = SalesOpportunity::query()->lockForUpdate()->find($task->opportunity_id);
        abort_unless($opportunity && $opportunity->company_id === $task->company_id, 409,
            'Task Opportunity must belong to the task legal entity.');
        foreach (['customer_id', 'inquiry_id'] as $field) {
            abort_unless(empty($task->{$field}) || $task->{$field} === $opportunity->{$field}, 409,
                'Task references must match the linked Opportunity.');
        }
        if ($task->booking_id) {
            $this->assertOpportunityBooking($task->booking_id, $opportunity, (string) $task->company_id, 409);
        }
    }

    private function lockActiveSalesProfile(string $profileId, string $companyId, bool $requireConfigured = true): SalesProfile
    {
        $query = SalesProfile::query()->activeAt(now());
        if ($requireConfigured) $query->configured();
        $owner = $query->lockForUpdate()->findOrFail($profileId);
        abort_unless($owner->company_id === $companyId, 422, 'Sales Profile and legal entity must match.');
        $staff = DB::table('staff')->where('id', $owner->staff_id)->where('company_id', $companyId)
            ->whereNull('deleted_at')->where(fn ($query) => $query->whereNull('employment_ended_at')->orWhere('employment_ended_at', '>', now()))
            ->lockForUpdate()->first(['id', 'user_id']);
        abort_unless($staff && $staff->user_id, 422, 'Sales Profile owner must be current Staff with a linked User.');
        $user = DB::table('users')->where('id', $staff->user_id)->where('is_active', true)->whereNull('deleted_at')
            ->lockForUpdate()->first(['id']);
        abort_unless($user, 422, 'Sales Profile owner must have an active User account.');
        $context = DB::table('user_contexts')->where('user_id', $staff->user_id)->where('context_type', 'staff')
            ->where('context_id', $staff->id)->where('is_active', true)->whereNull('deleted_at')->lockForUpdate()->first(['id']);
        abort_unless($context, 422, 'Sales Profile owner must have an active Staff context.');

        return $owner;
    }

    private function stageEvent(SalesOpportunity $opportunity, ?string $from, string $to, ?string $reasonCode, ?string $reason, string $key, string $actor, ?string $fromOwner = null, array $snapshotFacts = [], ?string $eventName = null): void
    {
        SalesOpportunityStageEvent::create([
            'opportunity_id' => $opportunity->id, 'from_stage' => $from, 'to_stage' => $to,
            'from_owner_sales_profile_id' => $fromOwner ?? $opportunity->owner_sales_profile_id,
            'to_owner_sales_profile_id' => $opportunity->owner_sales_profile_id, 'reason_code' => $reasonCode,
            'reason' => $reason, 'idempotency_key' => $key, 'actor_user_id' => $actor, 'occurred_at' => now(),
            'snapshot' => array_merge($opportunity->only(['stage', 'owner_sales_profile_id', 'expected_value_lkr', 'probability_percent', 'expected_close_date', 'state_version']), $snapshotFacts),
        ]);
        $this->events->record('sales', $opportunity->company_id, 'opportunity', $opportunity->id, 'sales.opportunity.'.($eventName ?? $to), $opportunity->state_version, 1, ['from_stage' => $from, 'to_stage' => $to] + $snapshotFacts, now(), $key);
    }

    private function taskEvent(
        SalesTask $task,
        string $type,
        ?string $from,
        ?string $to,
        ?string $fromOwner,
        ?string $toOwner,
        ?string $reason,
        string $key,
        string $actor,
        ?int $expectedVersion = null,
    ): void
    {
        SalesTaskEvent::create([
            'task_id' => $task->id, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to,
            'expected_version' => $expectedVersion, 'from_owner_sales_profile_id' => $fromOwner,
            'to_owner_sales_profile_id' => $toOwner, 'reason' => $reason, 'idempotency_key' => $key,
            'actor_user_id' => $actor, 'occurred_at' => now(),
        ]);
    }

    private function assertNoSilentCustomerDuplicate(array $data): void
    {
        if (! empty($data['customer_id'])) return;
        abort_if(empty($data['prospect_name']) || (empty($data['prospect_email']) && empty($data['prospect_phone'])), 422, 'A prospect name and at least one contact identifier are required when no Customer is linked.');
        $matches = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->select('customers.id')
            ->where(function ($query) use ($data) {
                if (! empty($data['prospect_email'])) $query->orWhereRaw('LOWER(users.email) = ?', [strtolower($data['prospect_email'])]);
                if (! empty($data['prospect_phone'])) $query->orWhere('users.phone', $data['prospect_phone']);
            })->limit(10)->pluck('customers.id');
        abort_if($matches->isNotEmpty(), 409, 'Potential existing customer matches require explicit duplicate review: '.$matches->implode(','));
    }

    private function assertEnabled(string $companyId): void
    {
        abort_unless($this->policySettings->featureEnabled($companyId, 'crm'), 409,
            'Sales CRM writes are not activated for this legal entity.');
    }

    private function lockActiveCompany(string $companyId): void
    {
        abort_unless(DB::table('companies')->where('id', $companyId)->where('is_active', true)
            ->whereNull('deleted_at')->lockForUpdate()->first(['id']), 422, 'Select an active legal entity.');
        $this->assertEnabled($companyId);
    }
}
