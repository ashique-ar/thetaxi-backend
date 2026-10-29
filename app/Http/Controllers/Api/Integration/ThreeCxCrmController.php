<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Booking\Booking;
use App\Enums\BookingLifecycleStatus;
use App\Models\IntegrationConnection;
use App\Models\PhoneCall;
use App\Models\PhoneCallAssignment;
use App\Notifications\PhoneCallNeedsOwnerNotification;
use App\Models\Sales\SalesProfile;
use App\Models\User;
use App\Services\Sales\SalesCrmService;
use App\Services\Sales\SalesPolicySettingsService;
use App\Services\CompanyCustomerScope;
use App\Services\CompanyBookingScope;
use App\Services\SingleCompanyScope;
use App\Support\PhoneNumberMatch;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ThreeCxCrmController extends Controller
{
    public function connection(): JsonResponse
    {
        $connection = $this->companyConnection();
        return response()->json(['status' => 'success', 'data' => $connection ? [
            'id' => $connection->id, 'name' => $connection->name, 'enabled' => $connection->enabled,
            'api_key_configured' => (bool) $connection->api_key_hash,
            'missed_call_callback_minutes' => $connection->missed_call_callback_minutes,
            'missed_call_escalation_minutes' => $connection->missed_call_escalation_minutes,
        ] : null]);
    }

    public function createConnection(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $company = app(SingleCompanyScope::class)->activeDefaultCompany();
        abort_unless($company, 409, 'No active business is configured.');
        abort_if($this->companyConnection(), 409, 'A 3CX connection already exists for this business.');
        $apiKey = bin2hex(random_bytes(32));
        $connection = IntegrationConnection::create([
            'company_id' => $company->id, 'provider' => '3cx', 'name' => trim($data['name']),
            'webhook_secret_hash' => hash('sha256', random_bytes(32)),
            'api_key_hash' => hash('sha256', $apiKey), 'created_user_id' => $request->user()->id,
        ]);
        return response()->json(['status' => 'success', 'data' => [
            'id' => $connection->id, 'name' => $connection->name, 'api_key' => $apiKey, 'api_key_shown_once' => true,
        ]], 201);
    }

    public function rotateApiKey(): JsonResponse
    {
        $connection = $this->companyConnection();
        abort_unless($connection, 404, 'No 3CX connection is configured.');
        $apiKey = bin2hex(random_bytes(32));
        $connection->update(['api_key_hash' => hash('sha256', $apiKey)]);
        return response()->json(['status' => 'success', 'data' => ['api_key' => $apiKey, 'api_key_shown_once' => true]]);
    }

    public function updateConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'enabled' => ['sometimes', 'required', 'boolean'],
            'missed_call_callback_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'missed_call_escalation_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
        ]);
        $connection = $this->companyConnection();
        abort_unless($connection, 404, 'No 3CX connection is configured.');
        $connection->update($data);
        return response()->json(['status' => 'success', 'data' => [
            'id' => $connection->id, 'name' => $connection->name, 'enabled' => $connection->enabled,
            'missed_call_callback_minutes' => $connection->missed_call_callback_minutes,
            'missed_call_escalation_minutes' => $connection->missed_call_escalation_minutes,
        ]]);
    }

    public function contactByNumber(Request $request): JsonResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:40']]);
        $connection = $this->authorizeCrmRequest($request);
        $number = PhoneNumberMatch::normalized(trim($data['number']));
        abort_if($number === '', 422, 'The phone number must contain digits.');
        $contacts = $this->contacts($connection);
        $this->whereCustomerPhoneIdentity($contacts, $number);
        $contacts = $contacts->limit(20)->get();
        return response()->json(['contacts' => $contacts->map(fn (Customer $customer) => $this->contactData($customer))->values()]);
    }

    public function callContextByNumber(Request $request): JsonResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:40']]);
        $connection = $this->authorizeCrmRequest($request);
        $number = PhoneNumberMatch::normalized(trim($data['number']));
        abort_if($number === '', 422, 'The phone number must contain digits.');

        $contacts = $this->contacts($connection);
        $this->whereCustomerPhoneIdentity($contacts, $number);
        $contacts = $contacts->limit(20)->get();
        $customerIds = $contacts->pluck('id')->all();
        $activeStatuses = BookingLifecycleStatus::activeOperationalValues();
        $bookings = collect();
        if ($customerIds) {
            $bookingQuery = Booking::query()->whereIn('customer_id', $customerIds)->whereIn('status', $activeStatuses)
                ->with(['bookingItems.serviceType']);
            CompanyBookingScope::constrain($bookingQuery, 'bookings', (string) $connection->company_id);
            $bookings = $bookingQuery->orderByDesc('booking_date')->limit(100)->get()->groupBy('customer_id');
        }

        return response()->json([
            'matches' => $contacts->map(fn (Customer $customer): array => [
                'customer' => $this->contactData($customer),
                'active_bookings' => ($bookings->get($customer->id) ?? collect())->take(5)->map(function (Booking $booking): array {
                    $items = $booking->bookingItems;
                    $firstItem = $items->first();
                    $lastItem = $items->last();
                    return [
                        'id' => (string) $booking->id,
                        'booking_number' => $booking->booking_number,
                        'status' => $booking->status,
                        'booking_date' => $booking->booking_date,
                        'service_types' => $items->pluck('serviceType.name')->filter()->unique()->values(),
                        'starts_at' => $firstItem ? trim(($firstItem->from_date ?? '').' '.($firstItem->from_time ?? '')) : null,
                        'ends_at' => $lastItem ? trim(($lastItem->to_date ?? '').' '.($lastItem->to_time ?? '')) : null,
                    ];
                })->values(),
            ])->values(),
            'match_warning' => 'A phone match does not verify the caller. Confirm their identity before discussing booking details.',
        ]);
    }

    public function contactByEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $connection = $this->authorizeCrmRequest($request);
        $contacts = $this->contacts($connection);
        $this->whereCustomerEmailIdentity($contacts, Str::lower(trim($data['email'])));
        $contacts = $contacts->limit(20)->get();
        return response()->json(['contacts' => $contacts->map(fn (Customer $customer) => $this->contactData($customer))->values()]);
    }

    public function searchContacts(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $connection = $this->authorizeCrmRequest($request);
        $term = '%'.addcslashes(trim($data['search']), '%_\\').'%';
        $contacts = $this->contacts($connection)->where(function ($query) use ($term): void {
            $query->whereHas('user', fn ($user) => $user->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term))
                ->orWhereExists(function ($alias) use ($term): void {
                    $alias->selectRaw('1')->from('customer_user_aliases as customer_alias')
                        ->join('users as alias_user', 'alias_user.id', '=', 'customer_alias.user_id')
                        ->whereColumn('customer_alias.customer_id', 'customers.id')->whereNull('alias_user.deleted_at')
                        ->where(function ($identity) use ($term): void {
                            $identity->where('alias_user.first_name', 'like', $term)->orWhere('alias_user.last_name', 'like', $term)
                                ->orWhere('alias_user.email', 'like', $term)->orWhere('alias_user.phone', 'like', $term);
                        });
                });
        })->limit(20)->get();
        return response()->json(['contacts' => $contacts->map(fn (Customer $customer) => $this->contactData($customer))->values()]);
    }

    public function reportCall(Request $request, SalesCrmService $crm, SalesPolicySettingsService $salesPolicy): JsonResponse
    {
        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:40'],
            'call_type' => ['required', 'string', 'in:Inbound,Outbound,Missed,Notanswered'],
            'direction' => ['required', 'string', 'in:Inbound,Outbound'],
            'name' => ['nullable', 'string', 'max:255'], 'entity_id' => ['nullable', 'uuid'],
            'queue_extension' => ['nullable', 'string', 'max:40'], 'agent' => ['nullable', 'string', 'max:40'],
            'agent_email' => ['nullable', 'email', 'max:255'], 'duration' => ['nullable', 'regex:/^\d{1,3}:\d{2}:\d{2}$/'],
            'started_at' => ['required', 'date'], 'established_at' => ['nullable', 'date'], 'ended_at' => ['required', 'date'],
            'summary' => ['nullable', 'string', 'max:2000'],
        ]);
        $connection = $this->authorizeCrmRequest($request);
        $customer = ! empty($data['entity_id']) ? $this->contacts($connection)->whereKey($data['entity_id'])->first() : null;
        $agent = ! empty($data['agent_email']) ? User::query()->whereRaw('LOWER(email) = ?', [Str::lower($data['agent_email'])])
            ->where('is_active', true)->whereHas('staff', fn ($query) => $query->where('company_id', $connection->company_id)
                ->whereNull('deleted_at')->whereNull('employment_ended_at'))->first() : null;
        $startedAt = Carbon::parse($data['started_at'])->utc();
        $endedAt = Carbon::parse($data['ended_at'])->utc();
        abort_if($endedAt->lt($startedAt), 422, 'The call end time cannot be before its start time.');
        $seconds = isset($data['duration']) ? $this->durationSeconds($data['duration']) : null;
        $legacyKey = hash('sha256', implode('|', [
            $data['number'] ?? '', $data['call_type'], $data['direction'], $startedAt->format('Y-m-d\TH:i:s.u\Z'),
            $endedAt->format('Y-m-d\TH:i:s.u\Z'), $data['agent'] ?? '', Str::lower($data['agent_email'] ?? ''),
        ]));
        $keys = [$legacyKey, hash('sha256', $connection->company_id.'|'.$legacyKey)];
        $key = $keys[1];

        $existing = PhoneCall::query()->where('company_id', $connection->company_id)->where('external_provider', '3cx')
            ->whereIn('external_call_key', $keys)->first();
        if ($existing) return response()->json(['status' => 'success', 'duplicate' => true, 'call_id' => $existing->id]);

        try {
            $call = DB::transaction(function () use ($data, $customer, $agent, $startedAt, $endedAt, $seconds, $key, $connection, $crm): PhoneCall {
                $call = PhoneCall::create([
                'company_id' => $connection->company_id,
                'phone' => $data['number'] ?? null, 'client_name' => $customer?->user?->full_name ?? ($data['name'] ?? null),
                'customer_id' => $customer?->id, 'summary' => trim(implode("\n", array_filter([
                    '3CX '.$data['call_type'].' call'.(! empty($data['queue_extension']) ? ' · Queue '.$data['queue_extension'] : ''),
                    ! empty($data['summary']) ? trim($data['summary']) : null,
                ]))),
                'call_time' => $startedAt->format('Y-m-d H:i:s'), 'created_user_id' => $agent?->id,
                'external_provider' => '3cx', 'external_call_key' => $key, 'direction' => $data['direction'],
                'call_status' => $data['call_type'], 'agent_extension' => $data['agent'] ?? null,
                'agent_email' => $data['agent_email'] ?? null, 'call_started_at' => $startedAt,
                'call_ended_at' => $endedAt, 'talk_duration_seconds' => $seconds, 'assigned_user_id' => $agent?->id,
                ]);
                if ($agent) PhoneCallAssignment::create([
                    'phone_call_id' => $call->id, 'company_id' => $connection->company_id,
                    'from_user_id' => null, 'to_user_id' => $agent->id, 'actor_user_id' => null,
                    'reason' => 'Mapped from the 3CX agent email.', 'event_at' => now(),
                ]);

                // shortcut: only exact CRM matches with an active owner get callback tasks; supervisors route other calls after review.
                if ($customer && $data['direction'] === 'Inbound' && in_array($data['call_type'], ['Missed', 'Notanswered'], true)) {
                    $assignment = DB::table('customer_relationship_owner_assignments')
                        ->where('customer_id', $customer->id)->where('company_id', $connection->company_id)
                        ->whereNull('ended_at')->whereNotNull('owner_sales_profile_id')->latest('effective_from')->first();
                    $owner = $assignment ? SalesProfile::query()->whereKey($assignment->owner_sales_profile_id)
                        ->where('company_id', $connection->company_id)->activeAt(now())
                        ->whereHas('staff', fn ($query) => $query->where('company_id', $connection->company_id)
                            ->whereNull('deleted_at')->where(fn ($employment) => $employment->whereNull('employment_ended_at')
                                ->orWhere('employment_ended_at', '>', now()))
                            ->whereHas('user', fn ($user) => $user->where('is_active', true)->whereNull('deleted_at')))
                        ->with('staff')->first() : null;

                    if ($owner && $salesPolicy->featureEnabled((string) $connection->company_id, 'crm')) {
                        $actorId = $connection->created_user_id ?: $owner->staff->user_id;
                        $dueAt = $connection->missed_call_callback_minutes
                            ? $endedAt->copy()->addMinutes((int) $connection->missed_call_callback_minutes)->utc()
                            : $endedAt->copy()->setTimezone(config('app.timezone'))->endOfDay()->utc();
                        $task = $crm->createTask([
                            'company_id' => $connection->company_id,
                            'owner_sales_profile_id' => $owner->id,
                            'customer_id' => $customer->id,
                            'creation_idempotency_key' => '3cx-missed-call-callback:'.$call->id,
                            'title' => Str::limit('Call back '.($customer->user?->full_name ?: $data['number'] ?? 'customer'), 255, ''),
                            'description' => $call->summary,
                            'priority' => 'normal',
                            'due_at' => $dueAt->toIso8601String(),
                            'escalate_at' => $connection->missed_call_escalation_minutes
                                ? $dueAt->copy()->addMinutes((int) $connection->missed_call_escalation_minutes)->toIso8601String()
                                : null,
                        ], (string) $actorId, null);
                        $call->update(['follow_up_task_id' => $task->id]);
                    }
                    // shortcut: with Sales CRM disabled, the call remains in the call log without a SalesTask callback reminder.
                }

                return $call;
            });
            if (! $call->assigned_user_id) $this->notifyCallManagers($connection, $call);
            return response()->json(['status' => 'success', 'duplicate' => false, 'call_id' => $call->id], 201);
        } catch (QueryException $error) {
            $existing = PhoneCall::query()->where('company_id', $connection->company_id)->where('external_provider', '3cx')
                ->whereIn('external_call_key', $keys)->first();
            if (! $existing) throw $error;
            return response()->json(['status' => 'success', 'duplicate' => true, 'call_id' => $existing->id]);
        }
    }

    private function authorizeCrmRequest(Request $request): IntegrationConnection
    {
        $keys = [$request->header('X-Cassons-Integration-Key'), $request->bearerToken(), $request->getUser(), $request->getPassword()];
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        $connection = $companyId ? IntegrationConnection::query()->where('company_id', $companyId)->where('provider', '3cx')->first() : null;
        $authorized = $connection && $connection->enabled && $connection->api_key_hash
            && collect($keys)->contains(fn ($key) => is_string($key) && strlen($key) === 64
                && hash_equals($connection->api_key_hash, hash('sha256', $key)));
        abort_unless($authorized, 401, 'Invalid integration credentials.');
        return $connection;
    }

    private function contacts(IntegrationConnection $connection)
    {
        $customers = Customer::query()->with('user')->whereHas('user');
        CompanyCustomerScope::constrain($customers, 'customers', (string) $connection->company_id);
        return $customers;
    }

    private function whereCustomerPhoneIdentity($query, string $number): void
    {
        $query->where(function ($identity) use ($number): void {
            $identity->whereHas('user', fn ($user) => $user->whereRaw($this->normalizedPhoneExpression('phone').' = ?', [$number]))
                ->orWhereExists(function ($alias) use ($number): void {
                    $alias->selectRaw('1')->from('customer_user_aliases as customer_alias')
                        ->join('users as alias_user', 'alias_user.id', '=', 'customer_alias.user_id')
                        ->whereColumn('customer_alias.customer_id', 'customers.id')->whereNull('alias_user.deleted_at')
                        ->whereRaw($this->normalizedPhoneExpression('alias_user.phone').' = ?', [$number]);
                });
        });
    }

    private function whereCustomerEmailIdentity($query, string $email): void
    {
        $query->where(function ($identity) use ($email): void {
            $identity->whereHas('user', fn ($user) => $user->whereRaw('LOWER(email) = ?', [$email]))
                ->orWhereExists(function ($alias) use ($email): void {
                    $alias->selectRaw('1')->from('customer_user_aliases as customer_alias')
                        ->join('users as alias_user', 'alias_user.id', '=', 'customer_alias.user_id')
                        ->whereColumn('customer_alias.customer_id', 'customers.id')->whereNull('alias_user.deleted_at')
                        ->whereRaw('LOWER(alias_user.email) = ?', [$email]);
                });
        });
    }

    private function notifyCallManagers(IntegrationConnection $connection, PhoneCall $call): void
    {
        try {
            $recipients = User::permission('phone-calls.view')->permission('phone-calls.edit')->where('is_active', true)->whereNull('deleted_at')
                ->whereHas('staff', fn ($query) => $query->where('company_id', $connection->company_id)
                    ->whereNull('deleted_at')->whereNull('employment_ended_at'))->get();
            foreach ($recipients as $recipient) $recipient->notify(new PhoneCallNeedsOwnerNotification($call));
        } catch (\Throwable $exception) {
            Log::warning('Unassigned 3CX call notification could not be queued', [
                'phone_call_id' => $call->id,
                'company_id' => $connection->company_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function contactData(Customer $customer): array
    {
        $user = $customer->user;
        return [
            'id' => $customer->id, 'first_name' => $user?->first_name, 'last_name' => $user?->last_name,
            'name' => trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')), 'email' => $user?->email,
            'phone' => $user?->phone, 'contact_url' => url('/customers/'.$customer->id),
        ];
    }

    private function durationSeconds(string $duration): int
    {
        [$hours, $minutes, $seconds] = array_map('intval', explode(':', $duration));
        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private function normalizedPhoneExpression(string $column): string
    {
        return PhoneNumberMatch::sqlExpression($column);
    }

    private function companyConnection(): ?IntegrationConnection
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        return $companyId ? IntegrationConnection::query()->where('company_id', $companyId)->where('provider', '3cx')->first() : null;
    }
}
