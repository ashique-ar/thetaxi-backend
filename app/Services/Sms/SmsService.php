<?php

namespace App\Services\Sms;

use App\Jobs\LaunchSmsCampaignJob;
use App\Jobs\SendSmsMessageJob;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\Sms\SmsCampaign;
use App\Models\Sms\SmsMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SmsService
{
    public function __construct(
        private SmsProviderManager $providerManager,
        private SmsSettingsService $settingsService
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
            'recent_messages' => SmsMessage::query()->latest()->limit(10)->get(),
            'recent_campaigns' => SmsCampaign::query()->latest()->limit(10)->get(),
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
        $dryRun = ($payload['source'] ?? 'manual') === 'automation'
            && !empty($settings['dry_run']);

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
            SendSmsMessageJob::dispatch($message->id)->afterCommit();
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
        $recipients = $this->resolveAudienceRecipients(
            $payload['audience_type'] ?? 'manual',
            $payload['audience_filters'] ?? [],
            $payload['recipients'] ?? []
        );

        $campaign = SmsCampaign::create([
            'name' => trim((string) ($payload['name'] ?? 'Untitled campaign')),
            'message' => trim((string) ($payload['message'] ?? '')),
            'provider' => $this->settingsService->getActiveProvider(),
            'sender_mask' => $this->resolveSenderMask($payload['sender_mask'] ?? null),
            'status' => !empty($payload['scheduled_at']) ? 'scheduled' : 'draft',
            'audience_type' => $payload['audience_type'] ?? 'manual',
            'audience_filters' => $payload['audience_filters'] ?? null,
            'recipient_snapshot' => array_values($recipients),
            'total_recipients' => count($recipients),
            'scheduled_at' => !empty($payload['scheduled_at'])
                ? Carbon::parse($payload['scheduled_at'])
                : null,
            'meta' => $payload['meta'] ?? null,
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
        if (in_array($campaign->status, ['processing', 'completed'], true)) {
            return $campaign;
        }

        $recipients = $this->normalizeRecipients($campaign->recipient_snapshot ?? []);
        if ($recipients === []) {
            $campaign->update([
                'status' => 'failed',
                'completed_at' => now(),
                'meta' => array_merge($campaign->meta ?? [], [
                    'error' => 'No recipients available for campaign',
                ]),
            ]);
            return $campaign->fresh();
        }

        $campaign->update([
            'status' => 'processing',
            'launched_at' => now(),
            'queued_recipients' => count($recipients),
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
                SendSmsMessageJob::dispatch($message->id);
            } else {
                $this->processQueuedMessage($message);
            }
        }

        return $campaign->fresh();
    }

    public function processQueuedMessage(SmsMessage $message): SmsMessage
    {
        if ($message->status === 'delivered') {
            return $message;
        }

        $message->update([
            'status' => 'processing',
            'processing_at' => now(),
            'attempts' => (int) $message->attempts + 1,
        ]);

        try {
            $response = $this->providerManager->active()->sendSingle([
                'recipient' => $message->normalized_recipient,
                'message' => $message->message,
                'sender_mask' => $message->sender_mask,
                'meta' => $message->meta ?? [],
            ]);

            $message->update([
                'status' => 'sent',
                'provider_message_id' => $response['provider_message_id'] ?? null,
                'provider_campaign_id' => $response['provider_campaign_id'] ?? null,
                'provider_transaction_id' => $response['transaction_id'] ?? null,
                'provider_response' => $response['raw'] ?? $response,
                'sent_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            $message->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ]);
        }

        if ($message->campaign_id) {
            $this->refreshCampaignStats($message->campaign_id);
        }

        return $message->fresh();
    }

    public function markDelivery(array $payload): array
    {
        $providerCampaignId = $payload['campaignId'] ?? $payload['campaign_id'] ?? null;
        $recipient = $this->normalizeRecipient($payload['msisdn'] ?? $payload['recipient'] ?? '');
        $statusCode = (string) ($payload['status'] ?? '');

        $query = SmsMessage::query();
        if ($providerCampaignId) {
            $query->where('provider_campaign_id', $providerCampaignId);
        }
        if ($recipient) {
            $query->where('normalized_recipient', $recipient);
        }

        $message = $query->latest()->first();
        if (!$message) {
            return ['updated' => false];
        }

        $isDelivered = in_array($statusCode, ['1', 'delivered', 'success'], true);

        $message->update([
            'status' => $isDelivered ? 'delivered' : 'failed',
            'delivered_at' => $isDelivered ? now() : $message->delivered_at,
            'failed_at' => $isDelivered ? $message->failed_at : now(),
            'provider_response' => array_merge($message->provider_response ?? [], [
                'delivery_callback' => $payload,
            ]),
        ]);

        if ($message->campaign_id) {
            $this->refreshCampaignStats($message->campaign_id);
        }

        return ['updated' => true, 'message_id' => $message->id];
    }

    public function retryFailedMessage(SmsMessage $message): SmsMessage
    {
        $message->update([
            'status' => 'queued',
            'error_message' => null,
            'failed_at' => null,
            'queued_at' => now(),
        ]);

        if ($this->settingsService->getSettings()['queue_enabled']) {
            SendSmsMessageJob::dispatch($message->id);
        } else {
            $this->processQueuedMessage($message);
        }

        return $message->fresh();
    }

    public function getMessages(array $filters = []): LengthAwarePaginator
    {
        return SmsMessage::query()
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

        $message->update([
            'provider_response' => array_merge($message->provider_response ?? [], [
                'transaction_status_check' => $result,
            ]),
        ]);

        return $result;
    }

    public function resolveAudienceRecipients(
        string $audienceType,
        array $filters = [],
        array $manualRecipients = []
    ): array {
        return match ($audienceType) {
            'customers' => $this->normalizeRecipients(
                Customer::query()
                    ->when(!empty($filters['ids']), fn($query) => $query->whereIn('id', $filters['ids']))
                    ->pluck('phone')
                    ->all()
            ),
            'drivers' => $this->normalizeRecipients(
                Driver::query()
                    ->when(!empty($filters['ids']), fn($query) => $query->whereIn('id', $filters['ids']))
                    ->pluck('phone')
                    ->all()
            ),
            'users' => $this->normalizeRecipients(
                User::query()
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
