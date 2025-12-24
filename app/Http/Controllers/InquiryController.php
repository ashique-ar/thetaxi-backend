<?php

namespace App\Http\Controllers;

use App\Mail\InquiryConfirmationMail;
use App\Models\Inquiry;
use App\Services\MailDispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InquiryController extends Controller
{
    protected MailDispatchService $mailDispatchService;

    public function __construct(MailDispatchService $mailDispatchService)
    {
        $this->mailDispatchService = $mailDispatchService;
    }

    /**
     * Handle public inquiry form submissions.
     */
    public function store(Request $request)
    {
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
                'intro' => 'Thank you for contacting TheTaxi about corporate transport services. Our corporate team will review your requirements and respond shortly.',
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
                'intro' => 'Thank you for reaching out to TheTaxi. We have received your message and will respond shortly.',
                'success_message' => 'Thank you for your inquiry! Our team will get back to you soon.',
            ],
        };
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
        if (!empty($data['country'])) {
            $lines[] = 'Country: ' . $data['country'];
        }
        $lines[] = 'Message: ' . ($data['message'] ?? 'N/A');

        return implode("\n", $lines);
    }
}
