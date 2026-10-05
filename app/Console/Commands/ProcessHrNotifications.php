<?php

namespace App\Console\Commands;

use App\Services\Hr\HrNotificationService;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProcessHrNotifications extends Command
{
    protected $signature = 'hr:process-notifications {--commit}';

    protected $description = 'Preview or process queued HR notifications without calling external providers';

    public function handle(HrNotificationService $notifications): int
    {
        if (! config('hr.features.engagement_analytics', false)
            || ! config('hr.system_user_id')
            || ! Schema::hasTable('hr_notification_outbox')) {
            return self::SUCCESS;
        }

        $commit = (bool) $this->option('commit');
        $count = 0;

        DB::table('hr_notification_outbox')
            ->where('status', 'queued')
            ->where('available_at', '<=', now())
            ->chunkById(100, function ($rows) use ($commit, $notifications, &$count): void {
                foreach ($rows as $candidate) {
                    if (! $commit) {
                        $count++;
                        continue;
                    }

                    $processed = DB::transaction(function () use ($candidate, $notifications): bool {
                        $outbox = DB::table('hr_notification_outbox')
                            ->where('id', $candidate->id)
                            ->where('status', 'queued')
                            ->where('available_at', '<=', now())
                            ->lockForUpdate()
                            ->first();
                        if (! $outbox) {
                            return false;
                        }

                        $failure = ! $notifications->companyIsActive($outbox->company_id)
                            ? 'Notification company is no longer active.'
                            : (! $notifications->recipientIsActive($outbox->company_id, $outbox->recipient_staff_id, $outbox->recipient_user_id)
                                ? 'Notification recipient is no longer active in the notification company.'
                                : null);
                        if ($failure !== null) {
                            $message = $failure;
                            DB::table('hr_notification_outbox')->where('id', $outbox->id)->update([
                                'status' => 'failed', 'last_error' => $message, 'updated_at' => now(),
                            ]);
                            DB::table('hr_notification_delivery_events')->insertOrIgnore([
                                'id' => (string) Str::uuid(), 'outbox_id' => $outbox->id, 'event_type' => 'failed',
                                'attempt_number' => $outbox->attempt_count, 'payload_checksum' => $outbox->payload_checksum,
                                'message' => $message, 'actor_user_id' => config('hr.system_user_id'), 'occurred_at' => now(),
                            ]);

                            return true;
                        }

                        $payload = decrypt($outbox->encrypted_rendered_payload);
                        $encodedPayload = is_array($payload)
                            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                            : false;
                        abort_unless(
                            $encodedPayload !== false
                                && hash_equals($outbox->payload_checksum, hash('sha256', $encodedPayload)),
                            409,
                            'Queued HR notification integrity check failed.'
                        );

                        $now = now();
                        if ($outbox->channel === 'in_app') {
                            $notificationId = hash('sha256', $outbox->id);
                            $notificationId = substr($notificationId, 0, 8).'-'.substr($notificationId, 8, 4)
                                .'-4'.substr($notificationId, 13, 3).'-a'.substr($notificationId, 17, 3)
                                .'-'.substr($notificationId, 20, 12);
                            DatabaseNotification::firstOrCreate([
                                'id' => $notificationId,
                            ], [
                                'type' => 'App\\Notifications\\HrGovernedNotification',
                                'notifiable_type' => 'App\\Models\\User',
                                'notifiable_id' => $outbox->recipient_user_id,
                                'data' => [
                                    'notification_type' => $outbox->event_type,
                                    'title' => $payload['subject'] ?: 'HR notification',
                                    'message' => $payload['body'],
                                    'source_type' => $outbox->source_type,
                                    'source_id' => $outbox->source_id,
                                ],
                            ]);

                            $attempt = $outbox->attempt_count + 1;
                            DB::table('hr_notification_outbox')->where('id', $outbox->id)->update([
                                'status' => 'delivered', 'attempt_count' => $attempt, 'delivered_at' => $now,
                                'external_reference' => $notificationId, 'updated_at' => $now,
                            ]);
                            DB::table('hr_notification_delivery_events')->insertOrIgnore([
                                'id' => (string) Str::uuid(), 'outbox_id' => $outbox->id,
                                'event_type' => 'delivered', 'attempt_number' => $attempt,
                                'payload_checksum' => $outbox->payload_checksum,
                                'external_reference' => $notificationId,
                                'actor_user_id' => config('hr.system_user_id'), 'occurred_at' => $now,
                            ]);

                            return true;
                        }

                        DB::table('hr_notification_outbox')->where('id', $outbox->id)->update([
                            'status' => 'pending_delivery', 'updated_at' => $now,
                        ]);
                        DB::table('hr_notification_delivery_events')->insertOrIgnore([
                            'id' => (string) Str::uuid(), 'outbox_id' => $outbox->id,
                            'event_type' => 'handed_off', 'attempt_number' => 0,
                            'payload_checksum' => $outbox->payload_checksum,
                            'actor_user_id' => config('hr.system_user_id'), 'occurred_at' => $now,
                        ]);

                        return true;
                    });
                    if ($processed) {
                        $count++;
                    }
                }
            });

        $this->info(($commit ? 'Processed ' : 'Would process ').$count.' HR notification(s).');

        return self::SUCCESS;
    }
}
