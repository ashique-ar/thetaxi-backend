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
        $this->assertEnabled((string) $data['company_id']);
        return DB::transaction(function () use ($data, $actorUserId, $sourceUserIds) {
            abort_if(! empty($data['inquiry_id']) && ! empty($data['source_phone_call_id']), 422,
                'An opportunity can have only one managed source record.');
            $owner = $this->lockActiveSalesProfile((string) $data['owner_sales_profile_id'], (string) $data['company_id']);
            if (! empty($data['inquiry_id'])) {
                $inquiry = Inquiry::query()->lockForUpdate()->findOrFail($data['inquiry_id']);
                abort_unless($sourceUserIds === null || in_array($inquiry->assigned_to, $sourceUserIds, true)
                    || in_array($inquiry->created_user_id, $sourceUserIds, true), 403,
                    'The Inquiry source is outside your authorised Sales scope.');
                $data['source'] = $inquiry->source ?: 'inquiry';
                $data['customer_id'] = $data['customer_id'] ?? $inquiry->customer_id;
                $data['prospect_name'] = $data['prospect_name'] ?? $inquiry->name;
                $data['prospect_email'] = $data['prospect_email'] ?? $inquiry->email;
                $data['prospect_phone'] = $data['prospect_phone'] ?? $inquiry->phone;
            } elseif (! empty($data['source_phone_call_id'])) {
                $phoneCall = PhoneCall::query()->lockForUpdate()->findOrFail($data['source_phone_call_id']);
                abort_unless($sourceUserIds === null || in_array($phoneCall->created_user_id, $sourceUserIds, true), 403,
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
        return DB::transaction(function () use ($opportunity, $toStage, $expectedVersion, $reasonCode, $reason, $key, $actorUserId) {
            $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            if ($existing = SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->first()) return $locked->refresh();
            $this->assertEnabled((string) $locked->company_id);
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
    }

    public function transfer(SalesOpportunity $opportunity, SalesProfile $newOwner, int $expectedVersion, string $reason, string $key, string $actorUserId): SalesOpportunity
    {
        return DB::transaction(function () use ($opportunity, $newOwner, $expectedVersion, $reason, $key, $actorUserId) {
            $locked = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            if (SalesOpportunityStageEvent::query()->where('idempotency_key', $key)->exists()) return $locked->refresh();
            $this->assertEnabled((string) $locked->company_id);
            abort_unless($locked->state_version === $expectedVersion, 409, 'Opportunity version changed; refresh before retrying.');
            $newOwner = $this->lockActiveSalesProfile((string) $newOwner->id, (string) $locked->company_id);
            abort_unless($locked->company_id === $newOwner->company_id, 422, 'Opportunity transfers cannot cross legal entities.');
            $oldOwner = $locked->owner_sales_profile_id;
            $locked->update(['owner_sales_profile_id' => $newOwner->id, 'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId]);
            $this->stageEvent($locked, $locked->stage, $locked->stage, 'owner_transfer', $reason, $key, $actorUserId, $oldOwner);
            return $locked->refresh();
        });
    }

    public function markWonFromBooking(Booking $booking, ?string $actorUserId = null): ?SalesOpportunity
    {
        if (! $booking->sales_opportunity_id) return null;
        return DB::transaction(function () use ($booking, $actorUserId) {
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
        return DB::transaction(function () use ($opportunity, $booking, $key, $actorUserId) {
            $lockedOpportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $this->assertEnabled((string) $lockedOpportunity->company_id);
            $this->lockActiveSalesProfile((string) $lockedOpportunity->owner_sales_profile_id, (string) $lockedOpportunity->company_id);
            $bookingStaff = $lockedBooking->commission_owner_staff_id
                ? DB::table('staff')->where('id', $lockedBooking->commission_owner_staff_id)->first()
                : DB::table('staff')->where('user_id', $lockedBooking->created_user_id)->first();
            abort_unless($bookingStaff && $bookingStaff->company_id === $lockedOpportunity->company_id, 422,
                'The draft booking must have an explicit commission owner or creator in the same Sales legal entity.');
            $bookingOwnerProfileId = SalesProfile::query()->where('staff_id', $bookingStaff->id)->activeAt(now())->value('id');
            abort_unless($bookingOwnerProfileId === $lockedOpportunity->owner_sales_profile_id, 422,
                'The draft booking acquisition owner must match the opportunity owner before linkage.');
            abort_if(in_array($lockedOpportunity->stage, ['won', 'lost'], true), 422, 'Only an open opportunity can be linked to a booking.');
            abort_if($lockedBooking->confirmed || $lockedBooking->confirmed_at || $lockedBooking->status === 'confirmed', 422, 'Link the opportunity before booking confirmation.');
            abort_if(DB::table('sales_booking_attributions')->where('booking_id', $lockedBooking->id)->exists(), 422, 'The booking already has frozen Sales attribution.');
            abort_if($lockedBooking->sales_opportunity_id && $lockedBooking->sales_opportunity_id !== $lockedOpportunity->id, 409, 'The booking is linked to another opportunity.');
            abort_if($lockedOpportunity->won_booking_id && $lockedOpportunity->won_booking_id !== $lockedBooking->id, 409, 'The opportunity is already linked to another winning booking.');
            if ($lockedOpportunity->customer_id && $lockedBooking->customer_id) {
                abort_unless($lockedOpportunity->customer_id === $lockedBooking->customer_id, 422, 'Opportunity and booking Customers must match.');
            }
            $lockedBooking->update(['sales_opportunity_id' => $lockedOpportunity->id, 'updated_user_id' => $actorUserId]);
            $this->events->record('sales', $lockedOpportunity->company_id, 'opportunity', $lockedOpportunity->id, 'sales.opportunity.booking_linked', $lockedOpportunity->state_version, 1, ['booking_id' => $lockedBooking->id], now(), $key);
            return $lockedOpportunity->refresh();
        });
    }

    public function recordActivity(array $data, string $actorUserId, ?array $authorizedProfileIds): SalesActivity
    {
        $this->assertEnabled((string) $data['company_id']);
        try {
            return DB::transaction(function () use ($data, $actorUserId, $authorizedProfileIds) {
                // ponytail: company lock serializes the source-key check; use per-source locks if throughput requires it.
                DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->firstOrFail();
                $profile = SalesProfile::query()->lockForUpdate()->findOrFail($data['sales_profile_id']);
                abort_unless($profile->company_id === $data['company_id'], 422, 'Activity profile and legal entity must match.');
                $opportunity = SalesOpportunity::query()->lockForUpdate()->findOrFail($data['opportunity_id']);
                abort_unless($opportunity->company_id === $profile->company_id, 422, 'Activity opportunity and Sales Profile must belong to the same legal entity.');
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
                    abort_unless($opportunity->won_booking_id === $data['booking_id']
                        || DB::table('bookings')->where('id', $data['booking_id'])->whereNull('deleted_at')->where('sales_opportunity_id', $opportunity->id)->exists(),
                        422, 'Activity booking must be explicitly linked to the selected Opportunity.');
                    $attribution = DB::table('sales_booking_attributions')->where('booking_id', $data['booking_id'])->first();
                    abort_unless(! $attribution || $attribution->company_id === $profile->company_id, 422,
                        'Activity booking attribution must belong to the same legal entity.');
                }
                if (! empty($data['booking_activity_id'])) {
                    $bookingId = DB::table('booking_activities')->where('id', $data['booking_activity_id'])
                        ->whereNull('deleted_at')->value('booking_id');
                    abort_unless($bookingId && ($opportunity->won_booking_id === $bookingId
                        || DB::table('bookings')->where('id', $bookingId)->whereNull('deleted_at')->where('sales_opportunity_id', $opportunity->id)->exists()),
                        422, 'Activity booking event must belong to the selected Opportunity.');
                    $attribution = DB::table('sales_booking_attributions')->where('booking_id', $bookingId)->first();
                    abort_unless(! $attribution || $attribution->company_id === $profile->company_id, 422,
                        'Activity booking event attribution must belong to the same legal entity.');
                }

                $existing = SalesActivity::query()->where('source_system', $data['source_system'])
                    ->where('source_reference', $data['source_reference'])->first();
                if ($existing) {
                    abort_unless($this->sameActivityRequest($existing, $data, $actorUserId), 409,
                        'The activity source reference was already used for different activity facts.');
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
            $existing = SalesActivity::query()->where('source_system', $data['source_system'])
                ->where('source_reference', $data['source_reference'])->first();
            if (! $existing) throw $exception;
            abort_unless($this->sameActivityRequest($existing, $data, $actorUserId), 409,
                'The activity source reference was already used for different activity facts.');
            return $existing;
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

    public function createTask(array $data, string $actorUserId, ?array $authorizedProfileIds): SalesTask
    {
        try {
            return DB::transaction(function () use ($data, $actorUserId, $authorizedProfileIds) {
                // ponytail: company lock serializes task create-key checks; use per-key locks if throughput requires it.
                DB::table('companies')->where('id', $data['company_id'])->lockForUpdate()->firstOrFail();
                $existing = SalesTask::query()->where('creation_idempotency_key', $data['creation_idempotency_key'])->first();
                if ($existing) {
                    abort_unless($this->sameTaskRequest($existing, $data, $actorUserId), 409,
                        'The task creation key was already used for different task facts.');
                    return $existing;
                }

                $this->assertEnabled((string) $data['company_id']);
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
                    abort_unless($opportunity->won_booking_id === $data['booking_id']
                        || DB::table('bookings')->where('id', $data['booking_id'])->whereNull('deleted_at')
                            ->where('sales_opportunity_id', $opportunity->id)->exists(),
                        422, 'Task booking must be explicitly linked to the selected Opportunity.');
                    $attribution = DB::table('sales_booking_attributions')->where('booking_id', $data['booking_id'])->first();
                    abort_unless(! $attribution || $attribution->company_id === $owner->company_id, 422,
                        'Task booking attribution must belong to the same legal entity.');
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
            $existing = SalesTask::query()->where('creation_idempotency_key', $data['creation_idempotency_key'])->first();
            if (! $existing) throw $exception;
            abort_unless($this->sameTaskRequest($existing, $data, $actorUserId), 409,
                'The task creation key was already used for different task facts.');
            return $existing;
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
        return DB::transaction(function () use ($task, $data, $actorUserId) {
            $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
            $existing = SalesTaskEvent::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->task_id === $locked->id && $existing->event_type === 'status_changed'
                    && $existing->to_status === $data['to_status'] && $existing->expected_version === $data['expected_version']
                    && $existing->reason === ($data['reason'] ?? null) && $existing->actor_user_id === $actorUserId,
                    409, 'The task transition key was already used for a different command.');
                return $locked->refresh();
            }
            $this->assertEnabled((string) $locked->company_id);
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
    }

    public function transferTask(SalesTask $task, SalesProfile $newOwner, int $expectedVersion, string $reason, string $key, string $actorUserId): SalesTask
    {
        return DB::transaction(function () use ($task, $newOwner, $expectedVersion, $reason, $key, $actorUserId) {
            $locked = SalesTask::query()->lockForUpdate()->findOrFail($task->id);
            $existing = SalesTaskEvent::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless($existing->task_id === $locked->id && $existing->event_type === 'reassigned'
                    && $existing->to_owner_sales_profile_id === $newOwner->id && $existing->expected_version === $expectedVersion
                    && $existing->reason === $reason && $existing->actor_user_id === $actorUserId,
                    409, 'The task transfer key was already used for a different command.');
                return $locked->refresh();
            }
            $this->assertEnabled((string) $locked->company_id);
            abort_unless($locked->state_version === $expectedVersion, 409, 'Task version changed; refresh before retrying.');
            $newOwner = $this->lockActiveSalesProfile((string) $newOwner->id, (string) $locked->company_id, false);
            $oldOwner = $locked->owner_sales_profile_id;
            $locked->update(['owner_sales_profile_id' => $newOwner->id, 'state_version' => $locked->state_version + 1, 'updated_user_id' => $actorUserId]);
            $this->taskEvent($locked, 'reassigned', $locked->status, $locked->status, $oldOwner,
                $newOwner->id, $reason, $key, $actorUserId, $expectedVersion);
            return $locked->refresh();
        });
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

    private function stageEvent(SalesOpportunity $opportunity, ?string $from, string $to, ?string $reasonCode, ?string $reason, string $key, string $actor, ?string $fromOwner = null): void
    {
        SalesOpportunityStageEvent::create([
            'opportunity_id' => $opportunity->id, 'from_stage' => $from, 'to_stage' => $to,
            'from_owner_sales_profile_id' => $fromOwner ?? $opportunity->owner_sales_profile_id,
            'to_owner_sales_profile_id' => $opportunity->owner_sales_profile_id, 'reason_code' => $reasonCode,
            'reason' => $reason, 'idempotency_key' => $key, 'actor_user_id' => $actor, 'occurred_at' => now(),
            'snapshot' => $opportunity->only(['stage', 'owner_sales_profile_id', 'expected_value_lkr', 'probability_percent', 'expected_close_date', 'state_version']),
        ]);
        $this->events->record('sales', $opportunity->company_id, 'opportunity', $opportunity->id, 'sales.opportunity.'.$to, $opportunity->state_version, 1, ['from_stage' => $from, 'to_stage' => $to], now(), $key);
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
}
