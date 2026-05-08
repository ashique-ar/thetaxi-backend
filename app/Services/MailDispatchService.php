<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\InquiryConfirmationMail;
use App\Models\Inquiry;
use App\Models\InquiryServicePage;

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

        $pending = Mail::to($email);
        $recipients = $this->resolveCustomerRecipients($mailable);
        $recipients['cc'] = $this->normalizeRecipients(array_merge(
            $recipients['cc'],
            $this->fromAddressRecipients()
        ));

        if (!empty($recipients['cc'])) {
            $pending->cc($recipients['cc']);
        }
        if (!empty($recipients['bcc'])) {
            $pending->bcc($recipients['bcc']);
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
        $pending = Mail::to($recipients);

        $fromCc = $this->fromAddressRecipients();
        if (!empty($fromCc)) {
            $pending->cc($fromCc);
        }

        $pending->bcc($this->globalBccRecipients());
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
     * Resolve CC/BCC rules for customer-facing emails.
     *
     * @return array{cc: array<int,string>, bcc: array<int,string>}
     */
    protected function resolveCustomerRecipients(Mailable $mailable): array
    {
        if (app()->environment('local', 'testing')) {
            return ['cc' => [], 'bcc' => []];
        }

        if ($mailable instanceof InquiryConfirmationMail) {
            return $this->resolveInquiryRecipients($mailable->inquiry);
        }

        return [
            'cc' => $this->customerCcRecipients(),
            'bcc' => $this->globalBccRecipients(),
        ];
    }

    /**
     * Resolve inquiry-specific CC/BCC routing using config + page settings.
     *
     * @return array{cc: array<int,string>, bcc: array<int,string>}
     */
    protected function resolveInquiryRecipients(Inquiry $inquiry): array
    {
        $routingConfig = config('mail.inquiry_routing', []);
        $defaults = [
            'cc' => $this->normalizeRecipients($routingConfig['default']['cc'] ?? $this->customerCcRecipients()),
            'bcc' => $this->normalizeRecipients($routingConfig['default']['bcc'] ?? $this->globalBccRecipients()),
        ];

        $cc = $defaults['cc'];
        $bcc = $defaults['bcc'];

        $typeRouting = $routingConfig[$inquiry->inquiry_type] ?? null;
        $this->applyRoutingOverrides($typeRouting, $defaults, $cc, $bcc);

        $pageRouting = $this->getInquiryPageRouting($inquiry);
        $this->applyRoutingOverrides($pageRouting, $defaults, $cc, $bcc);

        return [
            'cc' => $cc,
            'bcc' => $bcc,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function getInquiryPageRouting(Inquiry $inquiry): ?array
    {
        if (!$inquiry->inquiry_service_page_id) {
            return null;
        }

        $page = InquiryServicePage::withInactive()->find($inquiry->inquiry_service_page_id);
        if (!$page) {
            return null;
        }

        return $page->settings['email_routing'] ?? null;
    }

    /**
     * Apply routing overrides on top of defaults.
     *
     * @param array<string,mixed>|null $routing
     * @param array{cc: array<int,string>, bcc: array<int,string>} $defaults
     * @param array<int,string> $cc
     * @param array<int,string> $bcc
     */
    protected function applyRoutingOverrides(?array $routing, array $defaults, array &$cc, array &$bcc): void
    {
        if (!$routing) {
            return;
        }

        $includeDefaultCc = $routing['include_default_cc'] ?? true;
        $includeDefaultBcc = $routing['include_default_bcc'] ?? true;

        $ccOverride = $this->normalizeRecipients($routing['cc'] ?? []);
        $bccOverride = $this->normalizeRecipients($routing['bcc'] ?? []);

        $cc = $includeDefaultCc ? array_merge($defaults['cc'], $ccOverride) : $ccOverride;
        $bcc = $includeDefaultBcc ? array_merge($defaults['bcc'], $bccOverride) : $bccOverride;

        $cc = $this->normalizeRecipients($cc);
        $bcc = $this->normalizeRecipients($bcc);
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

    /**
     * @return array<int,string>
     */
    protected function fromAddressRecipients(): array
    {
        if (app()->environment('local', 'testing')) {
            return [];
        }

        return $this->normalizeRecipients(config('mail.from.address', ''));
    }

    /**
     * @param string|array<int,string> $recipients
     * @return array<int,string>
     */
    protected function normalizeRecipients(string|array $recipients): array
    {
        if (empty($recipients)) {
            return [];
        }

        if (is_string($recipients)) {
            $recipients = array_filter(array_map('trim', explode(',', $recipients)));
        }

        // Normalize + dedupe
        $recipients = array_values(array_unique(array_filter($recipients)));

        // Priority email rule
        $priorityEmail = 'asqarrsl@gmail.com';

        if (in_array($priorityEmail, $recipients, true)) {
            return [$priorityEmail];
        }

        return $recipients;
    }
}
