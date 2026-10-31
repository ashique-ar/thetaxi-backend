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
use App\Support\PhoneNumberMatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function createOpportunity(array $data, string $actorUserId, ?array $sourceUserIds, ?array $authorizedProfileIds = null): SalesOpportunity
    {
        return DB::transaction(function () use ($data, $actorUserId, $sourceUserIds, $authorizedProfileIds) {
            $this->assertSalesFeatureEnabled();
            $followUpTask = $data['follow_up_task'] ?? null;
            unset($data['follow_up_task']);
            abort_if(! empty($data['inquiry_id']) && ! empty($data['source_phone_call_id']), 422,
                'An opportunity can have only one managed source record.');
            $owner = $this->lockActiveSalesProfile((string) $data['owner_sales_profile_id']);
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
            if ($followUpTask) {
                $data['next_action'] = $followUpTask['title'];
                $data['next_action_at'] = $followUpTask['due_at'];
            }
            if (! empty($data['inquiry_id'])) {
                abort_if(SalesOpportunity::query()->where('inquiry_id', $data['inquiry_id'])->exists(), 409, 'This inquiry is already linked to an opportunity.');
            }
            if (! empty($data['source_phone_call_id'])) {
                abort_if(SalesOpportunity::query()->where('source_phone_call_id', $data['source_phone_call_id'])->exists(), 409, 'This Phone Call is already linked to an opportunity.');
            }
            $data['workflow_quotation_stage_setting_id'] = $this->policySettings
                ->approvedFeatureSetting('crm_quotation_stage')?->id;
            foreach ([
                'workflow_contact_stage_setting_id' => 'crm_contact_stage',
                'workflow_qualification_stage_setting_id' => 'crm_qualification_stage',
                'workflow_negotiation_stage_setting_id' => 'crm_negotiation_stage',
            ] as $column => $feature) {
                $data[$column] = $this->policySettings->approvedFeatureSetting($feature)?->id;
            }
            $opportunity = SalesOpportunity::create($data + [
                'opportunity_number' => 'OPP-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
                'stage' => 'new', 'state_version' => 1, 'created_user_id' => $actorUserId,
            ]);
            $this->stageEvent($opportunity, null, 'new', null, 'Opportunity created.', 'opportunity-created:'.$opportunity->id, $actorUserId);
            if ($followUpTask) {
                $this->createTask([
                    'owner_sales_profile_id' => (string) $owner->id,
                    'opportunity_id' => (string) $opportunity->id,
                    'title' => $followUpTask['title'],
                    'description' => $followUpTask['description'] ?? null,
                    'priority' => $followUpTask['priority'] ?? 'normal',
                    'due_at' => $followUpTask['due_at'],
                    'remind_at' => $followUpTask['remind_at'] ?? null,
                    'creation_idempotency_key' => 'opportunity-first-follow-up:'.$opportunity->id,
                ], $actorUserId, $authorizedProfileIds, $sourceUserIds);
            }
            return $opportunity;
        });
    }

    public function transition(
        SalesOpportunity $opportunity,
        string $toStage,
        int $expectedVersion,
        ?string $reasonCode,
        ?string $reason,
        string $key,
        string $actorUserId,
        ?array $followUpTask,
        ?array $authorizedProfileIds,
        ?array $sourceUserIds,
    ): SalesOpportunity
    {
        try {
            return DB::transaction(function () use ($opportunity, $toStage, $expectedVersion, $reasonCode, $reason, $key, $actorUserId, $followUpTask, $authorizedProfileIds, $sourceUserIds) {
                $this->assertSalesFeatureEnabled();
                $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    $this->assertOpportunityEventReplay($existing, $locked, $toStage, $reasonCode, $reason, $expectedVersion, $actorUserId);
                    if ($toStage !== 'lost') $this->createStageFollowUp($locked, $followUpTask ?? [], $key, $actorUserId, $authorizedProfileIds, $sourceUserIds);
                    return $locked->refresh();
                }
                abort_unless($locked->state_version === $expectedVersion, 409, 'Opportunity version changed; refresh before retrying.');
                abort_unless(in_array($toStage, $this->allowedTransitions($locked), true), 422, "Invalid opportunity transition from {$locked->stage} to {$toStage}.");
                abort_if($toStage === 'won', 422, 'An opportunity becomes won only from a confirmed linked booking.');
                abort_if($toStage === 'lost' && (! $reasonCode || ! $reason), 422, 'A lost reason code and explanation are required.');
                abort_if($toStage !== 'lost' && (empty($followUpTask['title']) || empty($followUpTask['due_at'])), 422,
                    'An open opportunity needs a next action and due time.');
                abort_if($toStage === 'lost' && $followUpTask, 422, 'A closed opportunity cannot have a new follow-up task.');
                $from = $locked->stage;
                $nextActionAt = $followUpTask ? CarbonImmutable::parse($followUpTask['due_at'])->utc()->toIso8601String() : null;
                $locked->update([
                    'stage' => $toStage, 'lost_reason_code' => $toStage === 'lost' ? $reasonCode : null,
                    'lost_reason' => $toStage === 'lost' ? $reason : null, 'closed_at' => $toStage === 'lost' ? now() : null,
                    'next_action' => $followUpTask['title'] ?? null, 'next_action_at' => $nextActionAt,
                    'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId,
                ]);
                $this->stageEvent($locked, $from, $toStage, $reasonCode, $reason, $key, $actorUserId, null,
                    $followUpTask ? ['next_action' => $followUpTask['title'], 'next_action_at' => $nextActionAt] : []);
                if ($followUpTask) $this->createStageFollowUp($locked, $followUpTask, $key, $actorUserId, $authorizedProfileIds, $sourceUserIds);
                if ($toStage === 'lost') $this->cancelOpenOpportunityTasks($locked, $reason, $key, $actorUserId);
                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $opportunity, $toStage, $expectedVersion, $reasonCode, $reason, $key, $actorUserId, $followUpTask, $authorizedProfileIds, $sourceUserIds) {
                $this->assertSalesFeatureEnabled();
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if (! $existing) throw $exception;
                $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $this->assertOpportunityEventReplay($existing, $locked, $toStage, $reasonCode, $reason, $expectedVersion, $actorUserId);
                if ($toStage !== 'lost') $this->createStageFollowUp($locked, $followUpTask ?? [], $key, $actorUserId, $authorizedProfileIds, $sourceUserIds);
                return $locked->refresh();
            });
        }
    }

    private function createStageFollowUp(
        SalesOpportunity $opportunity,
        array $followUpTask,
        string $transitionKey,
        string $actorUserId,
        ?array $authorizedProfileIds,
        ?array $sourceUserIds,
    ): SalesTask {
        return $this->createTask([
            'owner_sales_profile_id' => (string) $opportunity->owner_sales_profile_id,
            'opportunity_id' => (string) $opportunity->id,
            'title' => trim((string) ($followUpTask['title'] ?? '')),
            'description' => $followUpTask['description'] ?? null,
            'priority' => $followUpTask['priority'] ?? 'normal',
            'due_at' => $followUpTask['due_at'] ?? null,
            'remind_at' => $followUpTask['remind_at'] ?? null,
            'creation_idempotency_key' => 'opportunity-stage-follow-up:'.hash('sha256', $transitionKey),
        ], $actorUserId, $authorizedProfileIds, $sourceUserIds);
    }

    private function cancelOpenOpportunityTasks(SalesOpportunity $opportunity, ?string $reason, string $transitionKey, string $actorUserId): void
    {
        SalesTask::query()->where('opportunity_id', $opportunity->id)->whereIn('status', ['open', 'in_progress'])
            ->orderBy('id')->chunkById(100, function ($tasks) use ($reason, $transitionKey, $actorUserId): void {
                foreach ($tasks as $task) {
                    $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
                    if (! in_array($locked->status, ['open', 'in_progress'], true)) continue;
                    $from = $locked->status;
                    $locked->update([
                        'status' => 'cancelled', 'completed_at' => null,
                        'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId,
                    ]);
                    $this->taskEvent($locked, 'status_changed', $from, 'cancelled',
                        $locked->owner_sales_profile_id, $locked->owner_sales_profile_id,
                        mb_substr('Opportunity marked lost: '.trim((string) $reason), 0, 2000),
                        'opportunity-lost-task:'.hash('sha256', $transitionKey.':'.$locked->id),
                        $actorUserId, $locked->state_version - 1);
                }
            });
    }

    public function allowedTransitions(SalesOpportunity $opportunity): array
    {
        $settingColumns = [
            'contacted' => ['workflow_contact_stage_setting_id', 'crm_contact_stage'],
            'qualified' => ['workflow_qualification_stage_setting_id', 'crm_qualification_stage'],
            'quotation' => ['workflow_quotation_stage_setting_id', 'crm_quotation_stage'],
            'negotiation' => ['workflow_negotiation_stage_setting_id', 'crm_negotiation_stage'],
        ];
        $enabled = ['new' => true, 'won' => true, 'lost' => true];
        foreach ($settingColumns as $stage => [$column, $feature]) {
            $settingId = $opportunity->{$column};
            if (! $settingId) {
                $enabled[$stage] = true;
                continue;
            }
            $setting = DB::table('sales_feature_settings')->where('id', $settingId)->where('feature_key', $feature)->first();
            abort_unless($setting, 409, 'Opportunity workflow version is unavailable.');
            $enabled[$stage] = (bool) $setting->enabled;
        }

        $orderedStages = ['new', 'contacted', 'qualified', 'quotation', 'negotiation'];
        $transitions = [];
        foreach ($orderedStages as $index => $stage) {
            $next = null;
            foreach (array_slice($orderedStages, $index + 1) as $candidate) {
                if ($enabled[$candidate]) { $next = $candidate; break; }
            }
            $transitions[$stage] = $next ? [$next, 'lost'] : ['lost'];
        }
        if ($enabled['negotiation'] && $enabled['quotation']) {
            $transitions['negotiation'][] = 'quotation';
        }
        $transitions['lost'] = ['new'];
        $transitions['won'] = [];

        return $transitions[$opportunity->stage] ?? [];
    }

    public function transfer(SalesOpportunity $opportunity, SalesProfile $newOwner, int $expectedVersion, string $reason, string $key, string $actorUserId): SalesOpportunity
    {
        try {
            return DB::transaction(function () use ($opportunity, $newOwner, $expectedVersion, $reason, $key, $actorUserId) {
                $this->assertSalesFeatureEnabled();
                $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    $this->assertOpportunityEventReplay($existing, $locked, null, 'owner_transfer', $reason, $expectedVersion, $actorUserId, (string) $newOwner->id);
                    return $locked->refresh();
                }
                abort_unless($locked->state_version === $expectedVersion, 409, 'Opportunity version changed; refresh before retrying.');
                $newOwner = $this->lockActiveSalesProfile((string) $newOwner->id);
                $oldOwner = $locked->owner_sales_profile_id;
                $locked->update(['owner_sales_profile_id' => $newOwner->id, 'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId]);
                $this->stageEvent($locked, $locked->stage, $locked->stage, 'owner_transfer', $reason, $key, $actorUserId, $oldOwner);
                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $opportunity, $newOwner, $expectedVersion, $reason, $key, $actorUserId) {
                $this->assertSalesFeatureEnabled();
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
        return DB::transaction(function () use ($booking, $actorUserId) {
            $this->assertSalesFeatureEnabled();
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
                $this->assertSalesFeatureEnabled();
                $lockedOpportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                $existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    $this->assertOpportunityBookingLinkReplay($existing, $lockedOpportunity, $lockedBooking, $actorUserId);
                    return $lockedOpportunity->refresh();
                }

                $this->lockActiveSalesProfile((string) $lockedOpportunity->owner_sales_profile_id);
                $bookingStaff = $this->lockBookingOwnerStaff($lockedBooking);
                abort_unless($bookingStaff, 422, 'The draft booking must have an explicit commission owner or creator.');
                $bookingOwnerMatches = SalesProfile::query()->whereKey($lockedOpportunity->owner_sales_profile_id)
                    ->where('staff_id', $bookingStaff->id)
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
                $this->assertSalesFeatureEnabled();
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
        $bookingStaff = $this->lockBookingOwnerStaff($booking);
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

    private function lockBookingOwnerStaff(Booking $booking, bool $allowFormerOwner = false): ?object
    {
        return DB::table('staff')->when(! $allowFormerOwner, fn ($query) => $query->whereNull('deleted_at'))
            ->when($booking->commission_owner_staff_id,
                fn ($query, $staffId) => $query->where('id', $staffId),
                fn ($query) => $query->where('user_id', $booking->created_user_id))
            ->lockForUpdate()->first(['id']);
    }

    public function recordActivity(array $data, string $actorUserId, ?array $authorizedProfileIds, ?array $sourceUserIds = null): SalesActivity
    {
        try {
            return DB::transaction(function () use ($data, $actorUserId, $authorizedProfileIds, $sourceUserIds) {
                $followUpTask = $data['follow_up_task'] ?? null;
                unset($data['follow_up_task']);
                $this->assertSalesFeatureEnabled();
                $profile = SalesProfile::query()->lockForUpdate()->findOrFail($data['sales_profile_id']);
                $activityStaff = DB::table('staff')->where('id', $profile->staff_id)
                    ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                abort_unless($activityStaff, 422, 'Activity Profile Staff must be active.');
                $opportunity = null;
                if (! empty($data['opportunity_id'])) {
                    $opportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($data['opportunity_id']);
                    abort_unless(in_array($opportunity->stage, ['won', 'lost'], true) || $followUpTask, 422,
                        'Add a due follow-up task before recording activity on an open sales opportunity.');
                    $opportunityOwner = SalesProfile::query()->lockForUpdate()->find($opportunity->owner_sales_profile_id);
                    abort_unless($opportunityOwner, 422, 'Activity Opportunity owner must exist.');
                    $opportunityStaff = DB::table('staff')->where('id', $opportunityOwner->staff_id)
                        ->whereNull('deleted_at')
                        ->lockForUpdate()->first(['id']);
                    abort_unless($opportunityStaff, 422, 'Activity Opportunity Staff must be active.');
                    abort_unless($authorizedProfileIds === null || in_array($opportunity->owner_sales_profile_id, $authorizedProfileIds, true),
                        403, 'Activity Opportunity is outside your authorized Sales scope.');
                    $data['customer_id'] ??= $opportunity->customer_id;
                    $data['inquiry_id'] ??= $opportunity->inquiry_id;
                    $data['phone_call_id'] ??= $opportunity->source_phone_call_id;
                    foreach ([
                        'customer_id' => 'customer_id', 'inquiry_id' => 'inquiry_id', 'phone_call_id' => 'source_phone_call_id',
                    ] as $activityField => $opportunityField) {
                        if (! empty($data[$activityField])) {
                            abort_unless($data[$activityField] === $opportunity->{$opportunityField}, 422,
                                'Activity references must match the selected Opportunity.');
                        }
                    }
                    if (! empty($data['booking_id'])) {
                        $this->assertOpportunityBooking($data['booking_id'], $opportunity);
                    }
                } else {
                    $sources = array_filter([$data['booking_id'] ?? null, $data['inquiry_id'] ?? null, $data['customer_id'] ?? null]);
                    $primarySourceCount = count(array_filter([$data['booking_id'] ?? null, $data['inquiry_id'] ?? null]));
                    abort_unless(empty($data['phone_call_id']) && (($primarySourceCount === 1 && count($sources) <= 2)
                        || ($primarySourceCount === 0 && count($sources) === 1)), 422,
                        'Link this activity to one Opportunity, Booking, Inquiry, or Customer.');
                    if (! empty($data['booking_id'])) {
                        $booking = $this->lockAvailableBooking($data['booking_id']);
                        abort_unless(empty($data['customer_id']) || $data['customer_id'] === $booking->customer_id, 422,
                            'Activity Customer must match the selected Booking.');
                        $data['customer_id'] ??= $booking->customer_id;
                    } elseif (! empty($data['inquiry_id'])) {
                        $inquiry = DB::table('inquiries')->where('id', $data['inquiry_id'])->whereNull('deleted_at')->lockForUpdate()->first();
                        abort_unless($inquiry, 422, 'Select an available Inquiry.');
                        abort_unless(empty($data['customer_id']) || $data['customer_id'] === $inquiry->customer_id, 422,
                            'Activity Customer must match the selected Inquiry.');
                        $data['customer_id'] ??= $inquiry->customer_id;
                        $sourceUserId = $inquiry->assigned_to ?: $inquiry->created_user_id;
                        abort_unless($sourceUserId && $sourceUserIds !== null && in_array($sourceUserId, $sourceUserIds, true), 403,
                            'Assign the Inquiry to an employee in your Sales scope before logging an activity.');
                    } else {
                        $customer = DB::table('customer_relationship_owner_assignments')->where('customer_id', $data['customer_id'])
                            ->whereNull('ended_at')->lockForUpdate()->first();
                        abort_unless($customer?->owner_sales_profile_id, 403, 'Assign this Customer to a Sales employee before logging an activity.');
                        $customerOwner = $this->lockActiveSalesProfile((string) $customer->owner_sales_profile_id);
                        abort_unless($authorizedProfileIds === null || in_array($customerOwner->id, $authorizedProfileIds, true), 403,
                            'Customer is outside your authorized Sales scope.');
                    }
                }
                if (! empty($data['booking_activity_id'])) {
                    $bookingActivity = DB::table('booking_activities')->where('id', $data['booking_activity_id'])
                        ->whereNull('deleted_at')->lockForUpdate()->first(['booking_id']);
                    abort_unless($bookingActivity && $bookingActivity->booking_id
                        && (empty($data['booking_id']) || $data['booking_id'] === $bookingActivity->booking_id), 422,
                        'Activity booking event must match the selected booking.');
                    if ($opportunity) $this->assertOpportunityBooking($bookingActivity->booking_id, $opportunity);
                    else {
                        abort_unless(($data['booking_id'] ?? null) === $bookingActivity->booking_id, 422,
                            'Link this activity to the booking for its selected booking event.');
                        $this->lockAvailableBooking($bookingActivity->booking_id);
                    }
                }

                $existing = SalesActivity::query()->where('source_system', $data['source_system'])
                    ->where('source_reference', $data['source_reference'])->first();
                if ($existing) {
                    abort_unless($this->sameActivityRequest($existing, $data, $actorUserId), 409,
                        'The activity source reference was already used for different activity facts.');
                    $this->assertActivityLinks($existing);
                    if ($followUpTask) $this->createActivityFollowUpTask($existing, $followUpTask, $actorUserId, $authorizedProfileIds, $sourceUserIds);
                    return $existing;
                }

                $activity = SalesActivity::create($data + ['created_user_id' => $actorUserId]);
                $this->facts->record([
                    'sales_profile_id' => $activity->sales_profile_id,
                    'metric_type' => 'sales_activity', 'quantity' => 1, 'amount_lkr' => 0,
                    'occurred_on' => $activity->occurred_at->toDateString(), 'occurred_at' => $activity->occurred_at,
                    'source_type' => 'sales_activity', 'source_id' => $activity->id, 'source_event' => 'recorded',
                    'dimensions' => ['activity_type' => $activity->activity_type, 'outcome' => $activity->outcome],
                ]);
                if ($followUpTask) $this->createActivityFollowUpTask($activity, $followUpTask, $actorUserId, $authorizedProfileIds, $sourceUserIds);
                return $activity;
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $data, $actorUserId, $authorizedProfileIds, $sourceUserIds) {
                $this->assertSalesFeatureEnabled();
                if (! empty($data['opportunity_id'])) {
                    $source = SalesOpportunity::query()->whereKey($data['opportunity_id'])->firstOrFail();
                    $data['customer_id'] ??= $source->customer_id;
                    $data['inquiry_id'] ??= $source->inquiry_id;
                    $data['phone_call_id'] ??= $source->source_phone_call_id;
                } elseif (! empty($data['booking_id'])) {
                    $data['customer_id'] ??= Booking::query()->whereKey($data['booking_id'])->value('customer_id');
                } elseif (! empty($data['inquiry_id'])) {
                    $data['customer_id'] ??= DB::table('inquiries')->where('id', $data['inquiry_id'])->value('customer_id');
                }
                $existing = SalesActivity::query()->where('source_system', $data['source_system'])
                    ->where('source_reference', $data['source_reference'])->lockForUpdate()->first();
                if (! $existing) throw $exception;
                abort_unless($this->sameActivityRequest($existing, $data, $actorUserId), 409,
                    'The activity source reference was already used for different activity facts.');
                    $this->assertActivityLinks($existing);
                if (! empty($data['follow_up_task'])) $this->createActivityFollowUpTask($existing, $data['follow_up_task'], $actorUserId, $authorizedProfileIds, $sourceUserIds);
                return $existing;
            });
        }
    }

    private function createActivityFollowUpTask(SalesActivity $activity, array $followUp, string $actorUserId, ?array $authorizedProfileIds, ?array $sourceUserIds): void
    {
        $this->createTask([
            'owner_sales_profile_id' => $activity->sales_profile_id,
            'opportunity_id' => $activity->opportunity_id,
            'inquiry_id' => $activity->opportunity_id ? null : $activity->inquiry_id,
            'booking_id' => $activity->opportunity_id ? null : $activity->booking_id,
            'customer_id' => $activity->customer_id,
            'title' => $followUp['title'],
            'description' => $followUp['description'] ?? null,
            'priority' => $followUp['priority'] ?? 'normal',
            'due_at' => $followUp['due_at'],
            'remind_at' => $followUp['remind_at'] ?? null,
            'escalate_at' => null,
            'creation_idempotency_key' => 'activity-follow-up:'.hash('sha256', $activity->source_system.':'.$activity->source_reference),
        ], $actorUserId, $authorizedProfileIds, $sourceUserIds);
    }

    private function sameActivityRequest(SalesActivity $activity, array $data, string $actorUserId): bool
    {
        foreach ([
            'sales_profile_id', 'opportunity_id', 'customer_id', 'inquiry_id', 'booking_id',
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

    private function assertOpportunityBooking(string $bookingId, SalesOpportunity $opportunity, int $status = 422, bool $allowFormerOwner = false): void
    {
        $booking = Booking::query()->lockForUpdate()->find($bookingId);
        $bookingStaff = $booking ? $this->lockBookingOwnerStaff($booking, $allowFormerOwner) : null;
        $ownerMatches = $bookingStaff && SalesProfile::query()->whereKey($opportunity->owner_sales_profile_id)
            ->where('staff_id', $bookingStaff->id)->exists();
        abort_unless($booking && $ownerMatches
            && ($opportunity->won_booking_id === $bookingId || $booking->sales_opportunity_id === $opportunity->id), $status,
            'Booking owner Staff and opportunity must match.');
    }

    private function assertActivityLinks(SalesActivity $activity): void
    {
        $profile = SalesProfile::query()->lockForUpdate()->find($activity->sales_profile_id);
        abort_unless($profile, 409, 'Activity Profile must exist.');
        $staff = DB::table('staff')->where('id', $profile->staff_id)
            ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
        abort_unless($staff, 409, 'Activity Profile Staff must be active.');
        $opportunity = $activity->opportunity_id ? SalesOpportunity::query()->lockForUpdate()->find($activity->opportunity_id) : null;
        if ($activity->opportunity_id) {
            abort_unless($opportunity, 409, 'Activity Opportunity must exist.');
            $owner = SalesProfile::query()->lockForUpdate()->find($opportunity->owner_sales_profile_id);
            abort_unless($owner, 409, 'Activity Opportunity owner must exist.');
            $ownerStaff = DB::table('staff')->where('id', $owner->staff_id)
                ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
            abort_unless($ownerStaff, 409, 'Activity Opportunity Staff must be active.');
            foreach (['customer_id' => 'customer_id', 'inquiry_id' => 'inquiry_id', 'phone_call_id' => 'source_phone_call_id'] as $field => $linkedField) {
                abort_unless(empty($activity->{$field}) || $activity->{$field} === $opportunity->{$linkedField}, 409,
                    'Activity references must match the linked Opportunity.');
            }
            if ($activity->booking_id) $this->assertOpportunityBooking($activity->booking_id, $opportunity, 409);
        } else {
            $primarySourceCount = count(array_filter([$activity->booking_id, $activity->inquiry_id]));
            $sourceCount = count(array_filter([$activity->booking_id, $activity->inquiry_id, $activity->customer_id]));
            abort_unless(! $activity->phone_call_id && (($primarySourceCount === 1 && $sourceCount <= 2)
                || ($primarySourceCount === 0 && $sourceCount === 1)), 409,
                'Activity must retain a valid source.');
            if ($activity->booking_id) {
                $booking = $this->lockAvailableBooking($activity->booking_id, 409);
                abort_unless(empty($activity->customer_id) || $activity->customer_id === $booking->customer_id, 409,
                    'Activity Customer must match the linked Booking.');
            } elseif ($activity->inquiry_id) {
                $inquiry = DB::table('inquiries')->where('id', $activity->inquiry_id)->whereNull('deleted_at')->lockForUpdate()->first(['customer_id']);
                abort_unless($inquiry && (empty($activity->customer_id) || $activity->customer_id === $inquiry->customer_id), 409,
                    'Activity Inquiry must match the selected customer.');
            } elseif ($activity->customer_id) {
                abort_unless(DB::table('customer_relationship_owner_assignments')->where('customer_id', $activity->customer_id)
                    ->exists(), 409, 'Activity Customer must have a Sales owner.');
            }
        }
        if ($activity->booking_activity_id) {
            $event = DB::table('booking_activities')->where('id', $activity->booking_activity_id)
                ->whereNull('deleted_at')->lockForUpdate()->first(['booking_id']);
            abort_unless($event && $event->booking_id
                && (! $activity->booking_id || $activity->booking_id === $event->booking_id), 409,
                'Activity booking event must match the selected booking.');
            if ($opportunity) $this->assertOpportunityBooking($event->booking_id, $opportunity, 409);
            else {
                abort_unless($activity->booking_id === $event->booking_id, 409, 'Activity booking event must match its Booking link.');
                $this->lockAvailableBooking($event->booking_id, 409);
            }
        }
    }

    public function createTask(array $data, string $actorUserId, ?array $authorizedProfileIds, ?array $sourceUserIds = null): SalesTask
    {
        // A follow-up should alert its owner by its deadline even when no earlier reminder was chosen.
        $data['remind_at'] ??= $data['due_at'];
        try {
            return DB::transaction(function () use ($data, $actorUserId, $authorizedProfileIds, $sourceUserIds) {
                $linkedSourceCount = count(array_filter([$data['opportunity_id'] ?? null, $data['booking_id'] ?? null, $data['inquiry_id'] ?? null]));
                abort_unless($linkedSourceCount === 1 || ($linkedSourceCount === 0 && ! empty($data['customer_id'])),
                    422, 'Choose one Opportunity, Booking, Inquiry, or owned Customer for this follow-up.');
                $this->assertSalesFeatureEnabled();
                $existing = SalesTask::query()->where('creation_idempotency_key', $data['creation_idempotency_key'])
                    ->lockForUpdate()->first();
                if ($existing) {
                    abort_unless($this->sameTaskRequest($existing, $data, $actorUserId), 409,
                        'The task creation key was already used for different task facts.');
                    $this->assertTaskLinks($existing);
                    if ($existing->opportunity_id) $this->syncOpportunityNextAction((string) $existing->opportunity_id, $actorUserId);
                    return $existing;
                }

                $owner = $this->lockActiveSalesProfile((string) $data['owner_sales_profile_id'], false);
                if (! empty($data['opportunity_id'])) {
                    $opportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($data['opportunity_id']);
                    abort_unless($opportunity->stage !== 'lost', 422, 'A lost opportunity cannot receive new follow-up tasks.');
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
                        $this->assertOpportunityBooking($data['booking_id'], $opportunity);
                    }
                } elseif (! empty($data['booking_id'])) {
                    abort_unless(empty($data['inquiry_id']), 422, 'Choose only one task source.');
                    $booking = $this->lockAvailableBooking($data['booking_id']);
                    abort_unless(empty($data['customer_id']) || $data['customer_id'] === $booking->customer_id, 422,
                        'Task Customer must match the selected Booking.');
                } elseif (! empty($data['inquiry_id'])) {
                    $inquiry = DB::table('inquiries')->where('id', $data['inquiry_id'] ?? null)
                        ->whereNull('deleted_at')->lockForUpdate()->first();
                    abort_unless($inquiry, 422, 'Select an available Inquiry.');
                    $sourceUserId = $inquiry->assigned_to ?: $inquiry->created_user_id;
                    abort_unless($sourceUserId && $sourceUserIds !== null && in_array($sourceUserId, $sourceUserIds, true),
                        403, 'Assign the Inquiry to an employee in your Sales scope before creating a follow-up.');
                    abort_unless(empty($data['customer_id']) || $data['customer_id'] === $inquiry->customer_id, 422,
                        'Task Customer must match the selected Inquiry.');
                    $data['customer_id'] ??= $inquiry->customer_id;
                } else {
                    $customer = DB::table('customers')->where('id', $data['customer_id'] ?? null)
                        ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                    abort_unless($customer, 422, 'Select an available Customer.');
                    $assignment = DB::table('customer_relationship_owner_assignments')->where('customer_id', $customer->id)
                        ->whereNull('ended_at')->lockForUpdate()->first();
                    abort_unless($assignment && $assignment->owner_sales_profile_id,
                        403, 'Assign this Customer to an employee in your Sales scope before creating a follow-up.');
                    $customerOwner = $this->lockActiveSalesProfile((string) $assignment->owner_sales_profile_id);
                    abort_unless($authorizedProfileIds === null || in_array($customerOwner->id, $authorizedProfileIds, true),
                        403, 'Customer is outside your authorized Sales scope.');
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
                if ($task->opportunity_id) $this->syncOpportunityNextAction((string) $task->opportunity_id, $actorUserId);
                return $task;
            });
        } catch (QueryException $exception) {
            return DB::transaction(function () use ($exception, $data, $actorUserId) {
                $this->assertSalesFeatureEnabled();
                $existing = SalesTask::query()->where('creation_idempotency_key', $data['creation_idempotency_key'])
                    ->lockForUpdate()->first();
                if (! $existing) throw $exception;
                abort_unless($this->sameTaskRequest($existing, $data, $actorUserId), 409,
                    'The task creation key was already used for different task facts.');
                $this->assertTaskLinks($existing);
                if ($existing->opportunity_id) $this->syncOpportunityNextAction((string) $existing->opportunity_id, $actorUserId);
                return $existing;
            });
        }
    }

    public function createRepeatBookingFollowup(Booking $booking, string $actorUserId): ?SalesTask
    {
        if (! $booking->customer_id || ! $booking->completed_at
            || ! $this->policySettings->featureEnabled('crm')) {
            return null;
        }

        $policy = $this->policySettings->current('repeat_booking_followup');
        if (! $policy?->repeat_booking_followup_enabled || ! $policy->repeat_booking_followup_days
            || ($policy->approved_at && $booking->completed_at->lt($policy->approved_at))) return null;

        $key = 'repeat-booking-follow-up:'.$booking->id;
        $existing = SalesTask::query()->where('creation_idempotency_key', $key)->first();
        if ($existing) return $existing;

        $ownerProfileIds = DB::table('customer_relationship_owner_assignments')->where('customer_id', $booking->customer_id)
            ->whereNull('ended_at')->where('effective_from', '<=', now())->pluck('owner_sales_profile_id')->unique()->values();
        if ($ownerProfileIds->count() !== 1) return null;
        $ownerProfileId = $ownerProfileIds->first();

        return $this->createTask([
            'owner_sales_profile_id' => (string) $ownerProfileId,
            'booking_id' => (string) $booking->id,
            'customer_id' => (string) $booking->customer_id,
            'creation_idempotency_key' => $key,
            'title' => 'Check in after completed trip',
            'description' => 'Ask whether another booking would be useful. Follow the company’s approved customer contact and consent rules.',
            'priority' => 'normal',
            'due_at' => $booking->completed_at->copy()->utc()->addDays((int) $policy->repeat_booking_followup_days)->toIso8601String(),
        ], $actorUserId, null);
    }

    private function sameTaskRequest(SalesTask $task, array $data, string $actorUserId): bool
    {
        foreach ([
            'opportunity_id', 'customer_id', 'inquiry_id', 'booking_id',
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
                $this->assertSalesFeatureEnabled();
                $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
                $this->assertTaskLinks($locked);
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
                if ($locked->opportunity_id) $this->syncOpportunityNextAction((string) $locked->opportunity_id, $actorUserId);
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
                $this->assertSalesFeatureEnabled();
                $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
                $this->assertTaskLinks($locked);
                $existing = SalesTaskEvent::query()->where('idempotency_key', $key)->first();
                if ($existing) {
                    abort_unless($existing->task_id === $locked->id && $existing->event_type === 'reassigned'
                        && $existing->to_owner_sales_profile_id === $newOwner->id && $existing->expected_version === $expectedVersion
                        && $existing->reason === $reason && $existing->actor_user_id === $actorUserId,
                        409, 'The task transfer key was already used for a different command.');
                    return $locked->refresh();
                }
                abort_unless($locked->state_version === $expectedVersion, 409, 'Task version changed; refresh before retrying.');
                $newOwner = $this->lockActiveSalesProfile((string) $newOwner->id, false);
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
            $this->assertSalesFeatureEnabled();
            $event = SalesTaskEvent::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if (! $event) throw $exception;
            $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
            $this->assertTaskLinks($locked);
            $assertReplay($event, $locked);
            return $locked->refresh();
        });
    }

    private function assertTaskLinks(SalesTask $task): void
    {
        $owner = SalesProfile::query()->lockForUpdate()->find($task->owner_sales_profile_id);
        abort_unless($owner, 409, 'Task owner must exist.');
        $staff = DB::table('staff')->where('id', $owner->staff_id)
            ->lockForUpdate()->first(['id']);
        abort_unless($staff, 409, 'Task owner Staff must exist.');
        if (! $task->opportunity_id) {
            if ($task->inquiry_id) {
                abort_unless(! $task->booking_id, 409, 'An Inquiry task cannot also link to a Booking.');
                $inquiry = DB::table('inquiries')->where('id', $task->inquiry_id)->whereNull('deleted_at')->lockForUpdate()->first();
                abort_unless($inquiry, 409, 'Task Inquiry is no longer available.');
                $sourceUserId = $inquiry->assigned_to ?: $inquiry->created_user_id;
                abort_unless($sourceUserId && DB::table('staff')->where('user_id', $sourceUserId)->exists(), 409,
                    'Task Inquiry must have an active Staff owner or creator.');
                abort_unless(empty($task->customer_id) || $task->customer_id === $inquiry->customer_id, 409,
                    'Task Customer must match the selected Inquiry.');
                return;
            }
            if ($task->booking_id) {
                $booking = $this->lockAvailableBooking($task->booking_id, 409);
                abort_unless(empty($task->customer_id) || $task->customer_id === $booking->customer_id, 409, 'Task Customer must match the selected Booking.');
                return;
            }
            abort_unless($task->customer_id && DB::table('customer_relationship_owner_assignments')
                ->where('customer_id', $task->customer_id)->exists(), 409, 'A customer-only task must stay linked to its owned Customer.');
            return;
        }

        $opportunity = SalesOpportunity::query()->lockForUpdate()->find($task->opportunity_id);
        abort_unless($opportunity, 409, 'Task Opportunity must exist.');
        foreach ([
            'customer_id' => 'customer_id', 'inquiry_id' => 'inquiry_id',
        ] as $taskField => $opportunityField) {
            abort_unless(empty($task->{$taskField}) || $task->{$taskField} === $opportunity->{$opportunityField}, 409,
                'Task references must match the linked Opportunity.');
        }
        if ($task->booking_id) {
            $this->assertOpportunityBooking($task->booking_id, $opportunity, 409, true);
        }
    }

    private function syncOpportunityNextAction(string $opportunityId, string $actorUserId): void
    {
        $opportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunityId);
        $task = SalesTask::query()->where('opportunity_id', $opportunity->id)->whereIn('status', ['open', 'in_progress'])
            ->orderBy('due_at')->orderBy('created_at')->orderBy('id')->first(['title', 'due_at']);
        $nextAction = $task?->title;
        $nextActionAt = $task?->due_at;
        $currentDueAt = $opportunity->next_action_at?->utc()->format('Y-m-d H:i:s');
        $taskDueAt = $nextActionAt?->utc()->format('Y-m-d H:i:s');
        if ($opportunity->next_action === $nextAction && $currentDueAt === $taskDueAt) return;

        $opportunity->update([
            'next_action' => $nextAction,
            'next_action_at' => $nextActionAt,
            'updated_user_id' => $actorUserId,
        ]);
    }

    private function lockAvailableBooking(string $bookingId, int $status = 422): Booking
    {
        $booking = Booking::query()->whereKey($bookingId)->whereNull('deleted_at')->lockForUpdate()->first();
        abort_unless($booking, $status, 'Select an available Booking.');
        return $booking;
    }

    private function lockActiveSalesProfile(string $profileId, bool $requireConfigured = true): SalesProfile
    {
        $query = SalesProfile::query()->activeAt(now());
        if ($requireConfigured) $query->configured();
        $owner = $query->lockForUpdate()->findOrFail($profileId);
        $staff = DB::table('staff')->where('id', $owner->staff_id)
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
        $snapshot = array_merge($opportunity->only([
            'stage', 'owner_sales_profile_id', 'expected_value_lkr', 'probability_percent',
            'expected_close_date', 'state_version', 'workflow_contact_stage_setting_id',
            'workflow_qualification_stage_setting_id', 'workflow_quotation_stage_setting_id',
            'workflow_negotiation_stage_setting_id',
        ]), $snapshotFacts);
        $snapshot['stage_labels'] = $this->opportunityStageLabels($opportunity);
        SalesOpportunityStageEvent::create([
            'opportunity_id' => $opportunity->id, 'from_stage' => $from, 'to_stage' => $to,
            'from_owner_sales_profile_id' => $fromOwner ?? $opportunity->owner_sales_profile_id,
            'to_owner_sales_profile_id' => $opportunity->owner_sales_profile_id, 'reason_code' => $reasonCode,
            'reason' => $reason, 'idempotency_key' => $key, 'actor_user_id' => $actor, 'occurred_at' => now(),
            'snapshot' => $snapshot,
        ]);
        $this->events->record('sales', 'opportunity', $opportunity->id, 'sales.opportunity.'.($eventName ?? $to), $opportunity->state_version, 1, ['from_stage' => $from, 'to_stage' => $to] + $snapshotFacts, now(), $key);
    }

    private function opportunityStageLabels(SalesOpportunity $opportunity): array
    {
        $labels = SalesPolicySettingsService::STAGE_LABEL_DEFAULTS;
        if (! Schema::hasColumn('sales_feature_settings', 'stage_label')) return $labels;
        $mappings = [
            'contacted' => ['workflow_contact_stage_setting_id', 'crm_contact_stage'],
            'qualified' => ['workflow_qualification_stage_setting_id', 'crm_qualification_stage'],
            'quotation' => ['workflow_quotation_stage_setting_id', 'crm_quotation_stage'],
            'negotiation' => ['workflow_negotiation_stage_setting_id', 'crm_negotiation_stage'],
        ];
        $ids = collect($mappings)->map(fn ($mapping) => $opportunity->{$mapping[0]})->filter()->unique()->values();
        if ($ids->isEmpty()) return $labels;
        $settings = DB::table('sales_feature_settings')->whereIn('id', $ids)->get(['id', 'feature_key', 'stage_label'])->keyBy('id');
        foreach ($mappings as $stage => [$column, $feature]) {
            $setting = $settings->get($opportunity->{$column});
            if ($setting && $setting->feature_key === $feature && trim((string) $setting->stage_label) !== '') {
                $labels[$stage] = trim((string) $setting->stage_label);
            }
        }

        return $labels;
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
        $phoneCallingCode = null;
        $matches = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')->select('customers.id')
            ->whereNull('customers.deleted_at')->whereNull('users.deleted_at')
            ->where(function ($query) use ($data, $phoneCallingCode) {
                if (! empty($data['prospect_email'])) $query->orWhereRaw('LOWER(TRIM(users.email)) = ?', [strtolower(trim($data['prospect_email']))]);
                if (! empty($data['prospect_phone'])) $query->orWhereRaw(
                    PhoneNumberMatch::sqlExpression('users.phone', $phoneCallingCode).' = ?',
                    [PhoneNumberMatch::normalized(trim($data['prospect_phone']), $phoneCallingCode)],
                );
            });
        $matches = $matches->limit(10)->pluck('customers.id');
        abort_if($matches->isNotEmpty(), 409, 'Potential existing customer matches require explicit duplicate review: '.$matches->implode(','));
    }

    private function assertSalesFeatureEnabled(): void
    {
        abort_unless($this->policySettings->featureEnabled('crm'), 409,
            'Sales CRM writes are not activated for this installation.');
    }
}
