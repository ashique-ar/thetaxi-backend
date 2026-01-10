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
     */
    public function sendToCustomer(string $email, Mailable $mailable): void
    {
        if (!$email) {
            Log::warning('Skipping customer email send; missing recipient.', [
                'mailable' => $mailable::class,
            ]);
            return;
        }

        $pending = Mail::to($email)
            ->cc($this->customerCcRecipients())
            ->bcc($this->globalBccRecipients());

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
