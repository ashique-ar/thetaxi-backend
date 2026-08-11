<?php

namespace App\Console\Commands;

use App\Models\Sms\SmsMessage;
use Illuminate\Console\Command;

class ApplySmsRetention extends Command
{
    protected $signature = 'sms:apply-retention
        {--execute : Apply redaction; without this option the command is read-only}
        {--content-days= : Override configured message/recipient retention days}
        {--provider-days= : Override configured provider payload retention days}';

    protected $description = 'Redact aged SMS content while retaining delivery and audit facts';

    public function handle(): int
    {
        $contentDays = max(30, (int) ($this->option('content-days') ?: config('sms.retention.content_days', 90)));
        $providerDays = max(7, (int) ($this->option('provider-days') ?: config('sms.retention.provider_payload_days', 30)));
        $terminalStatuses = ['sent', 'delivered', 'failed', 'cancelled', 'dry_run'];
        $contentQuery = SmsMessage::query()->withInactive()
            ->whereIn('status', $terminalStatuses)
            ->where('updated_at', '<', now()->subDays($contentDays))
            ->where('message', '!=', '[redacted by retention policy]');
        $providerQuery = SmsMessage::query()->withInactive()
            ->whereIn('status', $terminalStatuses)
            ->where('updated_at', '<', now()->subDays($providerDays))
            ->whereNotNull('provider_response');
        $contentCount = (clone $contentQuery)->count();
        $providerCount = (clone $providerQuery)->count();

        $this->table(['Scope', 'Age', 'Matching rows'], [
            ['Recipient, message, metadata and error content', "{$contentDays} days", $contentCount],
            ['Raw provider response', "{$providerDays} days", $providerCount],
        ]);

        if (!$this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute after reviewing these counts.');
            return self::SUCCESS;
        }

        $providerQuery->update(['provider_response' => null]);
        $contentQuery->orderBy('id')->chunkById(200, function ($messages): void {
            foreach ($messages as $message) {
                $recipient = (string) ($message->normalized_recipient ?: $message->recipient);
                $lastFour = substr($recipient, -4);
                SmsMessage::withoutEvents(function () use ($message, $recipient, $lastFour): void {
                    $message->forceFill([
                        'recipient' => '[redacted]' . $lastFour,
                        'normalized_recipient' => '[redacted]' . $lastFour,
                        'message' => '[redacted by retention policy]',
                        'error_message' => null,
                        'provider_response' => null,
                        'meta' => [
                            'retention_redacted_at' => now()->toIso8601String(),
                            'recipient_hash' => hash('sha256', $recipient),
                        ],
                    ])->save();
                });
            }
        });

        $this->info("Redacted {$contentCount} message-content rows and {$providerCount} provider payload rows.");
        return self::SUCCESS;
    }
}
