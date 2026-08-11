<?php

namespace App\Http\Controllers\Api\Sms;

use App\Http\Controllers\Controller;
use App\Models\Sms\SmsCampaign;
use App\Models\Sms\SmsMessage;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsProviderManager;
use App\Services\Sms\SmsAutomationService;
use App\Services\Sms\SmsSettingsService;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class SmsManagementController extends Controller
{
    public function __construct(
        private SmsService $smsService,
        private SmsSettingsService $smsSettingsService,
        private WebsiteSettingsService $websiteSettingsService,
        private SmsAutomationService $smsAutomationService
    ) {
        $this->middleware('permission:communication.view|communication.manage|sms.overview.view')->only([
            'overview',
        ]);
        $this->middleware('permission:communication.view|communication.manage|sms.settings.view|sms.settings.manage')->only([
            'settings',
            'balance',
            'masks',
            'previewAdminBookingSummary',
            'previewTransactionalTemplate',
        ]);
        $this->middleware('permission:communication.manage|sms.settings.manage')->only([
            'updateSettings',
            'testCredentials',
        ]);
        $this->middleware('permission:communication.manage|sms.sending.manage')->only([
            'send',
            'sendTest',
        ]);
        $this->middleware('permission:communication.view|communication.manage|sms.messages.view|sms.messages.manage')->only([
            'messages',
            'showMessage',
            'complianceReport',
        ]);
        $this->middleware('permission:communication.manage|sms.messages.manage')->only([
            'retryMessage',
        ]);
        $this->middleware('permission:communication.view|communication.manage|sms.messages.view|sms.messages.manage')->only([
            'checkMessageStatus',
            'reconcileProcessing',
        ]);
        $this->middleware('permission:communication.view|communication.manage|sms.campaigns.view|sms.campaigns.manage')->only([
            'campaigns',
            'showCampaign',
        ]);
        $this->middleware('permission:communication.manage|sms.campaigns.manage')->only([
            'createCampaign',
            'launchCampaign',
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $overview = $this->smsService->getOverview();
        $canManageMessages = $this->canManageMessages($request);
        $overview['recent_messages'] = collect($overview['recent_messages'] ?? [])
            ->map(fn (SmsMessage $message): array => $this->messagePayload($message, $canManageMessages))
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'data' => $overview,
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $settings = $this->smsSettingsService->getSettings();
        $canManage = $request->user()?->can('communication.manage')
            || $request->user()?->can('sms.settings.manage');

        if (!$canManage) {
            $settings['admin_booking_summary_numbers'] = array_map(
                static fn (string $number): string => str_repeat('*', max(0, strlen($number) - 4)) . substr($number, -4),
                $settings['admin_booking_summary_numbers']
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms_enabled' => ['required', 'boolean'],
            'sms_provider' => ['required', 'string'],
            'sms_default_sender_mask' => ['nullable', 'string', 'max:50'],
            'sms_allow_mask_override' => ['required', 'boolean'],
            'sms_queue_enabled' => ['required', 'boolean'],
            'sms_dry_run' => ['required', 'boolean'],
            'sms_bulk_chunk_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'sms_cost_per_segment' => ['required', 'numeric', 'min:0'],
            'sms_cost_currency' => ['required', 'string', 'size:3'],
            'sms_webhook_secret' => ['nullable', 'string', 'max:255'],
            'sms_booking_status_enabled' => ['required', 'boolean'],
            'sms_booking_confirmation_enabled' => ['sometimes', 'boolean'],
            'sms_quotation_requested_enabled' => ['sometimes', 'boolean'],
            'sms_inquiry_received_enabled' => ['sometimes', 'boolean'],
            'sms_driver_dispatched_enabled' => ['sometimes', 'boolean'],
            'sms_driver_arrived_enabled' => ['sometimes', 'boolean'],
            'sms_trip_completion_enabled' => ['sometimes', 'boolean'],
            'sms_payment_confirmation_enabled' => ['sometimes', 'boolean'],
            'sms_trip_completion_scope' => ['sometimes', 'string', 'in:booking,item'],
            'sms_driver_assignment_fallback_enabled' => ['sometimes', 'boolean'],
            'sms_driver_assignment_fallback_timeout_minutes' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'sms_admin_booking_summary_enabled' => ['sometimes', 'boolean'],
            'sms_admin_booking_summary_numbers' => [
                'sometimes',
                'required_if:sms_admin_booking_summary_enabled,true',
                'array',
                'min:1',
                'max:2',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $normalized = array_map(function (mixed $number): string {
                        $digits = preg_replace('/\D+/', '', (string) $number);
                        return str_starts_with($digits, '0') ? '94' . substr($digits, 1) : $digits;
                    }, is_array($value) ? $value : []);

                    if (count($normalized) !== count(array_unique($normalized))) {
                        $fail('The admin booking summary numbers must be different.');
                    }
                },
            ],
            'sms_admin_booking_summary_numbers.*' => ['required', 'string', 'distinct', 'regex:/^(?:\+?94|0)?7\d{8}$/'],
            'sms_booking_confirmation_template' => ['sometimes', 'string', 'max:1000'],
            'sms_quotation_requested_template' => ['sometimes', 'string', 'max:1000'],
            'sms_inquiry_received_template' => ['sometimes', 'string', 'max:1000'],
            'sms_driver_dispatched_template' => ['sometimes', 'string', 'max:1500'],
            'sms_driver_arrived_template' => ['sometimes', 'string', 'max:1500'],
            'sms_trip_completion_template' => ['sometimes', 'string', 'max:1500'],
            'sms_payment_confirmation_template' => ['sometimes', 'string', 'max:1500'],
            'sms_driver_assignment_fallback_template' => ['sometimes', 'string', 'max:1500'],
            'sms_admin_booking_summary_template' => ['sometimes', 'string', 'max:2000'],
            'sms_esms_base_url' => ['nullable', 'url'],
            'sms_esms_username' => ['nullable', 'string', 'max:255'],
            'sms_esms_password' => ['nullable', 'string', 'max:255'],
            'sms_esms_api_key' => ['nullable', 'string', 'max:5000'],
            'sms_esms_esmsqk' => ['nullable', 'string', 'max:5000'],
            'sms_esms_delivery_callback_url' => ['nullable', 'url'],
        ]);

        $companyId = $this->websiteSettingsService->resolveCurrentCompanyId();

        foreach ($data as $type => $value) {
            if ($type === 'sms_admin_booking_summary_numbers') {
                $value = $this->smsSettingsService->protectAdminNumbers($value);
            }

            // Queue workers have no HTTP/company context, so they consume the
            // deployment-global copy. Keep the active company copy in sync so
            // the settings screen reads back exactly what was submitted.
            $this->websiteSettingsService->setGlobal($type, $value);
            if ($companyId !== null) {
                $this->websiteSettingsService->set($type, $value, $companyId);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'SMS settings updated successfully',
            'data' => $this->smsSettingsService->getSettings(),
        ]);
    }

    public function testCredentials(Request $request, SmsProviderManager $providerManager): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:esms'],
            'base_url' => ['nullable', 'url'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:5000'],
            'esmsqk' => ['nullable', 'string', 'max:5000'],
        ]);

        $provider = $data['provider'];
        unset($data['provider']);

        return response()->json([
            'status' => 'success',
            'message' => 'SMS credentials tested',
            'data' => $providerManager->testCredentials($provider, $data),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string'],
            'sender_mask' => ['nullable', 'string', 'max:50'],
            'channel' => ['nullable', 'string', 'in:single,bulk,transactional,test'],
            'recipient' => ['nullable', 'string'],
            'recipients' => ['nullable', 'array'],
            'recipients.*' => ['string'],
            'context_type' => ['nullable', 'string'],
            'context_id' => ['nullable', 'string'],
            'template_key' => ['nullable', 'string'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $messages = !empty($data['recipient'])
            ? [$this->smsService->queueSingleMessage($data)]
            : $this->smsService->queueBulkMessages($data)->all();

        return response()->json([
            'status' => 'success',
            'message' => 'SMS queued successfully',
            'data' => ['messages' => $messages],
        ], 201);
    }

    public function sendTest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'string'],
            'message' => ['nullable', 'string'],
            'sender_mask' => ['nullable', 'string', 'max:50'],
        ]);

        $message = $this->smsService->queueSingleMessage([
            'recipient' => $data['recipient'],
            'message' => $data['message'] ?: 'Company SMS test message',
            'sender_mask' => $data['sender_mask'] ?? null,
            'channel' => 'test',
            'template_key' => 'sms.test',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Test SMS queued successfully',
            'data' => ['message' => $message],
        ], 201);
    }

    public function messages(Request $request): JsonResponse
    {
        $messages = $this->smsService->getMessages($request->all());
        $canManageMessages = $this->canManageMessages($request);
        $messages->setCollection($messages->getCollection()->map(
            fn (SmsMessage $message): array => $this->messagePayload($message, $canManageMessages)
        ));

        return response()->json([
            'status' => 'success',
            'data' => $messages->items(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function showMessage(Request $request, SmsMessage $smsMessage): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'message' => $this->messagePayload($smsMessage, $this->canManageMessages($request)),
                'retry_eligible' => $smsMessage->status === 'failed' && $smsMessage->is_active,
            ],
        ]);
    }

    public function complianceReport(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);

        return response()->json([
            'status' => 'success',
            'data' => $this->smsService->getTransactionalComplianceReport((int) ($data['days'] ?? 30)),
        ]);
    }

    public function previewAdminBookingSummary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_reference' => ['nullable', 'string', 'max:100'],
            'template' => ['nullable', 'string', 'max:2000'],
        ]);
        $booking = null;

        if (!empty($data['booking_reference'])) {
            $reference = $data['booking_reference'];
            $booking = \App\Models\Booking\Booking::query()
                ->whereKey($reference)
                ->orWhere('booking_number', $reference)
                ->first();

            if (!$booking) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The selected booking could not be found.',
                ], 404);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->smsAutomationService->previewAdminBookingSummary($booking, $data['template'] ?? null),
        ]);
    }

    public function previewTransactionalTemplate(Request $request): JsonResponse
    {
        $events = [
            'website.inquiry_received',
            'website.quotation_requested',
            'booking.confirmed',
            'admin.booking_confirmed_summary',
            'driver.assignment_fallback',
            'driver.dispatched',
            'driver.arrived',
            'trip.completed',
            'payment.received',
        ];
        $data = $request->validate([
            'event_key' => ['required', 'string', Rule::in($events)],
            'template' => ['required', 'string', 'max:2000'],
            'booking_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $booking = null;

        if (!empty($data['booking_reference'])) {
            $reference = $data['booking_reference'];
            $booking = \App\Models\Booking\Booking::query()
                ->whereKey($reference)
                ->orWhere('booking_number', $reference)
                ->first();

            if (!$booking) {
                return response()->json(['status' => 'error', 'message' => 'The selected booking could not be found.'], 404);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->smsAutomationService->previewTransactionalTemplate(
                $data['event_key'],
                $data['template'],
                $booking
            ),
        ]);
    }

    public function retryMessage(Request $request, SmsMessage $smsMessage): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            return response()->json([
                'status' => 'success',
                'message' => 'SMS re-queued successfully',
                'data' => [
                    'message' => $this->smsService->retryFailedMessage($smsMessage, trim($data['reason']), $request->user()?->id),
                ],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function campaigns(Request $request): JsonResponse
    {
        $campaigns = $this->smsService->getCampaigns($request->all());

        return response()->json([
            'status' => 'success',
            'data' => $campaigns->items(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function createCampaign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'sender_mask' => ['nullable', 'string', 'max:50'],
            'audience_type' => ['required', 'string', 'in:manual,customers'],
            'audience_filters' => ['nullable', 'array'],
            'recipients' => ['nullable', 'array'],
            'recipients.*' => ['string'],
            'scheduled_at' => ['nullable', 'date'],
            'launch_now' => ['nullable', 'boolean'],
            'consent_confirmed' => ['required_if:audience_type,manual', 'accepted'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'SMS campaign created successfully',
            'data' => [
                'campaign' => $this->smsService->createCampaign($data),
            ],
        ], 201);
    }

    public function showCampaign(SmsCampaign $smsCampaign): JsonResponse
    {
        $smsCampaign->load('messages');

        return response()->json([
            'status' => 'success',
            'data' => ['campaign' => $smsCampaign],
        ]);
    }

    public function launchCampaign(SmsCampaign $smsCampaign): JsonResponse
    {
        $this->smsService->scheduleCampaignLaunch($smsCampaign);

        return response()->json([
            'status' => 'success',
            'message' => 'Campaign launch scheduled successfully',
            'data' => ['campaign' => $smsCampaign->fresh()],
        ]);
    }

    public function balance(Request $request): JsonResponse
    {
        try {
            $balance = $this->smsService->getBalance($request->boolean('refresh'));
            $threshold = (float) config('sms.health.low_balance', 1000);
            $numericBalance = is_numeric($balance['balance'] ?? null)
                ? (float) $balance['balance']
                : null;
            $balance['low_balance_threshold'] = $threshold;
            $balance['is_low_balance'] = $numericBalance !== null && $numericBalance <= $threshold;

            return response()->json([
                'status' => 'success',
                'data' => $balance,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch SMS provider balance',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function masks(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->smsService->getMasks($request->boolean('refresh')),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch SMS provider masks',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function checkMessageStatus(SmsMessage $smsMessage): JsonResponse
    {
        try {
            return response()->json([
                'status' => 'success',
                'data' => $this->smsService->checkMessageStatus($smsMessage),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to check SMS transaction status',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    public function reconcileProcessing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'older_than_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Stale processing messages checked against the provider without resending',
            'data' => $this->smsService->reconcileStaleProcessing(
                (int) ($data['older_than_minutes'] ?? 15),
                (int) ($data['limit'] ?? 100)
            ),
        ]);
    }

    public function deliveryCallback(Request $request): JsonResponse
    {
        $secret = $this->smsSettingsService->getSettings()['webhook_secret'];
        if (!$secret) {
            return response()->json(['status' => 'error', 'message' => 'SMS webhook is not configured'], 503);
        }
        $providedSecret = (string) ($request->header('X-SMS-Webhook-Secret') ?: $request->query('secret', ''));
        if (!hash_equals((string) $secret, $providedSecret)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid webhook secret',
            ], 403);
        }

        $payload = $request->validate([
            'transaction_id' => ['nullable', 'string', 'max:255', 'required_without_all:transactionId,message_id,messageId'],
            'transactionId' => ['nullable', 'string', 'max:255'],
            'message_id' => ['nullable', 'string', 'max:255'],
            'messageId' => ['nullable', 'string', 'max:255'],
            'status' => ['required_without:delivery_status', 'string', 'max:100'],
            'delivery_status' => ['required_without:status', 'string', 'max:100'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->smsService->markDelivery($payload),
        ]);
    }

    private function canManageMessages(Request $request): bool
    {
        return (bool) ($request->user()?->can('communication.manage')
            || $request->user()?->can('sms.messages.manage'));
    }

    private function messagePayload(SmsMessage $message, bool $canManage): array
    {
        $recipient = (string) ($message->normalized_recipient ?: $message->recipient ?: '');

        return [
            'id' => $message->id,
            'campaign_id' => $message->campaign_id,
            'provider' => $message->provider,
            'channel' => $message->channel,
            'source' => $message->source,
            'booking_id' => $message->booking_id,
            'booking_item_id' => $message->booking_item_id,
            'driver_assignment_id' => $message->driver_assignment_id,
            'event_key' => $message->event_key,
            'template_key' => $message->template_key,
            'recipient' => $canManage ? $recipient : $this->maskPhone($recipient),
            'normalized_recipient' => $canManage ? $message->normalized_recipient : null,
            'sender_mask' => $message->sender_mask,
            'message' => $canManage ? $message->message : '[Message content restricted]',
            'status' => $message->status,
            'provider_status' => $message->provider_status,
            'segments' => $message->segments,
            'unit_cost' => $message->unit_cost,
            'total_cost' => $message->total_cost,
            'cost_currency' => $message->cost_currency,
            'provider_message_id' => $message->provider_message_id,
            'provider_campaign_id' => $message->provider_campaign_id,
            'provider_transaction_id' => $message->provider_transaction_id,
            'attempts' => $message->attempts,
            'error_message' => $message->error_message,
            'queued_at' => $message->queued_at,
            'processing_at' => $message->processing_at,
            'sent_at' => $message->sent_at,
            'delivered_at' => $message->delivered_at,
            'failed_at' => $message->failed_at,
            'created_at' => $message->created_at,
        ];
    }

    private function maskPhone(string $number): string
    {
        return strlen($number) > 4
            ? str_repeat('*', strlen($number) - 4) . substr($number, -4)
            : $number;
    }
}
