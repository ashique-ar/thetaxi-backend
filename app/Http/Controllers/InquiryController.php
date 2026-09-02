<?php

namespace App\Http\Controllers;

use App\Mail\InquiryConfirmationMail;
use App\Models\Inquiry;
use App\Models\InquiryServicePage;
use App\Services\MailDispatchService;
use App\Services\Sms\SmsAutomationService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class InquiryController extends Controller
{
    protected MailDispatchService $mailDispatchService;

    public function __construct(
        MailDispatchService $mailDispatchService,
        protected SmsAutomationService $smsAutomationService
    )
    {
        $this->mailDispatchService = $mailDispatchService;
    }

    /**
     * Handle public inquiry form submissions.
     */
    public function store(Request $request)
    {
        if ($request->routeIs('contact.store') && $this->rejectSpamGeneralInquiry($request)) {
            return back()->with('success', 'Thank you for your inquiry! Our team will get back to you soon.');
        }

        $servicePage = $this->resolveInquiryServicePage($request);
        if ($request->filled('inquiry_service_page_id') || $request->filled('service_slug')) {
            if (!$servicePage) {
                return back()
                    ->withInput()
                    ->with('error', 'This inquiry form could not be found.');
            }

            return $this->storeDynamicInquiry($request, $servicePage);
        }

        $type = $this->resolveInquiryType($request);
        $validated = $request->validate($this->rulesForType($type));
        $meta = $this->buildInquiryMeta($type, $validated);

        try {
            $payload = [
                'type' => $type,
                'service_type' => $request->input('service_type'),
                'form' => $request->except('_token'),
                'meta' => [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ];

            $inquiry = Inquiry::create([
                'name' => $meta['name'],
                'email' => $meta['email'],
                'phone' => $meta['phone'],
                'inquiry_type' => $type,
                'subject' => $meta['subject'],
                'message' => $meta['message'],
                'status' => 'open',
                'source' => 'web',
                'payload' => $payload,
            ]);

            $this->queueInquirySms($inquiry);

            $this->mailDispatchService->sendToCustomer(
                $meta['email'],
                new InquiryConfirmationMail($inquiry, $meta['label'], $meta['intro'])
            );

            return back()->with('success', $meta['success_message']);
        } catch (\Exception $e) {
            Log::error('Inquiry submission failed', [
                'type' => $type,
                'error' => $e->getMessage(),
                'payload' => $validated,
            ]);

            return back()
                ->withInput()
                ->with('error', 'An error occurred while submitting your inquiry. Please try again.');
        }
    }

    /**
     * Silently discard automated submissions to the public General Inquiry form.
     *
     * The encrypted page token prevents scripts from posting directly without first
     * loading the form, while the off-screen field catches form-filling bots.
     */
    private function rejectSpamGeneralInquiry(Request $request): bool
    {
        $reason = null;

        if (trim((string) $request->input('company_website')) !== '') {
            $reason = 'honeypot_filled';
        } else {
            try {
                $startedAt = (int) Crypt::decryptString((string) $request->input('_inquiry_form_token'));
                $formAge = now()->timestamp - $startedAt;

                if ($startedAt <= 0 || $formAge < 2 || $formAge > 21600) {
                    $reason = 'invalid_form_age';
                }
            } catch (\Throwable) {
                $reason = 'invalid_form_token';
            }
        }

        if ($reason === null) {
            return false;
        }

        Log::notice('Spam General Inquiry discarded', [
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return true;
    }

    /**
     * Store a dynamic inquiry submission tied to a service page.
     */
    protected function storeDynamicInquiry(Request $request, InquiryServicePage $servicePage)
    {
        if (!$servicePage->is_active || $servicePage->status !== 'published') {
            return back()
                ->withInput()
                ->with('error', 'This inquiry form is not available at the moment.');
        }

        $servicePage->loadMissing(['form.fields']);
        $form = $servicePage->form;

        if (!$form) {
            return back()
                ->withInput()
                ->with('error', 'This inquiry form is not configured yet.');
        }

        $this->normalizeDynamicFormInput($request, $form);
        $validated = $request->validate($form->buildValidationRules());
        $meta = $this->buildDynamicInquiryMeta($servicePage, $form, $validated);

        if (empty($meta['email'])) {
            return back()
                ->withInput()
                ->with('error', 'Please provide a valid email address to submit your inquiry.');
        }

        try {
            $payload = [
                'type' => $servicePage->inquiry_type ?? 'general',
                'service_page_id' => $servicePage->id,
                'service_code' => $servicePage->code,
                'form_id' => $form->id,
                'form' => $request->except('_token'),
                'meta' => [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ];

            $inquiry = Inquiry::create([
                'name' => $meta['name'],
                'email' => $meta['email'],
                'phone' => $meta['phone'],
                'inquiry_type' => $servicePage->inquiry_type ?? 'general',
                'inquiry_service_page_id' => $servicePage->id,
                'service_type' => $servicePage->code,
                'subject' => $meta['subject'],
                'message' => $meta['message'],
                'status' => 'open',
                'source' => 'web',
                'payload' => $payload,
            ]);

            $this->queueInquirySms($inquiry);

            $this->mailDispatchService->sendToCustomer(
                $meta['email'],
                new InquiryConfirmationMail($inquiry, $meta['label'], $meta['intro'])
            );

            return back()->with('success', $meta['success_message']);
        } catch (\Exception $e) {
            Log::error('Dynamic inquiry submission failed', [
                'service_page_id' => $servicePage->id,
                'error' => $e->getMessage(),
                'payload' => $validated,
            ]);

            return back()
                ->withInput()
                ->with('error', 'An error occurred while submitting your inquiry. Please try again.');
        }
    }

    private function queueInquirySms(Inquiry $inquiry): void
    {
        try {
            $this->smsAutomationService->queueWebsiteInquiryReceived($inquiry);
        } catch (\Throwable $exception) {
            Log::error('Website inquiry SMS could not be queued', [
                'inquiry_id' => $inquiry->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Resolve inquiry type from request payload.
     */
    protected function resolveInquiryType(Request $request): string
    {
        $type = $request->input('inquiry_type');
        $serviceType = $request->input('service_type');

        if ($type) {
            return $type;
        }

        return match ($serviceType) {
            'corporate-transport', 'corporate' => 'corporate',
            'point_to_point', 'point-to-point' => 'point_to_point',
            default => 'general',
        };
    }

    /**
     * Resolve inquiry service page by id or slug if provided.
     */
    protected function resolveInquiryServicePage(Request $request): ?InquiryServicePage
    {
        $pageId = $request->input('inquiry_service_page_id');
        if ($pageId) {
            return InquiryServicePage::withInactive()->where('id', $pageId)->first();
        }

        $slug = $request->input('service_slug');
        if ($slug) {
            return InquiryServicePage::withInactive()->where('slug', $slug)->first();
        }

        return null;
    }

    /**
     * Get validation rules for inquiry type.
     *
     * @return array<string, string>
     */
    protected function rulesForType(string $type): array
    {
        return match ($type) {
            'corporate' => [
                'company_name' => 'required|string|max:255',
                'contact_person' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'service_type_select' => 'nullable|string|in:airport_transfer,corporate_event,employee_shuttle,client_meeting,other',
                'other_service_type' => 'required_if:service_type_select,other|nullable|string|max:255',
                'vehicle_class' => 'nullable|string|max:255',
                'employee_strength' => 'required|string|in:1-10,11-50,51-100,101-500,500+',
                'city_name' => 'required|string|max:255',
                'requirements' => 'required|string|max:1000',
            ],
            'point_to_point' => [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'pickup_location' => 'required|string|max:255',
                'dropoff_location' => 'required|string|max:255',
                'travel_date' => 'nullable|date',
                'travel_time' => 'nullable|string|max:50',
                'passengers' => 'nullable|integer|min:1|max:50',
                'message' => 'nullable|string|max:2000',
            ],
            default => [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:20',
                'service_type_select' => 'nullable|string|in:general_inquiry,booking,corporate,complaint,feedback,other',
                'vehicle_class' => 'nullable|string|max:255',
                'country' => 'nullable|string|max:100',
                'message' => 'required|string|max:2000',
            ],
        };
    }

    /**
     * Build inquiry meta content for storage and email copy.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    protected function buildInquiryMeta(string $type, array $data): array
    {
        return match ($type) {
            'corporate' => [
                'label' => 'Corporate Inquiry',
                'name' => $data['contact_person'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'subject' => 'Corporate Transport Inquiry - ' . ($data['company_name'] ?? 'Company'),
                'message' => $this->buildMessage($type, $data),
                'intro' => 'Thank you for contacting us about corporate transport services. Our corporate team will review your requirements and respond shortly.',
                'success_message' => 'Thank you for your inquiry! Our corporate team will contact you within 24 hours.',
            ],
            'point_to_point' => [
                'label' => 'Point-to-Point Inquiry',
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'subject' => 'Point-to-Point Inquiry - ' . ($data['name'] ?? 'Customer'),
                'message' => $this->buildMessage($type, $data),
                'intro' => 'Thank you for your point-to-point inquiry. Our team will review your trip details and respond with the next steps.',
                'success_message' => 'Thanks for reaching out! We will get back to you with your point-to-point inquiry shortly.',
            ],
            default => [
                'label' => 'General Inquiry',
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'subject' => 'General Inquiry - ' . ($data['name'] ?? 'Customer'),
                'message' => $this->buildMessage($type, $data),
                'intro' => 'Thank you for reaching out. We have received your message and will respond shortly.',
                'success_message' => 'Thank you for your inquiry! Our team will get back to you soon.',
            ],
        };
    }

    /**
     * Build meta for a dynamic inquiry submission.
     *
     * @return array<string, string|null>
     */
    protected function buildDynamicInquiryMeta(
        InquiryServicePage $servicePage,
        \App\Models\InquiryForm $form,
        array $data
    ): array {
        $settings = $form->settings ?? [];
        $nameField = $form->resolveContactField('contact_name_field', ['name', 'contact_person', 'full_name']);
        $emailField = $form->resolveContactField('contact_email_field', ['email']);
        $phoneField = $form->resolveContactField('contact_phone_field', ['phone']);

        $name = $nameField ? ($data[$nameField] ?? null) : null;
        $email = $emailField ? ($data[$emailField] ?? null) : null;
        $phone = $phoneField ? ($data[$phoneField] ?? null) : null;

        $label = Arr::get($settings, 'confirmation_label', $servicePage->name);
        $intro = Arr::get(
            $settings,
            'confirmation_intro',
            'Thank you for your inquiry. Our team will review your request and respond shortly.'
        );
        $successMessage = $form->success_message ?? 'Thank you for your inquiry! Our team will contact you soon.';

        $subjectTemplate = Arr::get($settings, 'subject_template', '{service} Inquiry - {name}');
        $subject = str_replace(
            ['{service}', '{name}'],
            [$servicePage->name, $name ?: 'Customer'],
            $subjectTemplate
        );

        return [
            'label' => $label,
            'intro' => $intro,
            'success_message' => $successMessage,
            'subject' => $subject,
            'message' => $this->buildDynamicMessage($servicePage, $form, $data),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /**
     * Build an inquiry summary message for storage.
     *
     * @param array<string, mixed> $data
     */
    protected function buildMessage(string $type, array $data): string
    {
        $lines = [];

        if ($type === 'corporate') {
            $lines[] = 'Corporate transport inquiry';
            $lines[] = 'Company: ' . ($data['company_name'] ?? 'N/A');
            $lines[] = 'Contact: ' . ($data['contact_person'] ?? 'N/A');
            $lines[] = 'Email: ' . ($data['email'] ?? 'N/A');
            $lines[] = 'Phone: ' . ($data['phone'] ?? 'N/A');
            $lines[] = 'Service Type: ' . $this->formatServiceType($data);
            $lines[] = 'Employee Strength: ' . ($data['employee_strength'] ?? 'N/A');
            $lines[] = 'City: ' . ($data['city_name'] ?? 'N/A');
            $lines[] = 'Requirements: ' . ($data['requirements'] ?? 'N/A');
            return implode("\n", $lines);
        }

        if ($type === 'point_to_point') {
            $lines[] = 'Point-to-point inquiry';
            $lines[] = 'Name: ' . ($data['name'] ?? 'N/A');
            $lines[] = 'Email: ' . ($data['email'] ?? 'N/A');
            $lines[] = 'Phone: ' . ($data['phone'] ?? 'N/A');
            $lines[] = 'Pickup: ' . ($data['pickup_location'] ?? 'N/A');
            $lines[] = 'Dropoff: ' . ($data['dropoff_location'] ?? 'N/A');
            if (!empty($data['travel_date'])) {
                $lines[] = 'Travel Date: ' . $data['travel_date'];
            }
            if (!empty($data['travel_time'])) {
                $lines[] = 'Travel Time: ' . $data['travel_time'];
            }
            if (!empty($data['passengers'])) {
                $lines[] = 'Passengers: ' . $data['passengers'];
            }
            if (!empty($data['message'])) {
                $lines[] = 'Message: ' . $data['message'];
            }
            return implode("\n", $lines);
        }

        $lines[] = 'General inquiry';
        $lines[] = 'Name: ' . ($data['name'] ?? 'N/A');
        $lines[] = 'Email: ' . ($data['email'] ?? 'N/A');
        $lines[] = 'Phone: ' . ($data['phone'] ?? 'N/A');
        $lines[] = 'Service Type: ' . $this->formatServiceType($data);
        if (!empty($data['country'])) {
            $lines[] = 'Country: ' . $data['country'];
        }
        $lines[] = 'Message: ' . ($data['message'] ?? 'N/A');

        return implode("\n", $lines);
    }

    /**
     * Build a summary message for dynamic inquiry submissions.
     */
    protected function buildDynamicMessage(
        InquiryServicePage $servicePage,
        \App\Models\InquiryForm $form,
        array $data
    ): string {
        $lines = [$servicePage->name . ' inquiry'];

        foreach ($form->fields as $field) {
            $value = $data[$field->name] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_filter($value));
            }

            if (!empty($field->options)) {
                $option = collect($field->options)->first(function ($opt) use ($value) {
                    if (is_array($opt)) {
                        return ($opt['value'] ?? null) == $value;
                    }
                    return $opt == $value;
                });
                if (is_array($option) && !empty($option['label'])) {
                    $value = $option['label'];
                }
            }

            $lines[] = "{$field->label}: {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Normalize dynamic form input values to strings when arrays are not expected.
     */
    protected function normalizeDynamicFormInput(Request $request, \App\Models\InquiryForm $form): void
    {
        $data = $request->all();

        foreach ($form->fields as $field) {
            if (!array_key_exists($field->name, $data)) {
                continue;
            }

            $value = $data[$field->name];
            if (!is_array($value)) {
                continue;
            }

            if ($this->shouldKeepArrayValue($field)) {
                continue;
            }

            $data[$field->name] = $this->stringifyArrayValue($value);
        }

        $request->merge($data);
    }

    protected function shouldKeepArrayValue(\App\Models\InquiryFormField $field): bool
    {
        if ($field->type === 'checkbox') {
            return true;
        }

        $rawRules = $field->validation_rules;
        return is_string($rawRules) && str_contains($rawRules, 'array');
    }

    /**
     * Collapse an array value into a single string.
     */
    protected function stringifyArrayValue(array $value): string
    {
        $filtered = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
        if (empty($filtered)) {
            return '';
        }

        return implode(', ', array_map('strval', $filtered));
    }

    /**
     * Format service type for display.
     */
    protected function formatServiceType(array $data): string
    {
        $serviceType = $data['service_type_select'] ?? '';
        if ($serviceType === 'other') {
            return $data['other_service_type'] ?? 'Other';
        }

        return match ($serviceType) {
            'airport_transfer' => 'Airport Transfer',
            'corporate_event' => 'Corporate Event',
            'employee_shuttle' => 'Employee Shuttle',
            'client_meeting' => 'Client Meeting',
            'general_inquiry' => 'General Inquiry',
            'booking' => 'Booking',
            'corporate' => 'Corporate Transport',
            'complaint' => 'Complaint',
            'feedback' => 'Feedback',
            default => 'N/A',
        };
    }
}
