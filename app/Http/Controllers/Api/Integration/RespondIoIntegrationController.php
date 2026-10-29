<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inquiry\InquiryResource;
use App\Http\Resources\Booking\BookingFlowResource;
use App\Models\Booking\Booking;
use App\Models\IntegrationConnection;
use App\Models\IntegrationContactMessage;
use App\Models\IntegrationWebhookEvent;
use App\Models\Inquiry;
use App\Models\Customer;
use App\Models\User;
use App\Support\PhoneNumberMatch;
use App\Services\InquiryAssignmentService;
use App\Services\InquiryAssignmentNotificationService;
use App\Services\BookingFlowService;
use App\Services\SingleCompanyScope;
use App\Notifications\RespondIoInquiryNeedsOwnerNotification;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class RespondIoIntegrationController extends Controller
{
    public function connection(): JsonResponse
    {
        $connection = $this->companyConnection();
        return response()->json(['status' => 'success', 'data' => $connection ? [
            'id' => $connection->id, 'name' => $connection->name, 'enabled' => $connection->enabled,
            'default_inquiry_assignee_user_id' => $connection->default_inquiry_assignee_user_id,
            'created_at' => $connection->created_at, 'secret_configured' => true,
            'api_token_configured' => (bool) $connection->respond_io_api_token,
        ] : null]);
    }

    public function createConnection(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $company = app(SingleCompanyScope::class)->activeDefaultCompany();
        abort_unless($company, 409, 'No active business is configured.');
        abort_if($this->companyConnection(), 409, 'A respond.io connection already exists for this business.');
        $secret = bin2hex(random_bytes(32));
        $connection = IntegrationConnection::create([
            'company_id' => $company->id, 'provider' => 'respond_io', 'name' => trim($data['name']),
            'webhook_secret_hash' => hash('sha256', $secret), 'created_user_id' => $request->user()->id,
        ]);

        return response()->json(['status' => 'success', 'data' => [
            'id' => $connection->id, 'name' => $connection->name,
            'webhook_url' => $this->webhookUrl($connection, $secret),
            'secret_shown_once' => true,
        ]], 201);
    }

    public function updateConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'enabled' => ['sometimes', 'required', 'boolean'],
            'default_inquiry_assignee_user_id' => ['sometimes', 'nullable', 'uuid', function ($attribute, $value, $fail): void {
                $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
                if ($value && (! $companyId || ! app(InquiryAssignmentService::class)->eligibleAssignees((string) $companyId)->where('users.id', $value)->exists())) {
                    $fail('Choose an active employee in the current company.');
                }
            }],
            'api_token' => ['sometimes', 'required', 'string', 'min:10', 'max:4096'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection, 404, 'No respond.io connection is configured.');
        if (array_key_exists('api_token', $data)) {
            try {
                $verified = Http::withToken($data['api_token'])->acceptJson()->timeout(10)
                    ->get('https://api.respond.io/v2/space/user', ['limit' => 1]);
            } catch (\Throwable $exception) {
                Log::warning('Respond.io API token verification request failed', ['connection_id' => $connection->id, 'error' => $exception->getMessage()]);
                return response()->json(['status' => 'error', 'message' => 'Respond.io could not be reached. Try again.'], 503);
            }
            if (! $verified->successful()) {
                return response()->json(['status' => 'error', 'message' => 'Respond.io rejected this API token. Check the token and workspace plan.'], 422);
            }
            $connection->respond_io_api_token = $data['api_token'];
            $connection->save();
            unset($data['api_token']);
        }
        $connection->update($data);
        return response()->json(['status' => 'success', 'data' => [
            'id' => $connection->id, 'name' => $connection->name, 'enabled' => $connection->enabled,
            'default_inquiry_assignee_user_id' => $connection->default_inquiry_assignee_user_id,
            'api_token_configured' => (bool) $connection->respond_io_api_token,
        ]]);
    }

    public function assigneeOptions(InquiryAssignmentService $assignments): JsonResponse
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        return response()->json(['status' => 'success', 'data' => ($companyId ? $assignments->eligibleAssignees((string) $companyId)
            ->select(['users.id', 'users.first_name', 'users.last_name', 'staff.code'])
            ->get()
            ->map(fn ($employee) => [
                'id' => (string) $employee->id,
                'name' => trim($employee->first_name . ' ' . $employee->last_name),
                'code' => $employee->code,
            ]) : collect())]);
    }

    public function agentMappings(InquiryAssignmentService $assignments): JsonResponse
    {
        $connection = $this->companyConnection();
        if (! $connection) return response()->json(['status' => 'success', 'data' => []]);
        $eligibleIds = $assignments->eligibleAssignees((string) $connection->company_id)->pluck('users.id')
            ->map(fn ($id) => (string) $id)->flip();
        $mappings = DB::table('respond_io_agent_mappings as mapping')
            ->join('users as employee', 'employee.id', '=', 'mapping.erp_user_id')
            ->leftJoin('users as mapper', 'mapper.id', '=', 'mapping.mapped_by_user_id')
            ->where('mapping.connection_id', $connection->id)->where('mapping.company_id', $connection->company_id)
            ->orderBy('mapping.external_agent_name')->orderBy('mapping.external_agent_email')
            ->get(['mapping.id', 'mapping.external_agent_id', 'mapping.external_agent_name', 'mapping.external_agent_email',
                'mapping.erp_user_id', 'mapping.updated_at', 'employee.first_name', 'employee.last_name', 'employee.email as employee_email',
                'mapper.first_name as mapper_first_name', 'mapper.last_name as mapper_last_name'])
            ->map(fn ($mapping) => [
                'id' => (string) $mapping->id,
                'external_agent_id' => $mapping->external_agent_id,
                'external_agent_name' => $mapping->external_agent_name,
                'external_agent_email' => $mapping->external_agent_email,
                'erp_user_id' => (string) $mapping->erp_user_id,
                'employee_name' => trim($mapping->first_name.' '.$mapping->last_name),
                'employee_email' => $mapping->employee_email,
                'employee_active' => $eligibleIds->has((string) $mapping->erp_user_id),
                'mapped_by' => trim(($mapping->mapper_first_name ?? '').' '.($mapping->mapper_last_name ?? '')) ?: null,
                'updated_at' => $mapping->updated_at,
            ])->values();
        return response()->json(['status' => 'success', 'data' => $mappings]);
    }

    public function removeAgentMapping(string $mapping): JsonResponse
    {
        $connection = $this->companyConnection();
        abort_unless($connection, 404, 'No respond.io connection is configured.');
        $deleted = DB::table('respond_io_agent_mappings')->where('id', $mapping)
            ->where('connection_id', $connection->id)->where('company_id', $connection->company_id)->delete();
        abort_unless($deleted, 404, 'This Respond.io employee mapping no longer exists.');
        return response()->json(['status' => 'success', 'message' => 'Respond.io employee mapping removed.']);
    }

    public function rotateSecret(): JsonResponse
    {
        $connection = $this->companyConnection();
        abort_unless($connection, 404, 'No respond.io connection is configured.');
        $secret = bin2hex(random_bytes(32));
        $connection->update(['webhook_secret_hash' => hash('sha256', $secret)]);
        return response()->json(['status' => 'success', 'data' => [
            'webhook_url' => $this->webhookUrl($connection, $secret), 'secret_shown_once' => true,
        ]]);
    }

    public function events(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:received,processed,reply_failed,not_a_lead'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $connection = $this->companyConnection();
        abort_unless($connection, 404, 'No respond.io connection is configured.');
        $events = IntegrationWebhookEvent::query()->where('company_id', $connection->company_id)->where('provider', 'respond_io')
            ->when($request->get('status') === 'reply_failed', fn ($query) => $query->whereIn('outbound_status', ['failed', 'uncertain']))
            ->when($request->filled('status') && $request->get('status') !== 'reply_failed', fn ($query) => $query->where('status', $request->get('status')))
            ->latest('received_at')->paginate($request->integer('per_page', 25));
        $events->setCollection($events->getCollection()->map(fn (IntegrationWebhookEvent $event): array => [
            'id' => $event->id, 'event_type' => $event->event_type, 'status' => $event->status,
            'reply_status' => $event->outbound_status, 'received_at' => $event->received_at,
            'linked_inquiry_id' => $event->linked_inquiry_id, 'linked_booking_id' => $event->linked_booking_id,
            'resolution_code' => $event->resolution_code,
        ]));
        return response()->json(['status' => 'success', 'data' => $events]);
    }

    public function event(IntegrationWebhookEvent $event): JsonResponse
    {
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);
        $linkedBookingNumber = $event->linked_booking_id
            ? $this->companyBookingQuery((string) $connection->company_id, (string) $event->linked_booking_id)->value('booking_number')
            : ($event->linked_inquiry_id
            ? Inquiry::query()->where('company_id', $connection->company_id)->whereKey($event->linked_inquiry_id)
                ->with('booking:id,booking_number')->first()?->booking?->booking_number
            : null);

        return response()->json(['status' => 'success', 'data' => [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'status' => $event->status,
            'received_at' => $event->received_at,
            'linked_inquiry_id' => $event->linked_inquiry_id,
            'linked_booking_id' => $event->linked_booking_id,
            'external_contact_id' => $event->external_contact_id,
            'external_conversation_id' => $event->external_conversation_id,
            'external_message_id' => $event->external_message_id,
            'can_link_to_inquiry' => $this->isRespondIoMessageEvent($event->event_type, $event->message_direction),
            'resolution_code' => $event->resolution_code,
            'resolution_note' => $event->resolution_note,
            'booking_number' => $linkedBookingNumber,
            'processed_at' => $event->processed_at,
            'payload' => $event->payload,
        ]]);
    }

    public function bookingRequestContext(IntegrationWebhookEvent $event): JsonResponse
    {
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);
        abort_unless($event->status === 'received' && ! $event->linked_inquiry_id && ! $event->linked_booking_id
            && $this->isRespondIoMessageEvent($event->event_type, $event->message_direction), 409, 'This message has already been reviewed.');

        $root = is_array($event->payload) ? $event->payload : [];
        $data = $root['data'] ?? $root['payload'] ?? [];
        $contact = $root['contact'] ?? ($data['contact'] ?? []);
        $firstName = trim((string) ($contact['firstName'] ?? $contact['first_name'] ?? ''));
        $lastName = trim((string) ($contact['lastName'] ?? $contact['last_name'] ?? ''));
        $name = trim((string) ($contact['name'] ?? trim($firstName.' '.$lastName)));

        return response()->json(['status' => 'success', 'data' => [
            'event_id' => (string) $event->id,
            'name' => $name,
            'email' => trim((string) ($contact['email'] ?? $data['email'] ?? $root['email'] ?? '')),
            'phone' => trim((string) ($contact['phone'] ?? $data['phone'] ?? $root['phone'] ?? '')),
        ]]);
    }

    public function createBookingRequest(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $request->validate([
            'customer_id' => ['required', 'uuid'],
            'booking_items' => ['required', 'array', 'min:1', 'max:10'],
            'booking_items.*.service_type_id' => ['required', 'uuid'],
            'booking_items.*.from_date' => ['required', 'date'],
            'booking_items.*.from_time' => ['required', 'date_format:H:i'],
            'booking_items.*.to_date' => ['nullable', 'date', 'after_or_equal:booking_items.*.from_date'],
            'booking_items.*.to_time' => ['nullable', 'date_format:H:i'],
            'booking_items.*.pickup_location' => ['nullable', 'array'],
            'booking_items.*.dropoff_location' => ['nullable', 'array'],
            'passenger_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'luggage_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'special_requirements' => ['nullable', 'string', 'max:5000'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);

        $bookingFlow = app(BookingFlowService::class);
        $params = $bookingFlow->normalizeDynamicCalculationParams($request->all());
        $params = $bookingFlow->normalizeCorporateEmployeeReferences($params);
        $result = app(BookingFlowService::class)->createRespondIoBookingInquiry(
            $params,
            (string) $event->id,
            (string) $request->user()->id,
        );

        return response()->json(['status' => 'success', 'duplicate' => $result['duplicate'],
            'data' => new BookingFlowResource($result['booking']),
            'message' => $result['duplicate'] ? 'This message already has a booking request.' : 'Booking request added to the booking queue.']);
    }

    public function inquiryOptions(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:120'],
            'selected_id' => ['nullable', 'uuid'], 'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);

        $inquiries = Inquiry::query()->where('company_id', $connection->company_id)->whereNull('deleted_at')
            ->when(empty($data['selected_id']), fn ($query) => $query->where(fn ($active) => $active->whereIn('status', ['open', 'in_progress'])
                ->orWhereHas('booking', fn ($booking) => \App\Services\CompanyBookingScope::constrain($booking, 'bookings', (string) $connection->company_id))))
            ->when(! empty($data['customer_id']), fn ($query) => $query->where('customer_id', $data['customer_id']))
            ->when(! empty($data['selected_id']), fn ($query) => $query->whereKey($data['selected_id']))
            ->when(empty($data['selected_id']) && ! empty($data['search']), function ($query) use ($data, $connection): void {
                $term = '%'.addcslashes(trim($data['search']), '%_\\').'%';
                $query->where(fn ($search) => $search->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('subject', 'like', $term)
                    ->orWhereHas('booking', function ($booking) use ($connection, $term): void {
                        $booking->where('booking_number', 'like', $term);
                        \App\Services\CompanyBookingScope::constrain($booking, 'bookings', (string) $connection->company_id);
                    }));
            })
            ->with(['booking' => function ($booking) use ($connection): void {
                \App\Services\CompanyBookingScope::constrain($booking, 'bookings', (string) $connection->company_id);
                $booking->select(['id', 'booking_number']);
            }])
            ->select(['id', 'name', 'subject', 'status', 'customer_id', 'booking_id', 'created_at'])
            ->orderByDesc('created_at')->paginate($data['per_page'] ?? 25);

        $inquiries->setCollection($inquiries->getCollection()->map(fn (Inquiry $inquiry): array => [
            'value' => (string) $inquiry->id,
            'label' => trim(($inquiry->name ?: 'Customer request').($inquiry->subject ? ' · '.$inquiry->subject : '').($inquiry->booking?->booking_number ? ' · '.$inquiry->booking->booking_number : '')),
            'metadata' => ['Status' => Str::headline((string) $inquiry->status), 'Received' => $inquiry->created_at],
        ]));

        return response()->json(['status' => 'success', 'data' => $inquiries]);
    }

    public function linkInquiry(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate(['inquiry_id' => ['required', 'uuid']]);
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);
        abort_unless($this->isRespondIoMessageEvent($event->event_type, $event->message_direction), 409, 'Only incoming Respond.io messages can be linked to an inquiry.');

        $duplicate = DB::transaction(function () use ($request, $data, $connection, $event): bool {
            abort_unless(app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id === $connection->company_id, 409, 'Business changed; retry event review.');
            $lockedEvent = IntegrationWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedEvent->connection_id === $connection->id && $lockedEvent->company_id === $connection->company_id, 404);
            if ($lockedEvent->status === 'processed' && (string) $lockedEvent->linked_inquiry_id === (string) $data['inquiry_id']) return true;
            abort_unless($lockedEvent->status === 'received' && $this->isRespondIoMessageEvent($lockedEvent->event_type, $lockedEvent->message_direction), 409, 'This message has already been reviewed.');
            $inquiry = Inquiry::query()->where('company_id', $connection->company_id)->whereNull('deleted_at')
                ->where(fn ($active) => $active->whereIn('status', ['open', 'in_progress'])
                    ->orWhereHas('booking', fn ($booking) => \App\Services\CompanyBookingScope::constrain($booking, 'bookings', (string) $connection->company_id)))
                ->lockForUpdate()->findOrFail($data['inquiry_id']);
            $lockedEvent->forceFill([
                'linked_inquiry_id' => $inquiry->id,
                'status' => 'processed',
                'resolution_code' => 'conversation_update',
                'resolution_note' => 'Linked to existing inquiry by manager review.',
                'processed_by_user_id' => $request->user()->id,
                'processed_at' => now(),
            ])->save();
            $this->linkSiblingConversationMessages($lockedEvent, $inquiry, (string) $request->user()->id);
            return false;
        });

        return response()->json(['status' => 'success', 'duplicate' => $duplicate, 'message' => 'Message linked to the existing inquiry.', 'data' => [
            'id' => (string) $event->id, 'status' => 'processed', 'linked_inquiry_id' => (string) $data['inquiry_id'],
            'resolution_code' => 'conversation_update',
        ]]);
    }

    public function lookupContact(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection && $connection->enabled && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);
        abort_unless($connection->respond_io_api_token, 409, 'Connect a Respond.io Developer API token first.');

        $identifier = ! empty($data['email'])
            ? 'email:'.strtolower(trim($data['email']))
            : (! empty($data['phone']) ? 'phone:'.trim($data['phone']) : ($event->external_contact_id ? 'id:'.$event->external_contact_id : null));
        abort_unless($identifier, 422, 'This event has no contact email, phone or Respond.io contact ID to look up.');
        try {
            $response = Http::withToken($connection->respond_io_api_token)->acceptJson()->timeout(10)
                ->get('https://api.respond.io/v2/contact/'.rawurlencode($identifier));
        } catch (\Throwable $exception) {
            Log::warning('Respond.io contact lookup request failed', ['connection_id' => $connection->id, 'event_id' => $event->id, 'error' => $exception->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Respond.io could not be reached. Try again.'], 503);
        }

        if ($response->status() === 404) {
            return response()->json(['status' => 'error', 'message' => 'No Respond.io contact matched this email or phone.'], 404);
        }
        if ($response->status() === 401 || $response->status() === 403) {
            return response()->json(['status' => 'error', 'message' => 'Respond.io rejected the saved API token. Verify it in integration settings.'], 409);
        }
        if ($response->status() === 429) {
            return response()->json(['status' => 'error', 'message' => 'Respond.io is receiving too many requests. Try again shortly.'], 429);
        }
        if (! $response->successful()) {
            return response()->json(['status' => 'error', 'message' => 'Respond.io contact lookup failed. Try again.'], 502);
        }

        $contact = $response->json();
        abort_unless(is_array($contact), 502, 'Respond.io returned an invalid contact response.');
        $contactEmail = strtolower(trim((string) ($contact['email'] ?? '')));
        $contactEmail = filter_var($contactEmail, FILTER_VALIDATE_EMAIL) ? $contactEmail : '';
        $contactPhone = PhoneNumberMatch::normalized((string) ($contact['phone'] ?? ''));
        $customerMatches = DB::table('customers')->join('users', 'users.id', '=', 'customers.user_id')
            ->whereNull('customers.deleted_at')->whereNull('users.deleted_at')
            ->where(function ($identity) use ($contactEmail, $contactPhone): void {
                if ($contactEmail !== '') $identity->orWhereRaw('LOWER(TRIM(users.email)) = ?', [$contactEmail]);
                if ($contactPhone !== '') $identity->orWhereRaw(PhoneNumberMatch::sqlExpression('users.phone').' = ?', [$contactPhone]);
            });
        \App\Services\CompanyCustomerScope::constrain($customerMatches, 'customers', (string) $connection->company_id);
        $customerMatches = $contactEmail === '' && $contactPhone === '' ? collect()
            : $customerMatches->orderBy('customers.id')->limit(2)
                ->get(['customers.id', 'customers.code', 'users.first_name', 'users.last_name', 'users.email', 'users.phone']);
        $providerAssignee = is_array($contact['assignee'] ?? null) ? $contact['assignee'] : null;
        $providerAssigneeEmail = strtolower(trim((string) ($providerAssignee['email'] ?? '')));
        $providerAssigneeId = $this->respondIoIdentifier($providerAssignee['id'] ?? null);
        $savedMapping = $providerAssigneeId ? DB::table('respond_io_agent_mappings as mapping')
            ->join('staff', 'staff.user_id', '=', 'mapping.erp_user_id')
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->where('mapping.connection_id', $connection->id)->where('mapping.external_agent_id', $providerAssigneeId)
            ->where('mapping.company_id', $connection->company_id)->where('staff.company_id', $connection->company_id)
            ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')
            ->whereNull('users.deleted_at')->where('users.is_active', true)
            ->orderBy('staff.id')->limit(2)->get(['users.id', 'users.first_name', 'users.last_name', 'staff.code']) : collect();
        $employeeMatches = $providerAssigneeEmail !== ''
            ? DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
                ->where('staff.company_id', $connection->company_id)->whereNull('staff.deleted_at')
                ->whereNull('staff.employment_ended_at')->whereNull('users.deleted_at')->where('users.is_active', true)
                ->whereRaw('LOWER(users.email) = ?', [$providerAssigneeEmail])
                ->orderBy('users.id')->limit(2)->get(['users.id', 'users.first_name', 'users.last_name', 'staff.code'])
            : collect();
        return response()->json(['status' => 'success', 'data' => ['contact' => [
            'id' => $contact['id'] ?? null,
            'firstName' => $contact['firstName'] ?? '',
            'lastName' => $contact['lastName'] ?? '',
            'email' => $contact['email'] ?? null,
            'phone' => $contact['phone'] ?? null,
            'matched_customer' => $customerMatches->count() === 1 ? [
                'id' => (string) $customerMatches[0]->id,
                'name' => trim($customerMatches[0]->first_name.' '.$customerMatches[0]->last_name),
                'code' => $customerMatches[0]->code,
                'matched_by' => array_values(array_filter([
                    $contactEmail !== '' && strtolower(trim((string) $customerMatches[0]->email)) === $contactEmail ? 'email' : null,
                    $contactPhone !== '' && PhoneNumberMatch::normalized((string) $customerMatches[0]->phone) === $contactPhone ? 'phone' : null,
                ])),
            ] : null,
            'customer_match_ambiguous' => $customerMatches->count() > 1,
            'assignee' => $providerAssignee ? [
                'id' => $providerAssigneeId,
                'firstName' => $providerAssignee['firstName'] ?? '',
                'lastName' => $providerAssignee['lastName'] ?? '',
                'email' => $providerAssigneeEmail ?: null,
            ] : null,
            'matched_employee' => $employeeMatches->count() === 1 ? [
                'id' => (string) $employeeMatches[0]->id,
                'name' => trim($employeeMatches[0]->first_name.' '.$employeeMatches[0]->last_name),
                'code' => $employeeMatches[0]->code,
            ] : null,
            'mapped_employee' => $savedMapping->count() === 1 ? [
                'id' => (string) $savedMapping[0]->id,
                'name' => trim($savedMapping[0]->first_name.' '.$savedMapping[0]->last_name),
                'code' => $savedMapping[0]->code,
            ] : null,
        ]]]);
    }

    public function mapContactAssignee(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate([
            'erp_user_id' => ['required', 'uuid'],
            'external_agent_id' => ['required', 'string', 'max:120'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection && $connection->enabled && $connection->respond_io_api_token
            && $event->connection_id === $connection->id && $event->company_id === $connection->company_id
            && $event->provider === 'respond_io' && $event->external_contact_id, 404);
        abort_unless(app(InquiryAssignmentService::class)->eligibleAssignees((string) $connection->company_id)
            ->where('users.id', $data['erp_user_id'])->exists(), 422, 'Choose an active employee in this business.');

        try {
            $response = Http::withToken($connection->respond_io_api_token)->acceptJson()->timeout(10)
                ->get('https://api.respond.io/v2/contact/'.rawurlencode('id:'.$event->external_contact_id));
        } catch (\Throwable $exception) {
            Log::warning('Respond.io agent mapping lookup failed', ['connection_id' => $connection->id, 'event_id' => $event->id]);
            return response()->json(['status' => 'error', 'message' => 'Respond.io could not be reached. Try again.'], 503);
        }
        if (! $response->successful()) {
            return response()->json(['status' => 'error', 'message' => 'The current Respond.io contact owner could not be verified. Refresh the contact and try again.'], 409);
        }
        $contact = $response->json();
        $agent = is_array($contact) && is_array($contact['assignee'] ?? null) ? $contact['assignee'] : null;
        $agentId = $this->respondIoIdentifier($agent['id'] ?? null);
        abort_unless($agentId, 422, 'This contact has no identifiable Respond.io agent to map.');
        abort_unless(hash_equals($agentId, trim($data['external_agent_id'])), 409, 'The Respond.io contact owner changed. Refresh the contact before saving the mapping.');
        $now = now();
        DB::transaction(function () use ($connection, $agent, $agentId, $data, $request, $now): void {
            abort_unless(app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id === $connection->company_id, 409, 'Company changed; retry mapping.');
            IntegrationConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $mapping = DB::table('respond_io_agent_mappings')->where('connection_id', $connection->id)
                ->where('external_agent_id', $agentId)->lockForUpdate()->first();
            $values = [
                'company_id' => $connection->company_id,
                'external_agent_name' => trim((string) (($agent['firstName'] ?? '').' '.($agent['lastName'] ?? ''))) ?: null,
                'external_agent_email' => is_string($agent['email'] ?? null) ? Str::limit(trim($agent['email']), 255, '') : null,
                'erp_user_id' => $data['erp_user_id'], 'mapped_by_user_id' => $request->user()->id, 'updated_at' => $now,
            ];
            if ($mapping) {
                DB::table('respond_io_agent_mappings')->where('id', $mapping->id)->update($values);
            } else {
                DB::table('respond_io_agent_mappings')->insert([
                    'id' => (string) Str::uuid(), 'connection_id' => $connection->id,
                    'external_agent_id' => $agentId, 'created_at' => $now, ...$values,
                ]);
            }
        });

        $employee = app(InquiryAssignmentService::class)->eligibleAssignees((string) $connection->company_id)
            ->where('users.id', $data['erp_user_id'])->first();
        abort_unless($employee, 409, 'Employee access changed. Refresh and try again.');
        return response()->json(['status' => 'success', 'message' => 'Respond.io agent is mapped to this employee for future contact reviews.', 'data' => [
            'agent_id' => $agentId,
            'employee' => ['id' => (string) $employee->id, 'name' => trim($employee->first_name.' '.$employee->last_name), 'code' => $employee->code],
        ]]);
    }

    public function contactHistory(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate(['cursor_id' => ['nullable', 'string', 'max:255']]);
        $connection = $this->companyConnection();
        abort_unless($connection && $connection->enabled && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);
        abort_unless($connection->respond_io_api_token, 409, 'Connect a Respond.io Developer API token first.');
        abort_unless($event->external_contact_id, 422, 'This event has no Respond.io contact ID.');

        $identifier = rawurlencode('id:'.$event->external_contact_id);
        $query = ['limit' => 50];
        if (! empty($data['cursor_id'])) $query['cursorId'] = $data['cursor_id'];
        $messages = [];
        $hasMore = false;
        $nextCursor = null;

        // shortcut: load at most 100 messages per request, fetch another cursor page when staff need older history.
        for ($page = 0; $page < 2; $page++) {
            try {
                $response = Http::withToken($connection->respond_io_api_token)->acceptJson()->timeout(10)
                    ->get('https://api.respond.io/v2/contact/'.$identifier.'/message/list', $query);
            } catch (\Throwable $exception) {
                Log::warning('Respond.io message history request failed', ['connection_id' => $connection->id, 'event_id' => $event->id]);
                return response()->json(['status' => 'error', 'message' => 'Respond.io could not be reached. Try again.'], 503);
            }

            if (! $response->successful()) {
                $status = $response->status();
                $message = match ($status) {
                    401, 403 => 'Respond.io rejected the saved API token. Verify it in integration settings.',
                    404 => 'No Respond.io message history was found for this contact.',
                    429 => 'Respond.io is receiving too many requests. Try again shortly.',
                    default => 'Respond.io message history could not be loaded. Try again.',
                };
                return response()->json(['status' => 'error', 'message' => $message], in_array($status, [401, 403, 404, 429], true) ? ($status === 401 || $status === 403 ? 409 : $status) : 502);
            }

            $body = $response->json();
            $items = data_get($body, 'items', data_get($body, 'data.items', []));
            abort_unless(is_array($items), 502, 'Respond.io returned an invalid message history response.');
            foreach ($items as $item) {
                if (! is_array($item)) continue;
                $message = $item['message'] ?? null;
                $type = is_array($message) && is_string($message['type'] ?? null) ? $message['type'] : null;
                $text = is_string($message) ? $message : (is_array($message) && is_string($message['text'] ?? null)
                    ? $message['text'] : ($type ? '['.Str::headline((string) $type).' message]' : '[Message]'));
                $sender = is_array($item['sender'] ?? null) ? ($item['sender']['source'] ?? null) : null;
                $messages[] = [
                    'id' => $this->respondIoIdentifier($item['messageId'] ?? $item['id'] ?? null) ?? '',
                    'traffic' => in_array($item['traffic'] ?? null, ['incoming', 'outgoing'], true) ? $item['traffic'] : 'unknown',
                    'sender' => is_string($sender) ? Str::limit($sender, 80, '') : null,
                    'sent_at' => is_string($item['createdAt'] ?? null) ? $item['createdAt'] : (is_string($item['timestamp'] ?? null) ? $item['timestamp'] : null),
                    'conversation_id' => $this->respondIoIdentifier($item['conversationId'] ?? $item['conversation_id'] ?? null),
                    'text' => Str::limit($text, 20000, '…'),
                    'type' => is_string($type) ? Str::limit($type, 40, '') : null,
                ];
            }

            $next = data_get($body, 'pagination.next');
            if (! is_string($next) || $next === '') {
                $hasMore = false;
                $nextCursor = null;
                break;
            }
            $hasMore = true;
            $nextCursor = null;
            parse_str((string) (parse_url($next, PHP_URL_QUERY) ?? ''), $nextQuery);
            if (! isset($nextQuery['cursorId']) || (! is_string($nextQuery['cursorId']) && ! is_numeric($nextQuery['cursorId']))) break;
            $nextCursor = (string) $nextQuery['cursorId'];
            if ($page === 1) break;

            // Follow only the cursor; never request a provider-supplied URL.
            $query = ['limit' => 50, 'cursorId' => $nextCursor];
        }

        return response()->json(['status' => 'success', 'data' => [
            'messages' => $messages,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'conversation_id' => $event->external_conversation_id,
        ]]);
    }

    public function syncContactHistory(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'message_ids' => ['required', 'array', 'min:1', 'max:100'],
            'message_ids.*' => ['required', 'string', 'max:120', 'distinct'],
            'cursor_id' => ['nullable', 'string', 'max:255'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection && $connection->enabled && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);
        $customerQuery = Customer::query()->whereKey($data['customer_id']);
        \App\Services\CompanyCustomerScope::constrain($customerQuery, 'customers', $connection->company_id);
        $customer = $customerQuery->firstOrFail();

        $history = $this->contactHistory($request, $event);
        if ($history->getStatusCode() !== 200) return $history;
        $selectedIds = $data['message_ids'];
        $messages = collect($history->getData(true)['data']['messages'] ?? [])
            ->filter(fn ($message) => in_array($message['id'] ?? null, $selectedIds, true))->values()->all();
        $foundIds = array_column($messages, 'id');
        $notReturned = count(array_diff($selectedIds, $foundIds));
        $now = now();
        $imported = 0;
        $alreadyLinked = 0;
        $conflicts = 0;

        DB::transaction(function () use ($messages, $connection, $event, $customer, $request, $now, &$imported, &$alreadyLinked, &$conflicts): void {
            foreach ($messages as $message) {
                if (! is_array($message) || empty($message['id'])) continue;
                $record = IntegrationContactMessage::query()->where('connection_id', $connection->id)
                    ->where('external_message_id', $message['id'])->lockForUpdate()->first();
                if ($record && $record->linked_customer_id && (string) $record->linked_customer_id !== (string) $customer->id) {
                    $conflicts++;
                    continue;
                }
                $sentAt = null;
                if (is_string($message['sent_at'] ?? null)) {
                    try { $sentAt = Carbon::parse($message['sent_at']); } catch (\Throwable) { }
                }
                $record ??= new IntegrationContactMessage([
                    'connection_id' => $connection->id, 'company_id' => $connection->company_id,
                    'provider' => 'respond_io', 'external_contact_id' => $event->external_contact_id,
                    'external_message_id' => $message['id'],
                ]);
                $wasLinked = (string) $record->linked_customer_id === (string) $customer->id;
                $record->fill([
                    'traffic' => $message['traffic'] ?? 'unknown', 'sender_source' => $message['sender'] ?? null,
                    'message_type' => $message['type'] ?? null, 'content' => $message['text'] ?? null,
                    'sent_at' => $sentAt, 'synced_at' => $now, 'linked_customer_id' => $customer->id,
                    'linked_by_user_id' => $wasLinked ? $record->linked_by_user_id : $request->user()->id,
                    'linked_at' => $wasLinked ? $record->linked_at : $now,
                ])->save();
                $wasLinked ? $alreadyLinked++ : $imported++;
            }
        });

        return response()->json(['status' => 'success', 'data' => [
            'imported' => $imported,
            'already_linked' => $alreadyLinked,
            'conflicts' => $conflicts,
            'not_returned' => $notReturned,
            'has_more' => (bool) ($history->getData(true)['data']['has_more'] ?? false),
            'next_cursor' => $history->getData(true)['data']['next_cursor'] ?? null,
        ], 'message' => 'Recent Respond.io messages were saved to the selected customer history.']);
    }

    public function sendInquiryReply(Request $request, Inquiry $inquiry): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:7000']]);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id;
        abort_unless($companyId && (string) $inquiry->company_id === (string) $companyId
            && $inquiry->source === 'respond_io', 404);
        $connection = $this->companyConnection();
        abort_unless($connection && $connection->enabled && $connection->respond_io_api_token, 409,
            'Enable the Respond.io connection and verify its Developer API token first.');

        $event = DB::transaction(function () use ($inquiry, $connection, $request, $data): IntegrationWebhookEvent {
            $event = IntegrationWebhookEvent::query()->where('company_id', $connection->company_id)
                ->where('connection_id', $connection->id)->where('provider', 'respond_io')
                ->where('linked_inquiry_id', $inquiry->id)->incomingMessage()
                ->latest('received_at')->lockForUpdate()->firstOrFail();
            abort_unless($event->external_contact_id && $event->status === 'processed', 409,
                'The linked Respond.io contact is unavailable or the message has not been reviewed.');
            abort_if(in_array($event->outbound_status, ['sending', 'sent', 'uncertain'], true), 409,
                'A reply for this message was already sent or its delivery is uncertain. Check Respond.io before trying again.');
            $event->forceFill([
                'outbound_message' => trim($data['message']),
                'outbound_status' => 'sending',
                'outbound_message_id' => null,
                'outbound_sent_at' => null,
                'outbound_by_user_id' => $request->user()->id,
            ])->save();
            return $event;
        });

        try {
            $response = Http::withToken($connection->respond_io_api_token)->acceptJson()->timeout(15)
                ->post('https://api.respond.io/v2/contact/'.rawurlencode('id:'.$event->external_contact_id).'/message', [
                    'message' => ['type' => 'text', 'text' => trim($data['message'])],
                ]);
        } catch (\Throwable $exception) {
            $event->forceFill(['outbound_status' => 'uncertain'])->save();
            Log::warning('Respond.io reply delivery result is uncertain', [
                'connection_id' => $connection->id, 'event_id' => $event->id, 'inquiry_id' => $inquiry->id,
            ]);
            return response()->json(['status' => 'error', 'message' => 'Delivery could not be confirmed. Check Respond.io before sending again.'], 504);
        }

        if (! $response->successful()) {
            $uncertain = $response->serverError();
            $event->forceFill(['outbound_status' => $uncertain ? 'uncertain' : 'failed'])->save();
            return response()->json(['status' => 'error', 'message' => $uncertain
                ? 'Delivery could not be confirmed. Check Respond.io before sending again.'
                : 'Respond.io did not accept the reply. Correct the issue and try again.'], $uncertain ? 502 : 422);
        }

        $sentAt = now();
        DB::transaction(function () use ($connection, $event, $inquiry, $response, $sentAt, $request): void {
            $lockedEvent = IntegrationWebhookEvent::query()->where('company_id', $connection->company_id)
                ->lockForUpdate()->findOrFail($event->id);
            $lockedEvent->forceFill([
                'outbound_status' => 'sent',
                'outbound_message_id' => (string) ($response->json('messageId') ?? $response->json('id') ?? ''),
                'outbound_sent_at' => $sentAt,
            ])->save();

            $lockedInquiry = Inquiry::query()->where('company_id', $connection->company_id)
                ->lockForUpdate()->findOrFail($inquiry->id);
            $changes = ['responded_at' => $sentAt, 'updated_user_id' => $request->user()->id];
            if (! $lockedInquiry->first_response_sent_at) $changes['first_response_sent_at'] = $sentAt;
            $lockedInquiry->update($changes);
        });

        return response()->json(['status' => 'success', 'data' => [
            'status' => $event->outbound_status,
            'message_id' => $event->outbound_message_id,
            'sent_at' => $event->outbound_sent_at,
        ], 'message' => 'Reply sent through Respond.io.']);
    }

    public function createInquiry(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate([
            'confirm_lead' => ['required', 'accepted'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'inquiry_type' => ['required', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:20000'],
            'customer_id' => ['nullable', 'uuid'],
            'booking_id' => ['nullable', 'uuid'],
            'assigned_to' => ['nullable', 'uuid'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);

        [$inquiry, $duplicate] = DB::transaction(function () use ($request, $data, $connection, $event): array {
            abort_unless(app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id === $connection->company_id, 409, 'Company changed; retry event conversion.');
            $locked = IntegrationWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->connection_id === $connection->id && $locked->company_id === $connection->company_id, 404);
            if ($locked->status === 'processed' && $locked->linked_inquiry_id) {
                abort_unless($locked->resolution_code !== 'support_request', 409, 'This event was already routed as a support request.');
                return [Inquiry::query()->where('company_id', $connection->company_id)->whereKey($locked->linked_inquiry_id)->firstOrFail(), true];
            }
            abort_unless($locked->status === 'received', 409, 'Only received Respond.io events can be converted into inquiries.');
            abort_unless($this->isRespondIoMessageEvent($locked->event_type, $locked->message_direction), 409, 'Only incoming message events can be added to an inquiry.');
            $customer = $this->selectedCompanyCustomer($data['customer_id'] ?? null, (string) $connection->company_id);
            $booking = $this->selectedCompanyBooking($data['booking_id'] ?? null, (string) $connection->company_id, $customer?->id);
            if ($booking && ! $customer) $customer = Customer::query()->findOrFail($booking->customer_id);
            [$assigneeId, $assignmentReason] = $this->resolveInquiryAssignee($connection, $customer, true);
            if (! empty($data['assigned_to'])) {
                $eligible = app(InquiryAssignmentService::class)->eligibleAssignees((string) $connection->company_id)
                    ->where('users.id', $data['assigned_to'])->lockForUpdate()->exists();
                abort_unless($eligible, 422, 'Select an active employee in this business.');
                $assigneeId = $data['assigned_to'];
                $assignmentReason = 'Assigned by the reviewer during Respond.io lead review.';
            }

            $inquiry = Inquiry::createWithUniqueNumber([
                'company_id' => $connection->company_id,
                'customer_id' => $customer?->id,
                'booking_id' => $booking?->id,
                'name' => trim($data['name']),
                'assigned_to' => $assigneeId,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'inquiry_type' => trim($data['inquiry_type']),
                'subject' => isset($data['subject']) ? trim($data['subject']) : null,
                'message' => $data['message'] ?? null,
                'status' => 'open',
                'priority' => 'medium',
                'source' => 'respond_io',
                'created_user_id' => $request->user()->id,
                'payload' => ['integration' => [
                    'provider' => 'respond_io',
                    'webhook_event_id' => $locked->id,
                    'event_type' => $locked->event_type,
                    'lead_confirmed' => true,
                ]],
            ]);
            $this->recordDefaultInquiryAssignment($locked, $inquiry, $assignmentReason, (string) $request->user()->id);
            $this->linkSiblingConversationMessages($locked, $inquiry, (string) $request->user()->id);
            $locked->forceFill([
                'linked_inquiry_id' => $inquiry->id,
                'processed_by_user_id' => $request->user()->id,
                'processed_at' => now(),
                'status' => 'processed',
                'resolution_code' => 'sales_lead',
            ])->save();

            return [$inquiry, false];
        });

        if (! $duplicate && ! $inquiry->assigned_to) $this->notifyUnassignedInquiry($connection, $inquiry);
        $assignmentSync = ! $duplicate && $inquiry->assigned_to
            ? app(\App\Services\RespondIoInquiryAssignmentSync::class)->sync($inquiry)
            : ['status' => 'not_applicable'];

        return response()->json(['status' => 'success', 'duplicate' => $duplicate, 'data' => [
            'inquiry' => new InquiryResource($inquiry->load('booking')),
            'respond_io_assignment_sync' => $assignmentSync,
        ]], $duplicate ? 200 : 201);
    }

    public function createSupportRequest(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate([
            'confirm_support' => ['required', 'accepted'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:20000'],
            'customer_id' => ['nullable', 'uuid'],
            'booking_id' => ['nullable', 'uuid'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);

        [$inquiry, $duplicate] = DB::transaction(function () use ($request, $data, $connection, $event): array {
            abort_unless(app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id === $connection->company_id, 409, 'Company changed; retry support request conversion.');
            $locked = IntegrationWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->connection_id === $connection->id && $locked->company_id === $connection->company_id, 404);
            if ($locked->status === 'processed' && $locked->linked_inquiry_id && $locked->resolution_code === 'support_request') {
                return [Inquiry::query()->where('company_id', $connection->company_id)->whereKey($locked->linked_inquiry_id)->firstOrFail(), true];
            }
            abort_unless($locked->status === 'received', 409, 'Only received Respond.io events can become support requests.');
            abort_unless($this->isRespondIoMessageEvent($locked->event_type, $locked->message_direction), 409, 'Only incoming message events can be added to a support request.');
            $customer = $this->selectedCompanyCustomer($data['customer_id'] ?? null, (string) $connection->company_id);
            $booking = $this->selectedCompanyBooking($data['booking_id'] ?? null, (string) $connection->company_id, $customer?->id);
            if ($booking && ! $customer) $customer = Customer::query()->findOrFail($booking->customer_id);
            [$assigneeId, $assignmentReason] = $this->resolveInquiryAssignee($connection, $customer, false);

            $inquiry = Inquiry::createWithUniqueNumber([
                'company_id' => $connection->company_id,
                'customer_id' => $customer?->id,
                'booking_id' => $booking?->id,
                'name' => trim($data['name']),
                'assigned_to' => $assigneeId,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'inquiry_type' => 'complaint',
                'subject' => trim($data['subject']),
                'message' => $data['message'],
                'status' => 'open',
                'priority' => 'medium',
                'source' => 'respond_io',
                'created_user_id' => $request->user()->id,
                'payload' => ['integration' => [
                    'provider' => 'respond_io',
                    'webhook_event_id' => $locked->id,
                    'event_type' => $locked->event_type,
                    'handling_kind' => 'support_request',
                ]],
            ]);
            $this->recordDefaultInquiryAssignment($locked, $inquiry, $assignmentReason);
            $this->linkSiblingConversationMessages($locked, $inquiry, (string) $request->user()->id);
            $locked->forceFill([
                'linked_inquiry_id' => $inquiry->id,
                'processed_by_user_id' => $request->user()->id,
                'processed_at' => now(),
                'status' => 'processed',
                'resolution_code' => 'support_request',
            ])->save();

            return [$inquiry, false];
        });

        if (! $duplicate && ! $inquiry->assigned_to) $this->notifyUnassignedInquiry($connection, $inquiry);

        return response()->json(['status' => 'success', 'duplicate' => $duplicate, 'data' => [
            'inquiry' => new InquiryResource($inquiry->load('booking')),
            'resolution_code' => 'support_request',
        ]], $duplicate ? 200 : 201);
    }

    public function markNotLead(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate([
            'resolution_code' => ['required', 'string', 'in:existing_customer,duplicate,spam,not_a_request,other'],
            'resolution_note' => ['required', 'string', 'max:1000'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);

        [$duplicate, $review] = DB::transaction(function () use ($request, $data, $connection, $event): array {
            abort_unless(app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id === $connection->company_id, 409, 'Company changed; retry event review.');
            $locked = IntegrationWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->connection_id === $connection->id && $locked->company_id === $connection->company_id, 404);
            if ($locked->status === 'not_a_lead') {
                return [true, $locked];
            }
            abort_unless($locked->status === 'received', 409, 'Only received Respond.io events can be marked as not a lead.');

            $locked->forceFill([
                'status' => 'not_a_lead',
                'resolution_code' => $data['resolution_code'],
                'resolution_note' => trim($data['resolution_note']),
                'processed_by_user_id' => $request->user()->id,
                'processed_at' => now(),
            ])->save();
            return [false, $locked];
        });

        return response()->json(['status' => 'success', 'duplicate' => $duplicate, 'data' => [
            'id' => (string) $review->id,
            'status' => 'not_a_lead',
            'resolution_code' => $review->resolution_code,
            'resolution_note' => $review->resolution_note,
        ]]);
    }

    public function applyContactAssignee(Request $request, IntegrationWebhookEvent $event): JsonResponse
    {
        $data = $request->validate(['assigned_to' => ['required', 'uuid']]);
        $connection = $this->companyConnection();
        abort_unless($connection && $event->connection_id === $connection->id
            && $event->company_id === $connection->company_id && $event->provider === 'respond_io', 404);
        abort_unless(str_contains(strtolower((string) $event->event_type), 'assignee'), 409, 'This is not a Respond.io assignment event.');
        abort_unless($event->linked_inquiry_id, 409, 'This contact is not linked to one clear inquiry yet. Link or review the inquiry first.');

        [$inquiry, $changed] = DB::transaction(function () use ($request, $data, $connection, $event): array {
            abort_unless(app(SingleCompanyScope::class)->activeDefaultCompany(true)?->id === $connection->company_id, 409, 'Company changed; retry assignment.');
            $lockedEvent = IntegrationWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedEvent->connection_id === $connection->id && $lockedEvent->company_id === $connection->company_id
                && str_contains(strtolower((string) $lockedEvent->event_type), 'assignee'), 404);
            abort_unless($lockedEvent->status === 'received' && $lockedEvent->linked_inquiry_id, 409, 'This assignment event has already been reviewed or has no linked inquiry.');
            $lockedInquiry = Inquiry::query()->whereKey($lockedEvent->linked_inquiry_id)->where('company_id', $connection->company_id)->lockForUpdate()->firstOrFail();
            $eligible = DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
                ->where('staff.company_id', $connection->company_id)->where('users.id', $data['assigned_to'])
                ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')
                ->whereNull('users.deleted_at')->where('users.is_active', true)->lockForUpdate()->exists();
            abort_unless($eligible, 422, 'Choose an active employee in this business.');

            $fromUserId = $lockedInquiry->assigned_to;
            $fromStatus = $lockedInquiry->status;
            $lockedInquiry->update(['assigned_to' => $data['assigned_to'], 'status' => $lockedInquiry->status ?: 'open', 'updated_user_id' => $request->user()->id]);
            app(\App\Services\InquiryStatusHistoryService::class)->record(
                $lockedInquiry, $fromStatus, $lockedInquiry->status, (string) $request->user()->id, 'Status set while applying a reviewed Respond.io assignment.',
            );
            if ((string) $fromUserId !== (string) $data['assigned_to']) {
                DB::table('inquiry_assignment_events')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $connection->company_id, 'inquiry_id' => $lockedInquiry->id,
                    'from_user_id' => $fromUserId, 'to_user_id' => $data['assigned_to'], 'actor_user_id' => $request->user()->id,
                    'event_type' => 'assigned', 'reason' => 'Assigned after reviewing a Respond.io contact assignment event.',
                    'idempotency_key' => 'respond_io:'.$lockedEvent->id.':assignment_review', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $lockedEvent->forceFill(['status' => 'processed', 'resolution_code' => 'contact_assignee_applied', 'processed_by_user_id' => $request->user()->id, 'processed_at' => now()])->save();
            return [$lockedInquiry->fresh(), (string) $fromUserId !== (string) $data['assigned_to']];
        });

        if ($changed) app(InquiryAssignmentNotificationService::class)->notifyAssigned($inquiry);
        return response()->json(['status' => 'success', 'message' => 'Inquiry assigned to the selected employee.', 'data' => ['inquiry_id' => $inquiry->id]]);
    }

    public function receive(Request $request, string $connection, string $token): JsonResponse
    {
        $config = IntegrationConnection::query()->whereKey($connection)->where('provider', 'respond_io')->firstOrFail();
        abort_unless($config->enabled && hash_equals($config->webhook_secret_hash, hash('sha256', $token)), 403, 'Invalid or disabled webhook URL.');
        $raw = $request->getContent();
        abort_if(strlen($raw) > 2_097_152, 413, 'Webhook payload is too large.');
        $payload = json_decode($raw, true);
        abort_unless(json_last_error() === JSON_ERROR_NONE && is_array($payload), 422, 'Webhook body must be a JSON object.');
        $eventType = $payload['event_type'] ?? $payload['eventType'] ?? $payload['event'] ?? $payload['type'] ?? null;
        $eventType = is_string($eventType) ? Str::limit($eventType, 120, '') : null;
        $hash = hash('sha256', $raw);
        $identity = $this->respondIoIdentity($payload);

        try {
            $event = IntegrationWebhookEvent::create([
                'connection_id' => $config->id, 'company_id' => $config->company_id, 'provider' => $config->provider,
                'event_type' => $eventType, ...$identity, 'payload_hash' => $hash, 'payload' => $payload,
                'status' => 'received', 'received_at' => now(),
            ]);
            $duplicate = false;
        } catch (QueryException $error) {
            $duplicate = IntegrationWebhookEvent::query()->where('connection_id', $config->id)
                ->where(function ($query) use ($hash, $identity): void {
                    $query->where('payload_hash', $hash);
                    if ($identity['external_message_id']) $query->orWhere('external_message_id', $identity['external_message_id']);
                })->exists();
            if (! $duplicate) throw $error;
        }

        if (! $duplicate && $identity['external_conversation_id'] && $this->isRespondIoConversationMessage($eventType, $identity['message_direction'])) {
            $priorInquiryId = IntegrationWebhookEvent::query()
                ->where('connection_id', $config->id)
                ->where('external_conversation_id', $identity['external_conversation_id'])
                ->where('status', 'processed')->whereNotNull('linked_inquiry_id')
                ->whereIn('resolution_code', ['sales_lead', 'support_request', 'conversation_update'])
                ->value('linked_inquiry_id');
            if ($priorInquiryId && Inquiry::query()->whereKey($priorInquiryId)->where('company_id', $config->company_id)->exists()) {
                $event->forceFill([
                    'linked_inquiry_id' => $priorInquiryId,
                    'status' => 'processed',
                    'resolution_code' => 'conversation_update',
                    'processed_at' => now(),
                ])->save();
            }
        }

        if (! $duplicate && $identity['external_contact_id'] && str_contains(strtolower((string) $eventType), 'assignee')) {
            $inquiryIds = IntegrationWebhookEvent::query()->where('connection_id', $config->id)
                ->where('external_contact_id', $identity['external_contact_id'])->where('status', 'processed')
                ->whereNotNull('linked_inquiry_id')->whereIn('resolution_code', ['sales_lead', 'support_request', 'conversation_update'])
                ->distinct()->pluck('linked_inquiry_id');
            if ($inquiryIds->count() === 1 && Inquiry::query()->whereKey($inquiryIds->first())->where('company_id', $config->company_id)->exists()) {
                $event->forceFill(['linked_inquiry_id' => $inquiryIds->first()])->save();
            }
        }

        return response()->json(['status' => 'accepted', 'duplicate' => $duplicate]);
    }

    private function respondIoIdentity(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $contact = is_array($payload['contact'] ?? null) ? $payload['contact'] : (is_array($data['contact'] ?? null) ? $data['contact'] : []);
        $conversation = is_array($payload['conversation'] ?? null) ? $payload['conversation'] : (is_array($data['conversation'] ?? null) ? $data['conversation'] : []);
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : (is_array($data['message'] ?? null) ? $data['message'] : []);
        $messageDirection = $this->respondIoMessageDirection(
            $message['direction'] ?? $payload['direction'] ?? $data['direction'] ?? $message['type'] ?? null
        );

        return [
            'message_direction' => $messageDirection,
            'external_contact_id' => $this->respondIoIdentifier($contact['id'] ?? $payload['contact_id'] ?? $payload['contactId'] ?? $message['contactId'] ?? $message['contact_id'] ?? $data['contact_id'] ?? $data['contactId'] ?? null),
            'external_conversation_id' => $this->respondIoIdentifier($conversation['id'] ?? $payload['conversation_id'] ?? $payload['conversationId'] ?? $message['conversationId'] ?? $message['conversation_id'] ?? $data['conversation_id'] ?? $data['conversationId'] ?? null),
            'external_message_id' => $this->respondIoIdentifier($message['id'] ?? $message['messageId'] ?? $message['message_id'] ?? $payload['message_id'] ?? $payload['messageId'] ?? $data['message_id'] ?? $data['messageId'] ?? null),
        ];
    }

    private function respondIoIdentifier(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) return null;
        $value = trim((string) $value);
        return $value !== '' && mb_strlen($value) <= 120 ? $value : null;
    }

    private function respondIoMessageDirection(mixed $value): ?string
    {
        if (! is_string($value)) return null;
        $direction = strtolower($value);
        if (str_contains($direction, 'incoming') || str_contains($direction, 'inbound') || str_contains($direction, 'received')) return 'incoming';
        if (str_contains($direction, 'outgoing') || str_contains($direction, 'outbound') || str_contains($direction, 'echo') || str_contains($direction, 'sent')) return 'outgoing';
        return null;
    }

    private function isRespondIoMessageEvent(?string $eventType, ?string $direction = null): bool
    {
        $eventType = strtolower((string) $eventType);
        if (! str_contains($eventType, 'message') || $direction === 'outgoing' || str_contains($eventType, 'status')
            || str_contains($eventType, 'outgoing') || str_contains($eventType, 'outbound')
            || str_contains($eventType, 'echo')) return false;
        return $direction === 'incoming' || (str_contains($eventType, 'message')
            && (str_contains($eventType, 'incoming') || str_contains($eventType, 'inbound') || str_contains($eventType, 'received'))
        );
    }

    private function isRespondIoConversationMessage(?string $eventType, ?string $direction = null): bool
    {
        $eventType = strtolower((string) $eventType);
        if (! str_contains($eventType, 'message') || str_contains($eventType, 'status') || str_contains($eventType, 'echo')) return false;
        if (in_array($direction, ['incoming', 'outgoing'], true)) return true;
        return str_contains($eventType, 'incoming') || str_contains($eventType, 'inbound') || str_contains($eventType, 'received')
            || str_contains($eventType, 'outgoing') || str_contains($eventType, 'outbound') || str_contains($eventType, 'sent');
    }

    private function linkSiblingConversationMessages(IntegrationWebhookEvent $event, Inquiry $inquiry, string $actorUserId): void
    {
        if (! $event->external_conversation_id) return;
        IntegrationWebhookEvent::query()->conversationMessage()
            ->where('connection_id', $event->connection_id)
            ->where('id', '!=', $event->id)
            ->where('external_conversation_id', $event->external_conversation_id)
            ->where('status', 'received')->whereNull('linked_inquiry_id')
            ->update([
                'linked_inquiry_id' => $inquiry->id,
                'status' => 'processed',
                'resolution_code' => 'conversation_update',
                'processed_by_user_id' => $actorUserId,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function companyConnection(): ?IntegrationConnection
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        return $companyId ? IntegrationConnection::query()->where('company_id', $companyId)->where('provider', 'respond_io')->first() : null;
    }

    private function selectedCompanyCustomer(?string $customerId, string $companyId): ?Customer
    {
        if (! $customerId) return null;
        $customer = Customer::query()->whereKey($customerId)->whereNull('deleted_at')->firstOrFail();
        $linkedBooking = DB::table('bookings as linked_booking')->where('linked_booking.customer_id', $customerId)->whereNull('linked_booking.deleted_at');
        \App\Services\CompanyBookingScope::constrain($linkedBooking, 'linked_booking', $companyId);
        $linked = DB::table('customer_relationship_owner_assignments')->where('customer_id', $customerId)->where('company_id', $companyId)->exists()
            || DB::table('sales_opportunities')->where('customer_id', $customerId)->where('company_id', $companyId)->whereNull('deleted_at')->exists()
            || DB::table('inquiries')->where('customer_id', $customerId)->where('company_id', $companyId)->whereNull('deleted_at')->exists()
            || $linkedBooking->exists();
        abort_unless($linked, 422, 'Choose a customer already associated with this business.');
        return $customer;
    }

    private function selectedCompanyBooking(?string $bookingId, string $companyId, ?string $customerId): ?\App\Models\Booking\Booking
    {
        if (! $bookingId) return null;
        $query = \App\Models\Booking\Booking::query()->whereKey($bookingId)->whereNull('deleted_at');
        \App\Services\CompanyBookingScope::constrain($query, 'bookings', $companyId);
        $booking = $query->firstOrFail();
        abort_unless($booking->customer_id && (! $customerId || (string) $booking->customer_id === $customerId), 422,
            'Choose a booking for the selected customer.');
        return $booking;
    }

    private function companyBookingQuery(string $companyId, string $bookingId)
    {
        $query = Booking::query()->whereKey($bookingId);
        \App\Services\CompanyBookingScope::constrain($query, 'bookings', $companyId);
        return $query;
    }

    private function eligibleDefaultAssignee(IntegrationConnection $connection): ?string
    {
        if (! $connection->default_inquiry_assignee_user_id) return null;
        return app(InquiryAssignmentService::class)->eligibleAssignees((string) $connection->company_id)
            ->where('users.id', $connection->default_inquiry_assignee_user_id)->value('users.id');
    }

    private function resolveInquiryAssignee(IntegrationConnection $connection, ?Customer $customer, bool $preferCustomerOwner): array
    {
        if ($preferCustomerOwner && $customer) {
            $ownerId = DB::table('customer_relationship_owner_assignments as assignment')
                ->join('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
                ->join('staff', 'staff.id', '=', 'profile.staff_id')
                ->where('assignment.customer_id', $customer->id)->where('assignment.company_id', $connection->company_id)
                ->where('profile.company_id', $connection->company_id)->where('profile.status', 'active')
                ->where('profile.effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                ->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')
                ->whereNull('assignment.ended_at')->value('staff.user_id');
            $eligibleOwner = $ownerId
                ? app(InquiryAssignmentService::class)->eligibleAssignees((string) $connection->company_id)
                    ->where('users.id', $ownerId)->value('users.id')
                : null;
            if ($eligibleOwner) return [$eligibleOwner, 'Assigned to the customer\'s active relationship owner.'];
        }

        return [$this->eligibleDefaultAssignee($connection), 'Assigned from the Respond.io default inquiry owner.'];
    }

    private function recordDefaultInquiryAssignment(IntegrationWebhookEvent $event, Inquiry $inquiry, string $reason, ?string $actorUserId = null): void
    {
        if (! $inquiry->assigned_to) {
            return;
        }

        DB::table('inquiry_assignment_events')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'company_id' => $event->company_id,
            'inquiry_id' => $inquiry->id,
            'from_user_id' => null,
            'to_user_id' => $inquiry->assigned_to,
            'actor_user_id' => $actorUserId,
            'event_type' => 'assigned',
            'reason' => $reason,
            'idempotency_key' => 'respond_io:'.$event->id.':inquiry_assignment',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(InquiryAssignmentNotificationService::class)->notifyAssigned($inquiry);
    }

    private function notifyUnassignedInquiry(IntegrationConnection $connection, Inquiry $inquiry): void
    {
        try {
            $recipients = User::permission('inquiries.view')->permission('inquiries.edit')->where('is_active', true)->whereNull('deleted_at')
                ->whereHas('staff', fn ($query) => $query->where('company_id', $connection->company_id)
                    ->whereNull('deleted_at')->whereNull('employment_ended_at'))->get();
            foreach ($recipients as $recipient) $recipient->notify(new RespondIoInquiryNeedsOwnerNotification($inquiry));
        } catch (\Throwable $exception) {
            Log::warning('Unassigned Respond.io inquiry notification could not be queued', [
                'inquiry_id' => $inquiry->id,
                'company_id' => $connection->company_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function webhookUrl(IntegrationConnection $connection, string $secret): string
    {
        return url('/api/webhooks/respond-io/'.$connection->id.'/'.$secret);
    }
}
