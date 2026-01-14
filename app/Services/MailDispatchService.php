<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailDispatchService
{
    /**
     * Send a customer-facing email with required CC/BCC rules.
     *
     * Options supported:
     * - cc: array|string - explicit CC recipients (overrides default customer CC if provided)
     * - bcc: array|string - extra BCC recipients to add
     * - suppress_global_bcc: bool - if true, do not add global BCC recipients
     */
    public function sendToCustomer(string $email, Mailable $mailable, array $options = []): void
    {
        if (!$email) {
            Log::warning('Skipping customer email send; missing recipient.', [
                'mailable' => $mailable::class,
            ]);
            return;
        }

        $pending = Mail::to($email);

        // CC: explicit override or default customer cc
        if (!empty($options['cc'])) {
            $pending = $pending->cc($options['cc']);
        } else {
            $pending = $pending->cc($this->customerCcRecipients());
        }

        // Global BCC: only add when not suppressed
        if (empty($options['suppress_global_bcc'])) {
            $pending = $pending->bcc($this->globalBccRecipients());
        }

        // Additional BCCs
        if (!empty($options['bcc'])) {
            $pending = $pending->bcc($options['bcc']);
        }

        $this->dispatch($pending, $mailable);
    }

    /**
     * Send an internal email with global BCC rules.
     *
     * @param string|array<int,string> $recipients
     */
    public function sendToInternal(string|array $recipients, Mailable $mailable): void
    {
        $pending = Mail::to($recipients)->bcc($this->globalBccRecipients());
        $this->dispatch($pending, $mailable);
    }

    /**
     * Centralized dispatch point so queueing can be enabled later.
     */
    protected function dispatch(PendingMail $pending, Mailable $mailable): void
    {
        $pending->send($mailable);
    }

    /**
     * @return array<int,string>
     */
    protected function customerCcRecipients(): array
    {
        if (app()->environment('local', 'testing')) {
            return [];
        }
        return array_values(array_filter(config('mail.customer_cc', [])));
    }

    /**
     * @return array<int,string>
     */
    protected function globalBccRecipients(): array
    {
        if (app()->environment('local', 'testing')) {
            return [];
        }
        return array_values(array_filter(config('mail.bcc_all', [])));
    }
}
