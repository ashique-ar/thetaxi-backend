<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Notifications\DocumentExpiryNotification;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverOnboardingApplication;
use App\Models\Vehicle\Vehicle;
use App\Services\Driver\NotificationTriggerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SendDocumentExpiryReminders extends Command
{
    protected $signature = 'documents:send-expiry-reminders';
    protected $description = 'Notify drivers about expiring onboarding documents';

    public function handle(NotificationTriggerService $notifications): int
    {
        if (! Schema::hasColumns('documents', ['reminder_days', 'last_reminded_on'])) {
            $this->warn('Document expiry reminder migration is not installed.');
            return self::SUCCESS;
        }
        $sent = 0;
        Document::query()->whereNotNull('expiry_date')->whereIn('status', ['pending', 'verified', 'active'])
            ->whereNull('last_reminded_on')
            ->whereRaw('expiry_date <= CURRENT_DATE + reminder_days')
            ->with('documentable')->each(function (Document $document) use (&$sent, $notifications) {
                $notifiable = $document->documentable?->user
                    ?? $document->documentable?->defaultDriver?->user
                    ?? $document->documentable?->application?->user;
                if (! $notifiable) return;
                $notifiable->notify(new DocumentExpiryNotification($document));
                $owner = $document->documentable;
                $driver = match (true) {
                    $owner instanceof Driver => $owner,
                    $owner instanceof Vehicle => $owner->defaultDriver,
                    $owner instanceof DriverOnboardingApplication => $owner->driver,
                    default => null,
                };
                if ($driver) {
                    $daysRemaining = now()->startOfDay()->diffInDays($document->expiry_date, false);
                    $notifications->sendDriverPushNotification(
                        $driver,
                        'driver_document_expiry',
                        $daysRemaining < 0 ? 'Document expired' : 'Document expiry reminder',
                        $daysRemaining < 0
                            ? 'Your ' . str($document->document_type)->replace('_', ' ')->lower() . ' has expired. Upload the renewed document.'
                            : 'Your ' . str($document->document_type)->replace('_', ' ')->lower() . ' expires on ' . $document->expiry_date->toDateString() . '. Please upload the renewed document.',
                        [
                            'document_id' => (string) $document->id,
                            'document_type' => (string) $document->document_type,
                            'expiry_date' => $document->expiry_date->toDateString(),
                            'days_remaining' => (string) $daysRemaining,
                            'action' => 'driver_document_renewal',
                        ],
                        false
                    );
                }
                $document->update(['last_reminded_on' => today()]);
                $sent++;
            });
        $this->info("Sent {$sent} document expiry reminder(s).");
        return self::SUCCESS;
    }
}
