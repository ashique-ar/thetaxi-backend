<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Notifications\DocumentExpiryNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SendDocumentExpiryReminders extends Command
{
    protected $signature = 'documents:send-expiry-reminders';
    protected $description = 'Notify drivers about expiring onboarding documents';

    public function handle(): int
    {
        if (! Schema::hasColumns('documents', ['reminder_days', 'last_reminded_on'])) {
            $this->warn('Document expiry reminder migration is not installed.');
            return self::SUCCESS;
        }
        $sent = 0;
        Document::query()->whereNotNull('expiry_date')->whereIn('status', ['pending', 'verified', 'active'])
            ->whereNull('last_reminded_on')
            ->whereRaw('expiry_date <= CURRENT_DATE + reminder_days')
            ->with('documentable')->each(function (Document $document) use (&$sent) {
                $notifiable = $document->documentable?->user
                    ?? $document->documentable?->defaultDriver?->user
                    ?? $document->documentable?->application?->user;
                if (! $notifiable) return;
                $notifiable->notify(new DocumentExpiryNotification($document));
                $document->update(['last_reminded_on' => today()]);
                $sent++;
            });
        $this->info("Sent {$sent} document expiry reminder(s).");
        return self::SUCCESS;
    }
}
