<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PublicInquirySpamProtection
{
    public function rejectsAutomatedSubmission(Request $request): bool
    {
        foreach (['name', 'customer_name', 'contact_person', 'full_name'] as $field) {
            if ($request->filled($field) && $this->invalidIdentityReason((string) $request->input($field))) {
                return $this->discard($request, $field . ':invalid_identity');
            }
        }

        if (trim((string) $request->input('_inquiry_website')) !== '') {
            return $this->discard($request, 'honeypot_filled');
        }

        try {
            $startedAt = (int) Crypt::decryptString((string) $request->input('_inquiry_form_token'));
            $formAge = now()->timestamp - $startedAt;
            if ($startedAt <= 0 || $formAge < 2 || $formAge > 21600) {
                return $this->discard($request, 'invalid_form_age');
            }
        } catch (\Throwable) {
            return $this->discard($request, 'invalid_form_token');
        }

        if (config('services.turnstile.enabled')) {
            $secret = (string) config('services.turnstile.secret_key');
            $token = (string) $request->input('cf-turnstile-response');
            if ($secret === '' || (string) config('services.turnstile.site_key') === '') {
                Log::critical('Turnstile is enabled without complete credentials.');
                return $this->discard($request, 'turnstile_not_configured');
            }
            if ($token === '') {
                return $this->discard($request, 'turnstile_token_missing');
            }

            try {
                $response = Http::asForm()->connectTimeout(2)->timeout(5)
                    ->post((string) config('services.turnstile.verify_url'), [
                        'secret' => $secret,
                        'response' => $token,
                        'remoteip' => $request->ip(),
                    ]);
                $result = $response->json();
                if (!$response->successful() || ($result['success'] ?? false) !== true
                    || (!empty($result['action']) && $result['action'] !== 'public_inquiry')) {
                    return $this->discard($request, 'turnstile_rejected');
                }
            } catch (\Throwable $exception) {
                Log::warning('Turnstile verification failed closed', ['error' => $exception->getMessage()]);
                return $this->discard($request, 'turnstile_unavailable');
            }
        }

        return false;
    }

    /** @param array<string, mixed> $meta */
    public function rejectsSpamOrDuplicate(Request $request, array $meta): bool
    {
        $content = Arr::flatten($request->except([
            '_token', '_inquiry_form_token', '_inquiry_website', 'cf-turnstile-response',
            'cms_content_id', 'cms_form_context', 'service_slug', 'inquiry_service_page_id',
            'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'name', 'customer_name', 'contact_person', 'full_name', 'email', 'customer_email',
            'phone', 'customer_phone', 'company_email', 'contact_person_email',
        ]));
        $message = Str::lower(implode(' ', array_filter($content, 'is_scalar')));
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', ' ', $message) ?? $message;

        if ($this->invalidIdentityReason((string) ($meta['name'] ?? '')) !== null) {
            return $this->discard($request, 'invalid_identity');
        }
        if (preg_match('/(?:https?:\/\/|www\.|\b[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:\/|\b))/iu', $message) === 1) {
            return $this->discard($request, 'external_link');
        }

        $fingerprint = hash('sha256', implode('|', [
            Str::lower(trim((string) ($meta['email'] ?? ''))),
            preg_replace('/\D+/', '', (string) ($meta['phone'] ?? '')),
            preg_replace('/\s+/', ' ', trim($message)),
        ]));
        if (!Cache::add('public-inquiry-submission:' . $fingerprint, true, now()->addDay())) {
            return $this->discard($request, 'duplicate_submission');
        }

        return false;
    }

    private function invalidIdentityReason(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') return null;
        if (Str::length($name) > 120 || preg_match('/[\r\n]/u', $name) === 1) return 'invalid_length_or_lines';
        if (preg_match('/(?:https?:\/\/|www\.|\b[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:\/|\b))/iu', $name) === 1) return 'url_in_name';
        if (preg_match('/[<>]|&#?\w+;|@/u', $name) === 1) return 'markup_or_address_in_name';
        if (preg_match('/\p{N}/u', $name) === 1) return 'number_in_name';
        if (preg_match('/\p{L}/u', $name) !== 1) return 'name_without_letters';
        return null;
    }

    private function discard(Request $request, string $reason): bool
    {
        Log::notice('Automated public inquiry discarded', [
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        return true;
    }
}
