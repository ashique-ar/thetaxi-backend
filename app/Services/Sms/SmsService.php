<?php

namespace App\Services\Sms;

use App\Services\Sms\Exceptions\SmsBlackoutException;
use App\Jobs\LaunchSmsCampaignJob;
use App\Jobs\SendSmsMessageJob;
use App\Models\Customer;
use App\Models\Sms\SmsCampaign;
use App\Models\Sms\SmsMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SmsService
{
    public function __construct(
        private SmsProviderManager $providerManager,
        private SmsSettingsService $settingsService,
        private SmsSegmentCalculator $segmentCalculator = new SmsSegmentCalculator()
    ) {}

    public function getOverview(): array
    {
        $since = now()->subDays(30);

        return [
            'messages' => [
                'total' => SmsMessage::query()->count(),
                'last_30_days' => SmsMessage::query()->where('created_at', '>=', $since)->count(),
                'queued' => SmsMessage::query()->where('status', 'queued')->count(),
                'sent' => SmsMessage::query()->where('status', 'sent')->count(),
                'delivered' => SmsMessage::query()->where('status', 'delivered')->count(),
                'failed' => SmsMessage::query()->where('status', 'failed')->count(),
            ],
            'campaigns' => [
                'total' => SmsCampaign::query()->count(),
                'draft' => SmsCampaign::query()->where('status', 'draft')->count(),
                'scheduled' => SmsCampaign::query()->where('status', 'scheduled')->count(),
                'processing' => SmsCampaign::query()->where('status', 'processing')->count(),
                'completed' => SmsCampaign::query()->where('status', 'completed')->count(),
            ],
            'recent_messages' => SmsMessage::query()->with(['booking:id,booking_number', 'bookingItem:id,booking_id,trip_number'])->latest()->limit(10)->get(),
            'recent_campaigns' => SmsCampaign::query()->latest()->limit(10)->get(),
            'health' => $this->getOperationalHealth(),
        ];
    }

    public function getOperationalHealth(): array
    {
        $queueAgeMinutes = (int) config('sms.health.queue_age_minutes', 10);
        $callbackAgeMinutes = (int) config('sms.health.callback_age_minutes', 30);
        $failureWindowMinutes = (int) config('sms.health.failure_window_minutes', 60);
        $failureThreshold = (int) config('sms.health.failure_count', 5);
        $bookingMessageThreshold = (int) config('sms.health.messages_per_booking', 5);

        $oldestQueuedAt = SmsMessage::query()
            ->where('status', 'queued')
            ->min('queued_at');
        $staleQueued = SmsMessage::query()
            ->where('status', 'queued')
            ->where('queued_at', '<=', now()->subMinutes($queueAgeMinutes))
            ->count();
        $recentFailures = SmsMessage::query()
            ->where('status', 'failed')
            ->where('failed_at', '>=', now()->subMinutes($failureWindowMinutes))
            ->count();
        $staleCallbacks = SmsMessage::query()
            ->whereIn('status', ['sent', 'provider_accepted'])
            ->whereNull('delivered_at')
            ->where('sent_at', '<=', now()->subMinutes($callbackAgeMinutes))
            ->count();
        $abnormalBookings = SmsMessage::query()
            ->whereNotNull('booking_id')
            ->where('created_at', '>=', now()->subDay())
            ->whereNotIn('status', ['dry_run', 'cancelled'])
            ->select('booking_id')
            ->groupBy('booking_id')
            ->havingRaw('COUNT(*) > ?', [$bookingMessageThreshold])
            ->get()
            ->count();

        $alerts = [];
        if ($staleQueued > 0) {
            $alerts[] = ['key' => 'queue_age', 'severity' => 'critical', 'count' => $staleQueued, 'message' => "{$staleQueued} SMS message(s) have been queued for more than {$queueAgeMinutes} minutes."];
        }
        if ($recentFailures >= $failureThreshold) {
            $alerts[] = ['key' => 'failure_spike', 'severity' => 'critical', 'count' => $recentFailures, 'message' => "{$recentFailures} SMS failures occurred within the last {$failureWindowMinutes} minutes."];
        }
        if ($staleCallbacks > 0) {
            $alerts[] = ['key' => 'callback_stale', 'severity' => 'warning', 'count' => $staleCallbacks, 'message' => "{$staleCallbacks} sent SMS message(s) have no delivery callback after {$callbackAgeMinutes} minutes."];
        }
        if ($abnormalBookings > 0) {
            $alerts[] = ['key' => 'booking_volume', 'severity' => 'warning', 'count' => $abnormalBookings, 'message' => "{$abnormalBookings} booking(s) exceeded {$bookingMessageThreshold} non-dry-run SMS records in the last 24 hours."];
        }

        return [
            'status' => $alerts === [] ? 'healthy' : (collect($alerts)->contains('severity', 'critical') ? 'critical' : 'warning'),
            'checked_at' => now()->toIso8601String(),
            'oldest_queued_at' => $oldestQueuedAt,
            'metrics' => [
                'stale_queued' => $staleQueued,
                'recent_failures' => $recentFailures,
                'stale_callbacks' => $staleCallbacks,
                'abnormal_bookings' => $abnormalBookings,
            ],
            'alerts' => $alerts,
        ];
    }

    public function queueSingleMessage(array $payload): SmsMessage
    {
        if (!$this->settingsService->isEnabled()) {
            throw new RuntimeException('SMS is disabled in settings');
        }

        $normalizedRecipient = $this->normalizeRecipient($payload['recipient'] ?? '');
        if (!$normalizedRecipient) {
            throw new RuntimeException('Invalid recipient number');
        }

        $settings = $this->settingsService->getSettings();
        $queueEnabled = $settings['queue_enabled'];
        $dryRun = in_array(($payload['source'] ?? 'manual'), ['automation', 'fallback'], true)
            && !empty($settings['dry_run']);
        $segmentFacts = $this->segmentCalculator->calculate(trim((string) ($payload['message'] ?? '')));
        $unitCost = (float) ($settings['cost_per_segment'] ?? 0);

        $attributes = [
            'campaign_id' => $payload['campaign_id'] ?? null,
            'provider' => $this->settingsService->getActiveProvider(),
            'channel' => $payload['channel'] ?? 'single',
            'source' => $payload['source'] ?? 'manual',
            'context_type' => $payload['context_type'] ?? null,
            'context_id' => $payload['context_id'] ?? null,
            'booking_id' => $payload['booking_id'] ?? null,
            'booking_item_id' => $payload['booking_item_id'] ?? null,
            'driver_assignment_id' => $payload['driver_assignment_id'] ?? null,
            'template_key' => $payload['template_key'] ?? null,
            'event_key' => $payload['event_key'] ?? null,
            'idempotency_key' => $payload['idempotency_key'] ?? null,
            'recipient' => (string) ($payload['recipient'] ?? ''),
            'normalized_recipient' => $normalizedRecipient,
            'sender_mask' => $this->resolveSenderMask($payload['sender_mask'] ?? null),
            'message' => trim((string) ($payload['message'] ?? '')),
            'segments' => $segmentFacts['segments'],
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost * $segmentFacts['segments'],
            'cost_currency' => $settings['cost_currency'] ?? 'LKR',
            'status' => $dryRun ? 'dry_run' : ($queueEnabled ? 'queued' : 'pending'),
            'scheduled_at' => isset($payload['scheduled_at']) && $payload['scheduled_at']
                ? Carbon::parse($payload['scheduled_at'])
                : null,
            'triggered_at' => isset($payload['triggered_at']) && $payload['triggered_at']
                ? Carbon::parse($payload['triggered_at'])
                : now(),
            'queued_at' => $dryRun ? null : now(),
            'meta' => array_merge($payload['meta'] ?? [], [
                'dry_run' => $dryRun,
                'encoding' => $segmentFacts['encoding'],
                'characters' => $segmentFacts['characters'],
            ]),
        ];

        $idempotencyKey = $attributes['idempotency_key'];
        $message = $idempotencyKey
            ? SmsMessage::firstOrCreate(['idempotency_key' => $idempotencyKey], $attributes)
            : SmsMessage::create($attributes);

        if (!$message->wasRecentlyCreated) {
            return $message;
        }

        $wasRecentlyCreated = $message->wasRecentlyCreated;

        if ($dryRun) {
            $result = $message->fresh();
            $result->wasRecentlyCreated = $wasRecentlyCreated;
            return $result;
        }

        if ($queueEnabled) {
            SendSmsMessageJob::dispatch($message->id)
                ->onQueue($this->queueForMessage($message))
                ->afterCommit();
        } else {
            DB::afterCommit(function () use ($message): void {
                $freshMessage = SmsMessage::query()->find($message->id);
                if ($freshMessage) {
                    $this->processQueuedMessage($freshMessage);
                }
            });
        }

        $result = $message->fresh();
        $result->wasRecentlyCreated = $wasRecentlyCreated;
        return $result;
    }

    public function queueBulkMessages(array $payload): Collection
    {
        $recipients = $this->normalizeRecipients($payload['recipients'] ?? []);

        return collect($recipients)->map(function (string $recipient) use ($payload) {
            return $this->queueSingleMessage([
                ...$payload,
                'recipient' => $recipient,
                'channel' => $payload['channel'] ?? 'bulk',
            ]);
        });
    }

    public function createCampaign(array $payload): SmsCampaign
    {
        $audienceType = $payload['audience_type'] ?? 'manual';
        $recipients = $this->resolveAudienceRecipients(
            $audienceType,
            $payload['audience_filters'] ?? [],
            $payload['recipients'] ?? []
        );

        $campaign = SmsCampaign::create([
            'name' => trim((string) ($payload['name'] ?? 'Untitled campaign')),
            'message' => trim((string) ($payload['message'] ?? '')),
            'provider' => $this->settingsService->getActiveProvider(),
            'sender_mask' => $this->resolveSenderMask($payload['sender_mask'] ?? null),
            'status' => !empty($payload['scheduled_at']) ? 'scheduled' : 'draft',
            'audience_type' => $audienceType,
            'audience_filters' => $payload['audience_filters'] ?? null,
            'recipient_snapshot' => array_values($recipients),
            'total_recipients' => count($recipients),
            'scheduled_at' => !empty($payload['scheduled_at'])
                ? Carbon::parse($payload['scheduled_at'])
                : null,
            'meta' => array_merge($payload['meta'] ?? [], [
                'consent_basis' => $audienceType === 'customers'
                    ? 'customer_marketing_consent'
                    : 'manual_operator_attestation',
                'consent_snapshot_at' => now()->toIso8601String(),
            ]),
        ]);

        if (($payload['launch_now'] ?? false) === true) {
            $this->scheduleCampaignLaunch($campaign);
        }

        return $campaign->fresh();
    }

    public function scheduleCampaignLaunch(SmsCampaign $campaign): void
    {
        $campaign->update([
            'status' => $campaign->scheduled_at ? 'scheduled' : 'processing',
        ]);

        $job = LaunchSmsCampaignJob::dispatch($campaign->id);
        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            $job->delay($campaign->scheduled_at);
        }
    }

    public function launchCampaign(SmsCampaign $campaign): SmsCampaign
    {
        if ($campaign->status === 'completed' || $campaign->messages()->exists()) {
            return $campaign;
        }

        $recipients = $this->normalizeRecipients($campaign->recipient_snapshot ?? []);
        if ($campaign->audience_type === 'customers') {
            $currentlyConsented = $this->resolveAudienceRecipients(
                'customers',
                $campaign->audience_filters ?? []
            );
            $recipients = array_values(array_intersect($recipients, $currentlyConsented));
        }
        if ($recipients === []) {
            $campaign->update([
                'status' => 'failed',
                'completed_at' => now(),
                'meta' => array_merge($campaign->meta ?? [], [
                    'error' => 'No consented recipients available for campaign',
                    'consent_revalidated_at' => now()->toIso8601String(),
                ]),
            ]);
            return $campaign->fresh();
        }

        $campaign->update([
            'status' => 'processing',
            'launched_at' => now(),
            'queued_recipients' => count($recipients),
            'total_recipients' => count($recipients),
            'recipient_snapshot' => $recipients,
            'meta' => array_merge($campaign->meta ?? [], [
                'consent_revalidated_at' => now()->toIso8601String(),
            ]),
        ]);

        foreach ($recipients as $recipient) {
            $message = SmsMessage::create([
                'campaign_id' => $campaign->id,
                'provider' => $campaign->provider,
                'channel' => 'campaign',
                'recipient' => $recipient,
                'normalized_recipient' => $recipient,
                'sender_mask' => $campaign->sender_mask,
                'message' => $campaign->message,
                'status' => 'queued',
                'queued_at' => now(),
                'meta' => [
                    'campaign_name' => $campaign->name,
                    'audience_type' => $campaign->audience_type,
                ],
            ]);

            if ($this->settingsService->getSettings()['queue_enabled']) {
                SendSmsMessageJob::dispatch($message->id)->onQueue('sms-campaigns');
            } else {
                $this->processQueuedMessage($message);
            }
        }

        return $campaign->fresh();
    }

    public function processQueuedMessage(SmsMessage $message): SmsMessage
    {
        $claimed = SmsMessage::query()
            ->whereKey($message->id)
            ->whereIn('status', ['queued', 'pending', 'failed'])
            ->update([
            'status' => 'processing',
            'provider_status' => 'processing',
            'provider_status_at' => now(),
            'processing_at' => now(),
            'attempts' => (int) $message->attempts + 1,
        ]);
        if ($claimed !== 1) {
            return $message->fresh();
        }
        $message = $message->fresh();

        try {
            $response = $this->providerManager->active()->sendSingle([
                'recipient' => $message->normalized_recipient,
                'message' => $message->message,
                'sender_mask' => $message->sender_mask,
                'meta' => $message->meta ?? [],
            ]);

            $message->update([
                'status' => 'sent',
                'provider_status' => 'sent',
                'provider_status_at' => now(),
                'provider_message_id' => $response['provider_message_id'] ?? null,
                'provider_campaign_id' => $response['provider_campaign_id'] ?? null,
                'provider_transaction_id' => $response['transaction_id'] ?? null,
                'provider_response' => $response['raw'] ?? $response,
                'sent_at' => now(),
                'error_message' => null,
            ]);
            Log::info('SMS sent', [
                'sms_message_id' => $message->id,
                'campaign_id' => $message->campaign_id,
                'channel' => $message->channel,
                'provider' => $message->provider,
                'provider_message_id' => $response['provider_message_id'] ?? null,
                'attempt' => $message->attempts,
            ]);
        } catch (SmsBlackoutException $exception) {
            $message->update([
                'status' => 'queued',
                'provider_status' => 'blackout_deferred',
                'provider_status_at' => now(),
                'error_message' => $exception->getMessage(),
                'failed_at' => null,
                'processing_at' => null,
                'meta' => array_merge($message->meta ?? [], [
                    'provider_retry_at' => $exception->retryAt->format(DATE_ATOM),
                ]),
            ]);

            Log::notice('SMS deferred by blackout window', [
                'sms_message_id' => $message->id,
                'retry_at' => $exception->retryAt->format(DATE_ATOM),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            $message->update([
                'status' => 'failed',
                'provider_status' => 'failed',
                'provider_status_at' => now(),
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ]);
            Log::error('SMS delivery failed', [
                'sms_message_id' => $message->id,
                'campaign_id' => $message->campaign_id,
                'channel' => $message->channel,
                'provider' => $message->provider,
                'attempt' => $message->attempts,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($message->campaign_id) {
            $this->refreshCampaignStats($message->campaign_id);
        }

        return $message->fresh();
    }

    public function markDelivery(array $payload): array
    {
        $transactionId = $payload['transaction_id'] ?? $payload['transactionId'] ?? null;
        $messageId = $payload['message_id'] ?? $payload['messageId'] ?? null;
        if (!$transactionId && !$messageId) {
            throw new RuntimeException('Delivery callback requires a provider transaction or message id');
        }

        $query = SmsMessage::query()
            ->when($transactionId, fn ($q) => $q->where('provider_transaction_id', $transactionId))
            ->when($messageId, fn ($q) => $q->where('provider_message_id', $messageId));
        if ((clone $query)->count() > 1) {
            throw new RuntimeException('Delivery callback matched more than one SMS message');
        }
        $message = $query->first();
        if (!$message) {
            return ['updated' => false];
        }

        $normalizedStatus = $this->normalizeProviderStatus((string) ($payload['status'] ?? $payload['delivery_status'] ?? ''));

        $message->update([
            'status' => $normalizedStatus,
            'provider_status' => $normalizedStatus,
            'provider_status_at' => now(),
            'delivered_at' => $normalizedStatus === 'delivered' ? now() : $message->delivered_at,
            'failed_at' => $normalizedStatus === 'failed' ? now() : $message->failed_at,
            'provider_response' => array_merge($message->provider_response ?? [], [
                'delivery_callback' => $this->redactProviderPayload($payload),
            ]),
        ]);

        Log::info('SMS delivery status updated', [
            'sms_message_id' => $message->id,
            'status' => $normalizedStatus,
            'provider' => $message->provider,
        ]);

        if ($message->campaign_id) {
            $this->refreshCampaignStats($message->campaign_id);
        }

        return ['updated' => true, 'message_id' => $message->id];
    }

    public function retryFailedMessage(SmsMessage $message, string $reason, ?string $actorId = null): SmsMessage
    {
        $message = DB::transaction(function () use ($message, $reason, $actorId): SmsMessage {
            $lockedMessage = SmsMessage::query()->lockForUpdate()->findOrFail($message->id);

            if ($lockedMessage->status !== 'failed' || !$lockedMessage->is_active) {
                throw new RuntimeException('Only an active, currently failed SMS message can be retried');
            }

            $lockedMessage->update([
                'status' => 'queued',
                'error_message' => null,
                'failed_at' => null,
                'queued_at' => now(),
                'meta' => array_merge($lockedMessage->meta ?? [], [
                    'retry_reason' => $reason,
                    'retried_by' => $actorId,
                    'retried_at' => now()->toIso8601String(),
                ]),
            ]);

            return $lockedMessage;
        });

        if ($this->settingsService->getSettings()['queue_enabled']) {
            SendSmsMessageJob::dispatch($message->id)
                ->onQueue($this->queueForMessage($message))
                ->afterCommit();
        } else {
            $this->processQueuedMessage($message);
        }

        return $message->fresh();
    }

    public function getMessages(array $filters = []): LengthAwarePaginator
    {
        return SmsMessage::query()->with(['booking:id,booking_number', 'bookingItem:id,booking_id,trip_number'])
            ->when(!empty($filters['status']), fn($query) => $query->where('status', $filters['status']))
            ->when(!empty($filters['channel']), fn($query) => $query->where('channel', $filters['channel']))
            ->when(!empty($filters['campaign_id']), fn($query) => $query->where('campaign_id', $filters['campaign_id']))
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $query->where(function ($inner) use ($filters) {
                    $inner->where('recipient', 'like', '%' . $filters['search'] . '%')
                        ->orWhere('message', 'like', '%' . $filters['search'] . '%');
                });
            })
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function getTransactionalComplianceReport(int $days = 30): array
    {
        $since = now()->subDays($days);
        $standardEvents = ['booking.confirmed', 'driver.dispatched', 'driver.arrived'];
        $optionalEvents = ['website.inquiry_received', 'website.quotation_requested', 'trip.completed', 'payment.received'];
        $adminEvent = 'admin.booking_confirmed_summary';
        $driverOnlyEvents = ['driver.assignment_fallback'];

        $messages = SmsMessage::query()
            ->where('source', 'automation')
            ->where('created_at', '>=', $since)
            ->whereNotNull('event_key')
            ->get([
                'id', 'booking_id', 'event_key', 'idempotency_key', 'status',
                'segments', 'total_cost', 'cost_currency', 'created_at',
            ]);
        $bookingMessages = $messages->whereNotNull('booking_id');
        $standard = $bookingMessages->whereIn('event_key', $standardEvents);
        $byBooking = $standard->groupBy('booking_id');
        $compliant = 0;
        $incomplete = 0;
        $bookingsWithExtra = 0;
        $standardOverage = 0;
        $duplicateStandardEvents = 0;

        foreach ($byBooking as $bookingRows) {
            $eventCounts = $bookingRows->countBy('event_key');
            $hasAllThree = collect($standardEvents)->every(fn (string $event) => ($eventCounts[$event] ?? 0) === 1);
            if ($hasAllThree && $bookingRows->count() === 3) {
                $compliant++;
            } else {
                if ($eventCounts->count() < 3) {
                    $incomplete++;
                }
                $duplicates = $eventCounts->sum(fn (int $count) => max(0, $count - 1));
                $duplicateStandardEvents += $duplicates;
                if ($bookingRows->count() > 3 || $duplicates > 0) {
                    $bookingsWithExtra++;
                }
                $standardOverage += max(0, $bookingRows->count() - 3);
            }
        }

        $admin = $bookingMessages->where('event_key', $adminEvent);
        $optional = $messages->whereIn('event_key', $optionalEvents);
        $knownEvents = array_merge($standardEvents, $optionalEvents, [$adminEvent], $driverOnlyEvents);
        $unexpected = $messages->reject(fn (SmsMessage $message) => in_array($message->event_key, $knownEvents, true));
        $settings = $this->settingsService->getSettings();
        $configuredAdminRecipients = !empty($settings['admin_booking_summary_enabled'])
            ? count($settings['admin_booking_summary_numbers'] ?? [])
            : 0;
        $confirmationBookings = $standard->where('event_key', 'booking.confirmed')->pluck('booking_id')->unique()->count();

        return [
            'window' => ['days' => $days, 'from' => $since->toIso8601String(), 'to' => now()->toIso8601String()],
            'customer_normal_three' => [
                'bookings_observed' => $byBooking->count(),
                'compliant_bookings' => $compliant,
                'incomplete_bookings' => $incomplete,
                'bookings_with_extras' => $bookingsWithExtra,
                'messages' => $standard->count(),
                'duplicate_event_messages' => $duplicateStandardEvents,
            ],
            'admin_summaries' => [
                'configured_recipients' => $configuredAdminRecipients,
                'expected_messages_for_confirmations' => $confirmationBookings * $configuredAdminRecipients,
                'recorded_messages' => $admin->count(),
                'segments' => (int) $admin->sum(fn (SmsMessage $message) => (int) ($message->segments ?: 1)),
                'estimated_cost' => round((float) $admin->sum(fn (SmsMessage $message) => (float) ($message->total_cost ?? 0)), 4),
                'cost_currency' => (string) ($settings['cost_currency'] ?? 'LKR'),
            ],
            'optional_messages' => [
                'total' => $optional->count(),
                'by_event' => $optional->countBy('event_key')->all(),
            ],
            'unexpected_messages' => [
                'total' => $standardOverage + $unexpected->count(),
                'standard_overage' => $standardOverage,
                'unknown_event_messages' => $unexpected->count(),
                'by_event' => $unexpected->countBy('event_key')->all(),
            ],
            'status_counts' => $messages->countBy('status')->all(),
        ];
    }

    private function queueForMessage(SmsMessage $message): string
    {
        return $message->channel === 'campaign' || $message->campaign_id
            ? 'sms-campaigns'
            : 'sms';
    }

    public function getCampaigns(array $filters = []): LengthAwarePaginator
    {
        return SmsCampaign::query()
            ->when(!empty($filters['status']), fn($query) => $query->where('status', $filters['status']))
            ->when(!empty($filters['search']), fn($query) => $query->where('name', 'like', '%' . $filters['search'] . '%'))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function getBalance(bool $forceRefresh = false): array
    {
        return $this->providerManager->active()->getBalance($forceRefresh);
    }

    public function getMasks(bool $forceRefresh = false): array
    {
        return $this->providerManager->active()->getMasks($forceRefresh);
    }

    public function checkMessageStatus(SmsMessage $message): array
    {
        if (!$message->provider_transaction_id) {
            throw new RuntimeException('This message has no provider transaction id to check');
        }

        $result = $this->providerManager->active()->checkTransactionStatus($message->provider_transaction_id);
        $providerStatus = trim((string) ($result['campaign_status'] ?? ''));
        $normalizedStatus = $this->tryNormalizeProviderStatus($providerStatus);

        $updates = [
            'provider_status' => $normalizedStatus ?: mb_substr($providerStatus ?: 'unknown', 0, 255),
            'provider_status_at' => now(),
            'provider_response' => array_merge($message->provider_response ?? [], [
                'transaction_status_check' => $this->redactProviderPayload($result),
            ]),
        ];
        if ($normalizedStatus !== null) {
            $updates['status'] = $normalizedStatus;
        }
        $message->update($updates);

        return array_merge($result, [
            'normalized_status' => $normalizedStatus,
            'status_known' => $normalizedStatus !== null,
            'message_status' => $normalizedStatus ?: $message->status,
        ]);
    }

    public function reconcileStaleProcessing(int $olderThanMinutes = 15, int $limit = 100): array
    {
        $messages = SmsMessage::query()
            ->where('status', 'processing')
            ->where('processing_at', '<=', now()->subMinutes(max(1, $olderThanMinutes)))
            ->whereNotNull('provider_transaction_id')
            ->oldest('processing_at')
            ->limit(max(1, min(500, $limit)))
            ->get();

        $result = ['checked' => 0, 'updated' => 0, 'errors' => 0, 'resent' => 0];
        foreach ($messages as $message) {
            $result['checked']++;
            try {
                $before = $message->status;
                $this->checkMessageStatus($message);
                if ($message->fresh()->status !== $before) {
                    $result['updated']++;
                }
            } catch (Throwable $exception) {
                $result['errors']++;
                $message->update([
                    'provider_response' => array_merge($message->provider_response ?? [], [
                        'reconciliation_error' => $exception->getMessage(),
                        'reconciliation_checked_at' => now()->toIso8601String(),
                    ]),
                ]);
            }
        }

        return $result;
    }

    private function normalizeProviderStatus(string $status): string
    {
        return $this->tryNormalizeProviderStatus($status)
            ?? throw new RuntimeException('Unknown SMS provider status');
    }

    private function tryNormalizeProviderStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            '1', 'delivered', 'delivery successful', 'success' => 'delivered',
            'queued', 'pending', 'created', 'campaign created' => 'queued',
            'processing', 'submitted', 'in progress', 'in_progress', 'campaign processing' => 'processing',
            'sent', 'accepted', 'completed', 'campaign completed', 'partially completed' => 'sent',
            'fully completed' => 'delivered',
            'cancelled', 'canceled' => 'cancelled',
            'failed', 'rejected', 'undelivered', 'expired', '0' => 'failed',
            default => null,
        };
    }

    private function redactProviderPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/secret|token|password|authorization|api.?key/i', (string) $key)) {
                $payload[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactProviderPayload($value);
            }
        }
        return $payload;
    }

    public function resolveAudienceRecipients(
        string $audienceType,
        array $filters = [],
        array $manualRecipients = []
    ): array {
        return match ($audienceType) {
            'customers' => $this->normalizeRecipients(
                Customer::query()
                    ->where('marketing_consent', true)
                    ->when(!empty($filters['ids']), fn($query) => $query->whereIn('id', $filters['ids']))
                    ->pluck('phone')
                    ->all()
            ),
            default => $this->normalizeRecipients($manualRecipients),
        };
    }

    private function refreshCampaignStats(string $campaignId): void
    {
        $campaign = SmsCampaign::query()->find($campaignId);
        if (!$campaign) {
            return;
        }

        $stats = SmsMessage::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->where('campaign_id', $campaignId)
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) SmsMessage::query()->where('campaign_id', $campaignId)->count();
        $completed = (int) ($stats['sent'] ?? 0) + (int) ($stats['delivered'] ?? 0) + (int) ($stats['failed'] ?? 0);

        $campaign->update([
            'queued_recipients' => (int) ($stats['queued'] ?? 0),
            'sent_recipients' => (int) ($stats['sent'] ?? 0),
            'delivered_recipients' => (int) ($stats['delivered'] ?? 0),
            'failed_recipients' => (int) ($stats['failed'] ?? 0),
            'status' => $completed >= $total && $total > 0 ? 'completed' : $campaign->status,
            'completed_at' => $completed >= $total && $total > 0 ? now() : $campaign->completed_at,
        ]);
    }

    private function resolveSenderMask(?string $senderMask): ?string
    {
        $settings = $this->settingsService->getSettings();
        if ($senderMask && $settings['allow_mask_override']) {
            return trim($senderMask);
        }

        return $settings['default_sender_mask'];
    }

    private function normalizeRecipients(array $recipients): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn($recipient) => $this->normalizeRecipient($recipient),
            $recipients
        ))));
    }

    private function normalizeRecipient(mixed $recipient): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $recipient);
        if (!$digits) {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '94' . substr($digits, 1);
        }

        if (str_starts_with($digits, '7') && strlen($digits) === 9) {
            $digits = '94' . $digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }
}
