<?php

namespace App\Jobs;

use App\Models\Sms\SmsMessage;
use App\Services\Sms\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendSmsMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $messageId
    ) {
        $this->queue = 'sms';
    }

    public function handle(SmsService $smsService): void
    {
        $message = SmsMessage::query()->find($this->messageId);
        if (!$message) {
            return;
        }

        $smsService->processQueuedMessage($message);
    }

    public function failed(Throwable $exception): void
    {
        SmsMessage::query()->whereKey($this->messageId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'failed_at' => now(),
        ]);
    }
}
