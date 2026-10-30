<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\CheckoutConfirmationMail;
use App\Mail\PaymentInitiatedMail;
use App\Mail\QuotationRequestMail;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingAddon;
use App\Models\Booking\BookingItem;
use App\Models\Customer;
use App\Models\Service\ServiceType;
use App\Models\TermsAndCondition;
use App\Models\Vehicle\VehicleGroup;
use App\Models\User;
use App\Models\Website\WebsiteSetting;
use App\Models\Website\PendingPaymentLink;
use App\Services\BookingFlowService;
use App\Services\CustomerService;
use App\Services\MailDispatchService;
use App\Services\WebXPayService;
use App\Services\CurrencyService;
use App\Services\PromoCodeService;
use App\Services\WebsiteSettingsService;
use App\Services\Sms\SmsAutomationService;
use App\Services\PaymentEventService;
use App\Services\BookingPaymentLedgerService;
use App\Services\Sms\SmsService;
use App\Support\PhoneNumberMatch;
use App\Helpers\BookingLinkHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    protected $bookingFlowService;
    protected $customerService;
    protected $cartService;
    protected $webxPayService;
    protected $currencyService;
    protected $paymentEventService;
    protected MailDispatchService $mailDispatchService;
    protected PromoCodeService $promoCodeService;
    protected WebsiteSettingsService $websiteSettingsService;

    public function __construct(
        BookingFlowService $bookingFlowService,
        CustomerService $customerService,
        \App\Services\CartService $cartService,
        WebXPayService $webxPayService,
        CurrencyService $currencyService,
        MailDispatchService $mailDispatchService,
        PromoCodeService $promoCodeService,
        WebsiteSettingsService $websiteSettingsService,
        PaymentEventService $paymentEventService,
        protected BookingPaymentLedgerService $paymentLedger,
        protected SmsAutomationService $smsAutomationService
    ) {
        $this->bookingFlowService = $bookingFlowService;
        $this->customerService = $customerService;
        $this->cartService = $cartService;
        $this->webxPayService = $webxPayService;
        $this->currencyService = $currencyService;
        $this->mailDispatchService = $mailDispatchService;
        $this->promoCodeService = $promoCodeService;
        $this->websiteSettingsService = $websiteSettingsService;
        $this->paymentEventService = $paymentEventService;
    }

    public function sendCustomerEmailVerificationCode(Request $request, SmsService $sms)
    {
        abort_unless($this->isCheckoutCustomerLookupEnabled(), 404);
        $data = $request->validate([
            'channel' => ['required', 'in:email,mobile'],
            'value' => ['required', 'string', 'max:255'],
        ]);
        $channel = $data['channel'];
        $value = $channel === 'email' ? mb_strtolower(trim($data['value'])) : $this->normalizeCheckoutMobile($data['value']);
        $resendAfter = (int) $this->websiteSettingsService->getBookingSettings()['checkout_customer_verification_resend_seconds'];
        if ($channel === 'email') validator(['email' => $value], ['email' => ['required', 'email:rfc', 'max:255']])->validate();
        abort_if($channel === 'mobile' && ! preg_match('/^\+[1-9]\d{7,14}$/', $value), 422, 'Enter a valid mobile number with its country code.');

        $limitKey = 'checkout-customer-code:'.sha1($channel.'|'.$value.'|'.$request->ip());
        $cooldownKey = $limitKey.':resend';
        $retryAfter = RateLimiter::availableIn($cooldownKey);
        if ($retryAfter > 0) {
            return response()->json(['message' => 'Please wait before requesting another code.', 'retry_after' => $retryAfter], 429)
                ->header('Retry-After', (string) $retryAfter);
        }
        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            $retryAfter = RateLimiter::availableIn($limitKey);
            return response()->json(['message' => 'Too many code requests. Please try again later.', 'retry_after' => $retryAfter], 429)
                ->header('Retry-After', (string) $retryAfter);
        }
        RateLimiter::hit($limitKey, 600);
        RateLimiter::hit($cooldownKey, $resendAfter);

        $customer = $this->findCheckoutCustomer($channel, $value);
        if ($this->isEligibleCheckoutCustomer($customer)) {
            $code = (string) random_int(100000, 999999);
            $expiresAt = now()->addMinutes(10);
            Cache::put($this->checkoutCustomerCodeKey($request, $channel, $value), [
                'hash' => Hash::make($code),
                'attempts' => 0,
                'customer_id' => (string) $customer->id,
                'expires_at' => $expiresAt->timestamp,
            ], $expiresAt);
            try {
                if ($channel === 'email') {
                    Notification::route('mail', $value)->notify(new \App\Notifications\CheckoutEmailVerificationCodeNotification($code));
                } else {
                    $smsMessage = $sms->queueSingleMessage([
                        'recipient' => $value,
                        'message' => "Your booking details code is {$code}. It expires in 10 minutes.",
                        'source' => 'manual',
                        'context_type' => 'customer_verification',
                        'event_key' => 'checkout_customer_verification_code',
                    ]);
                    if ($smsMessage->status === 'failed') {
                        throw new \RuntimeException('The SMS provider could not send the verification code.');
                    }
                }
            } catch (\Throwable $exception) {
                Cache::forget($this->checkoutCustomerCodeKey($request, $channel, $value));
                Log::warning('Checkout customer verification code could not be queued.', [
                    'channel' => $channel,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json(['status' => 'success', 'resend_after' => $resendAfter, 'message' => 'If these details match a previous booking, check your email or mobile for a code. The code expires in 10 minutes.']);
    }

    public function verifyCustomerEmailCode(Request $request)
    {
        abort_unless($this->isCheckoutCustomerLookupEnabled(), 404);
        $data = $request->validate([
            'channel' => ['required', 'in:email,mobile'],
            'value' => ['required', 'string', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);
        $channel = $data['channel'];
        $value = $channel === 'email' ? mb_strtolower(trim($data['value'])) : $this->normalizeCheckoutMobile($data['value']);
        $key = $this->checkoutCustomerCodeKey($request, $channel, $value);
        $challenge = Cache::get($key);

        $expired = ! $challenge || (int) ($challenge['expires_at'] ?? 0) <= now()->timestamp;
        $attemptsExceeded = $challenge && (int) ($challenge['attempts'] ?? 0) >= 5;
        $codeMatches = $challenge && Hash::check($data['code'], $challenge['hash']);
        if ($expired || $attemptsExceeded || ! $codeMatches) {
            if ($challenge && ($expired || $attemptsExceeded || (int) ($challenge['attempts'] ?? 0) >= 4)) {
                Cache::forget($key);
            } elseif ($challenge) {
                $challenge['attempts']++;
                Cache::put($key, $challenge, now()->addSeconds(max(1, (int) $challenge['expires_at'] - now()->timestamp)));
            }
            throw ValidationException::withMessages(['code' => ['The code is invalid or expired. Request a new code and try again.']]);
        }

        $customer = $this->findCheckoutCustomer($channel, $value);
        if (! $customer || (string) $customer->id !== (string) $challenge['customer_id'] || ! $this->isEligibleCheckoutCustomer($customer)) {
            Cache::forget($key);
            abort(422, 'We could not confirm those details. Please try again or enter your details below.');
        }
        $customer->load(['user', 'country']);
        Cache::forget($key);
        $email = mb_strtolower(trim((string) $customer->user->email));
        $mobile = $this->normalizeCheckoutMobile((string) $customer->user->phone, $customer->country?->callcode);
        session()->put('checkout_verified_email', $email);
        session()->put('checkout_verified_email_at', now()->timestamp);
        session()->put('checkout_verified_customer_id', (string) $customer->id);
        session()->put('checkout_verified_customer_mobile', $mobile);

        return response()->json(['status' => 'success', 'message' => 'Your previous booking details are ready. Check them and update anything that has changed.', 'data' => [
            'first_name' => $customer->user->first_name,
            'last_name' => $customer->user->last_name,
            'email' => $customer->user->email,
            'phone' => $mobile,
            'address' => $customer->address,
            'city' => $customer->city,
            'country' => $customer->getAttribute('country') ?: $customer->getRelation('country')?->name,
            'identification' => $customer->nic,
        ]]);
    }

    public function resetVerifiedCheckoutCustomer(Request $request)
    {
        $request->session()->forget([
            'checkout_verified_email', 'checkout_verified_email_at',
            'checkout_verified_customer_id', 'checkout_verified_customer_mobile',
        ]);

        return response()->json(['status' => 'success']);
    }

    private function hasRecentCheckoutVerification(): bool
    {
        $customerId = session('checkout_verified_customer_id');
        if (! $customerId || now()->timestamp - (int) session('checkout_verified_email_at', 0) > 1800) {
            return false;
        }

        return $this->isEligibleCheckoutCustomer(Customer::query()->with('user')->find($customerId));
    }

    private function isCheckoutCustomerLookupEnabled(): bool
    {
        return $this->normalizeBoolean(
            $this->websiteSettingsService->getBookingSettings()['checkout_returning_customer_lookup_enabled'] ?? null,
            true
        );
    }

    private function checkoutCustomerCodeKey(Request $request, string $channel, string $value): string
    {
        return 'checkout-customer-code:'.hash('sha256', $request->session()->getId().'|'.$channel.'|'.$value);
    }

    private function normalizeCheckoutMobile(string $mobile, ?string $callingCode = null): string
    {
        $digits = PhoneNumberMatch::normalized(trim($mobile), $callingCode);
        return $digits === '' ? '' : '+'.$digits;
    }

    private function checkoutCallingCode(string $internationalMobile): ?string
    {
        $digits = ltrim($internationalMobile, '+');
        $callingCodes = Cache::remember('checkout-phone-calling-codes', now()->addDay(), fn () => DB::table('countries')
            ->whereNotNull('callcode')->whereNull('deleted_at')->pluck('callcode')
            ->map(fn ($code) => preg_replace('/\D+/', '', (string) $code))
            ->filter(fn ($code) => preg_match('/^[1-9]\d{0,2}$/', $code))
            ->unique()->sortByDesc(fn ($code) => strlen($code))->values());

        return $callingCodes->first(fn ($code) => str_starts_with($digits, $code));
    }

    private function findCheckoutCustomer(string $channel, string $value): ?Customer
    {
        $userQuery = User::query()->whereNull('deleted_at');
        if ($channel === 'email') {
            $userQuery->whereRaw('LOWER(email) = ?', [$value]);
        } else {
            $digits = ltrim($value, '+');
            $callingCode = $this->checkoutCallingCode($value);
            $nationalNumber = $callingCode && str_starts_with($digits, $callingCode) ? substr($digits, strlen($callingCode)) : '';
            $phoneCandidates = array_values(array_filter(array_unique([$digits, $nationalNumber])));
            $placeholders = implode(',', array_fill(0, count($phoneCandidates), '?'));
            $userQuery->whereRaw(PhoneNumberMatch::sqlExpression('users.phone', $callingCode)." IN ({$placeholders})", $phoneCandidates);
        }

        $users = $userQuery->limit(2)->get(['users.*']);
        if ($users->count() !== 1) return null;
        $user = $users->first();

        $customer = $this->customerService->customerForUser($user);
        if ($customer) return $customer->load('user');

        $customerId = DB::table('customer_user_aliases')->where('user_id', $user->id)->value('customer_id');
        return $customerId ? Customer::query()->with('user')->find($customerId) : null;
    }

    private function isEligibleCheckoutCustomer(?Customer $customer): bool
    {
        return (bool) ($customer?->user
            && $customer->user->is_active
            && ! $customer->user->trashed()
            && ($customer->user->is_guest || $customer->user->hasRole('customer')));
    }

    /**
     * Reload booking with eager loaded relations for email sending
     */
    protected function reloadBookingForEmail(Booking $booking): Booking
    {
        return Booking::with([
            'customer',
            'customer.user',
            'bookingAddons.addon',
            'bookingItems.vehicleGroup',
            'bookingItems.serviceType',
            'bookingItems.booking',
            'acceptedTerms.terms'
        ])->findOrFail($booking->id);
    }

    /**
     * Normalize checkout-selected extra kilometers into a stable shape for
     * booking items, emails, success pages, and downstream booking views.
     */
    protected function normalizeCartExtraKm(array $item): ?array
    {
        $extraKm = $item['extra_km'] ?? null;
        if (!is_array($extraKm)) {
            return null;
        }

        $kilometers = (float) ($extraKm['km'] ?? ($extraKm['quantity'] ?? 0));
        $rate = (float) ($extraKm['rate_per_km'] ?? ($extraKm['rate'] ?? ($extraKm['price_per_km'] ?? 0)));
        $total = (float) ($extraKm['total_cost'] ?? ($extraKm['total'] ?? ($kilometers * $rate)));

        if ($kilometers <= 0 || $total <= 0) {
            return null;
        }

        return [
            'type' => 'extra_km',
            'code' => 'extra_km',
            'label' => 'Extra Kilometers',
            'quantity' => $kilometers,
            'km' => $kilometers,
            'rate' => $rate,
            'rate_per_km' => $rate,
            'total' => $total,
            'total_cost' => $total,
            'currency' => $extraKm['currency'] ?? $this->currencyService->getBookingBaseCurrency(),
            'added_at' => $extraKm['added_at'] ?? null,
        ];
    }

    /**
     * Display checkout page
     */
    public function index(Request $request)
    {
        $bookingSettings = $this->websiteSettingsService->getBookingSettings();
        $guestBookingEnabled = $this->normalizeBoolean($bookingSettings['guest_booking_enabled'] ?? null, true);
        $customerLookupEnabled = $this->normalizeBoolean($bookingSettings['checkout_returning_customer_lookup_enabled'] ?? null, true);
        $verificationResendAfter = (int) ($bookingSettings['checkout_customer_verification_resend_seconds'] ?? 60);
        $bookingBaseCurrency = $this->resolveBookingBaseCurrency($bookingSettings);
        try {
            $cartModel = $this->cartService->getOrCreateCart();
            $cartData = $this->cartService->toArray($cartModel);
            $cart = $cartData['items'] ?? [];

            if (empty($cart)) {
                return redirect()->route('home')->with('error', 'Your cart is empty.');
            }
        } catch (\Exception $e) {
            Log::error('Error loading cart for checkout: ' . $e->getMessage());
            return redirect()->route('home')->with('error', 'Error loading your cart.');
        }

        $verifiedCheckoutCustomer = null;
        if ($customerLookupEnabled && session('checkout_verified_customer_id')
            && now()->timestamp - (int) session('checkout_verified_email_at', 0) <= 1800) {
            $customer = Customer::query()->with(['user', 'country'])->find(session('checkout_verified_customer_id'));
            if ($this->isEligibleCheckoutCustomer($customer)) {
                $verifiedCheckoutCustomer = [
                    'first_name' => $customer->user->first_name,
                    'last_name' => $customer->user->last_name,
                    'email' => $customer->user->email,
                    'phone' => $this->normalizeCheckoutMobile((string) $customer->user->phone, $customer->country?->callcode),
                    'address' => $customer->address,
                    'city' => $customer->city,
                    'country' => $customer->getAttribute('country') ?: $customer->getRelation('country')?->name,
                    'identification' => $customer->nic,
                ];
            }
        }

        // Resolve airport-transfer behavior from service configuration so every
        // public website theme uses the same service metadata.
        $serviceTypeIds = collect($cart)->map(fn ($item) => data_get($item, 'service_type_data.id'))->filter()->unique()->values();
        $serviceTypeCodes = collect($cart)->map(fn ($item) => data_get($item, 'service_type'))->filter()->unique()->values();
        $airportServiceTypes = ServiceType::publicContext()
            ->where('category', 'airport')
            ->where(function ($query) use ($serviceTypeIds, $serviceTypeCodes) {
                if ($serviceTypeIds->isNotEmpty()) {
                    $query->whereIn('id', $serviceTypeIds);
                }
                if ($serviceTypeCodes->isNotEmpty()) {
                    $method = $serviceTypeIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('code', $serviceTypeCodes);
                }
            })
            ->get(['id', 'code']);
        $airportServiceTypeIds = $airportServiceTypes->pluck('id')->map(fn ($id) => (string) $id)->all();
        $airportServiceTypeCodes = $airportServiceTypes->pluck('code')->all();
        $hasAirportTransfer = collect($cart)->contains(fn ($item) =>
            in_array((string) data_get($item, 'service_type_data.id', ''), $airportServiceTypeIds, true)
            || in_array((string) data_get($item, 'service_type', ''), $airportServiceTypeCodes, true)
        );

        $paymentSettings = $this->resolvePaymentSettings();
        $paymentMethods = $this->getAvailablePaymentMethods(
            $paymentSettings['webxpay_enabled'],
            $paymentSettings['payment_online_enabled'],
            $paymentSettings['payment_offline_enabled']
        );
        $onlinePaymentEnabled = $paymentMethods->has('online');
        $offlinePaymentEnabled = $paymentMethods->has('offline');
        $advancePaymentEnabled = $onlinePaymentEnabled && $paymentSettings['advance_payment_enabled'];
        $allowedPaymentTypes = ['quotation'];
        if ($onlinePaymentEnabled) {
            $allowedPaymentTypes[] = 'full';
            if ($advancePaymentEnabled) $allowedPaymentTypes[] = 'advance';
        }
        if ($offlinePaymentEnabled) $allowedPaymentTypes[] = 'checkin';

        $paymentType = $request->old('payment_type', $request->query('type', 'full'));
        if (! in_array($paymentType, $allowedPaymentTypes, true)) {
            $paymentType = $onlinePaymentEnabled ? 'full' : ($offlinePaymentEnabled ? 'checkin' : 'quotation');
        }

        // Get dynamic T&C grouped by service type and payment type based on cart items
        $termsByService = $this->getCheckoutServiceTerms($cart);
        $termsByPaymentType = [];

        foreach ($allowedPaymentTypes as $type) {
            $paymentTerms = TermsAndCondition::getPaymentTermsForCheckout($type);
            if ($paymentTerms && $paymentTerms->count()) {
                $termsByPaymentType[$type] = $paymentTerms;
            }
        }

        $advancePercentage = $paymentSettings['advance_payment_percentage'];
        $advanceMinAmount = $this->currencyService->convertFromLKR(
            $paymentSettings['advance_payment_min_amount'],
            $cartData['currency'] ?? $this->currencyService->getSelectedCurrency()
        );

        // Load countries for dynamic dropdown
        $countries = \App\Models\Country::orderBy('name')->get(['id', 'name', 'code', 'callcode']);
        $checkoutDefaultCountry = strtolower((string) DB::table('companies')
            ->join('countries', 'countries.id', '=', 'companies.country_id')
            ->where('companies.id', $this->websiteSettingsService->resolveCompanyId())
            ->value('countries.code'));
        if (! preg_match('/^[a-z]{2}$/', $checkoutDefaultCountry)
            || ! $countries->contains(fn ($country) => strtolower((string) $country->code) === $checkoutDefaultCountry)) {
            $checkoutDefaultCountry = $countries->contains(fn ($country) => strtolower((string) $country->code) === 'lk')
                ? 'lk'
                : strtolower((string) ($countries->first()?->code ?: 'lk'));
        }
        $checkoutDefaultCountryName = $countries->first(fn ($country) => strtolower((string) $country->code) === $checkoutDefaultCountry)?->name
            ?? 'Sri Lanka';

        return view('checkout', compact(
            'cart',
            'cartData',
            'paymentType',
            'termsByService',
            'termsByPaymentType',
            'paymentMethods',
            'advancePaymentEnabled',
            'advancePercentage',
            'advanceMinAmount',
            'offlinePaymentEnabled',
            'countries',
            'checkoutDefaultCountry',
            'checkoutDefaultCountryName',
            'hasAirportTransfer',
            'guestBookingEnabled',
            'customerLookupEnabled',
            'verificationResendAfter',
            'verifiedCheckoutCustomer'
        ));
    }

    protected function getCheckoutServiceTerms(array $cart): array
    {
        $termsByService = [];

        foreach ($cart as $item) {
            $serviceTypeId = data_get($item, 'service_type_data.id');
            $code = $item['service_type'] ?? null;
            if (!$serviceTypeId && !$code) {
                continue;
            }

            $serviceType = ServiceType::publicContext()
                ->when($serviceTypeId, fn ($query) => $query->whereKey($serviceTypeId),
                    fn ($query) => $query->where('code', $code))
                ->first();
            $terms = collect();
            if ($serviceType) {
                $terms = $terms->merge(TermsAndCondition::getServiceTerms($serviceType->id));
                $code = $serviceType->code;
            }
            if ($code) {
                $terms = $terms->merge(TermsAndCondition::getServiceTerms($code));
            }
            if ($terms->isNotEmpty()) {
                $label = $serviceType?->name ?? $code;
                $termsByService[$label] = collect($termsByService[$label] ?? [])
                    ->merge($terms)->unique('id')->values();
            }
        }

        $general = TermsAndCondition::getGeneralServiceTerms();
        if ($general->isNotEmpty()) {
            $termsByService['general'] = $general;
        }

        return $termsByService;
    }

    /**
     * Process checkout form submission
     */
    public function process(Request $request)
    {
        $bookingSettings = $this->websiteSettingsService->getBookingSettings();
        $bookingBaseCurrency = $this->resolveBookingBaseCurrency($bookingSettings);
        $guestBookingEnabled = $this->normalizeBoolean($bookingSettings['guest_booking_enabled'] ?? null, true);
        $customerLookupEnabled = $this->normalizeBoolean($bookingSettings['checkout_returning_customer_lookup_enabled'] ?? null, true);
        if (! $customerLookupEnabled) {
            $request->session()->forget([
                'checkout_verified_email', 'checkout_verified_email_at',
                'checkout_verified_customer_id', 'checkout_verified_customer_mobile',
            ]);
        }
        if (!$guestBookingEnabled && !Auth::check() && ! ($customerLookupEnabled && $this->hasRecentCheckoutVerification())) {
            return redirect()->route('home')
                ->with('error', 'New customer booking is currently unavailable. Sign in or verify an existing customer account to continue.');
        }

        $paymentSettings = $this->resolvePaymentSettings();
        $webxpayEnabled = $paymentSettings['webxpay_enabled'];
        $advancePercentage = $paymentSettings['advance_payment_percentage'];
        $allowedPaymentMethodKeys = $this->getAvailablePaymentMethods(
            $webxpayEnabled,
            $paymentSettings['payment_online_enabled'],
            $paymentSettings['payment_offline_enabled']
        )->keys()->all();
        if (empty($allowedPaymentMethodKeys) && $request->input('payment_type') !== 'quotation') {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Online and pay-on-check-in options are unavailable. You can still request a quotation.');
        }
        $advancePaymentEnabled = in_array('online', $allowedPaymentMethodKeys, true) && $paymentSettings['advance_payment_enabled'];
        $allowedPaymentTypes = ['quotation'];
        if (in_array('online', $allowedPaymentMethodKeys, true)) {
            $allowedPaymentTypes[] = 'full';
            if ($advancePaymentEnabled) $allowedPaymentTypes[] = 'advance';
        }
        if (in_array('offline', $allowedPaymentMethodKeys, true)) $allowedPaymentTypes[] = 'checkin';

        foreach ([
            'first_name', 'last_name', 'phone', 'phone_country_code',
            'phone_international', 'email', 'address', 'city', 'country',
            'identification', 'flight_airline', 'flight_number',
            'special_notes', 'additional_notes',
            'flight_arrival_date', 'flight_arrival_time', 'contact_time', 'budget_range',
        ] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $trimmed = trim($value);
                $request->merge([$field => $trimmed === '' ? null : $trimmed]);
            }
        }
        foreach ([
            'identification', 'flight_airline', 'flight_number',
            'special_notes', 'additional_notes', 'flight_arrival_date',
            'flight_arrival_time', 'contact_time', 'budget_range',
        ] as $field) {
            $value = $request->input($field);
            if (!is_string($value) || $value === '') {
                continue;
            }

            $meaningfulCharacters = preg_match_all('/[\p{L}\p{N}]/u', $value);
            if (Str::length($value) <= 1 || $meaningfulCharacters < 2) {
                $request->merge([$field => null]);
            }
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower($request->input('email'))]);
        }
        $contactMethod = Auth::check() && filled(Auth::user()->email) && filled(Auth::user()->phone)
            ? 'email'
            : $request->input('contact_method', 'mobile');
        if (! in_array($contactMethod, ['email', 'mobile'], true)) {
            $contactMethod = 'mobile';
        }
        $request->merge(['contact_method' => $contactMethod]);
        $onlinePaymentNeedsBothContacts = in_array($request->input('payment_type'), ['full', 'advance'], true)
            && in_array('online', $allowedPaymentMethodKeys, true);
        if (! Auth::check() && ! ($customerLookupEnabled && $this->hasRecentCheckoutVerification()) && ! $onlinePaymentNeedsBothContacts) {
            // Contact choice is enforced server-side; hidden fields cannot become accidental identifiers.
            if ($contactMethod === 'email') {
                $request->merge(['phone' => null, 'phone_country_code' => null, 'phone_international' => null]);
            } else {
                $request->merge(['email' => null]);
            }
        }
        $request->merge([
            'identification' => filled($request->input('identification'))
                ? strtoupper(trim((string) $request->input('identification')))
                : null,
        ]);
        $existingCustomerId = filled($request->input('email')) ? Customer::query()
            ->whereHas('user', fn ($query) => $query->whereRaw(
                'LOWER(email) = ?',
                [strtolower(trim((string) $request->input('email')))]
            ))
            ->value('id') : null;
        if (!$existingCustomerId && Auth::check()) {
            $existingCustomerId = $this->customerService->customerForUser(Auth::user())?->id;
        }
        $uniqueIdentification = Rule::unique('customers', 'nic');
        if ($existingCustomerId) {
            $uniqueIdentification->ignore($existingCustomerId);
        }

        // Define validation rules
        $rules = [
            'contact_method' => ['required', 'in:email,mobile'],
            'payment_type' => 'required|in:' . implode(',', $allowedPaymentTypes),
            'first_name' => ['required', 'string', 'max:255', 'regex:/^(?=.*\p{L})[\p{L}\p{M} .\x27?-]+$/u'],
            'last_name' => ['nullable', 'string', 'max:255', 'regex:/^(?=.*\p{L})[\p{L}\p{M} .\x27?-]+$/u'],
            'phone' => ['nullable', 'required_if:contact_method,mobile', 'string', 'min:5', 'max:20', 'regex:/^(?=(?:.*\d){5,})[+\d\s().-]+$/'],
            'phone_country_code' => 'nullable|required_if:contact_method,mobile|string|max:5',
            'phone_international' => ['nullable', 'required_if:contact_method,mobile', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'email' => 'nullable|required_if:contact_method,email|email|max:255',
            'identification' => [
                'nullable', 'string', 'max:50',
                function ($attribute, $value, $fail): void {
                    if (preg_match_all('/[\p{L}\p{N}]/u', (string) $value) < 2) {
                        $fail('Enter at least two letters or numbers. ID and passport formats from any country are accepted.');
                    }
                },
                $uniqueIdentification,
            ],
            'address' => ['required', 'string', 'max:500', 'regex:/^(?=(?:.*[\p{L}\p{N}]){2})[\p{L}\p{M}\p{N}\s.,#\x27’()\/-]+$/u'],
            'city' => ['required', 'string', 'max:100', 'regex:/^(?=.*\p{L})[\p{L}\p{M} .\x27’-]+$/u'],
            'country' => ['required', 'string', 'max:100', 'regex:/^(?=.*\p{L})[\p{L}\p{M} .\x27’-]+$/u'],
            'special_notes' => 'nullable|string|max:1000',
            'flight_airline' => 'nullable|string|max:100',
            'flight_number' => 'nullable|string|max:20',
            'flight_arrival_date' => 'nullable|date',
            'flight_arrival_time' => 'nullable|date_format:H:i',
            'additional_notes' => 'nullable|string|max:1000',
            'contact_time' => 'nullable|string|in:morning,afternoon,evening,anytime',
            'budget_range' => 'nullable|string|in:under-500,500-1000,1000-2000,over-2000',
            'save_info' => 'boolean',
            'marketing_consent' => 'boolean',
        ];

        // Custom validation messages
        $messages = [
            'payment_type.required' => 'Choose one of the payment options shown.',
            'payment_type.in' => 'This payment option is no longer available. Choose one of the options shown.',
            'first_name.required' => 'Please enter your first name.',
            'phone.required' => 'Please enter your phone number.',
            'phone.required_if' => 'Please enter your mobile number.',
            'phone.min' => 'Phone number is too short.',
            'phone.regex' => 'Enter a valid phone number with at least five digits.',
            'phone_country_code.required' => 'Please select a valid country for your phone number.',
            'phone_country_code.required_if' => 'Please select the country for your mobile number.',
            'phone_international.required' => 'Please enter a valid international phone number.',
            'phone_international.required_if' => 'Please enter a valid mobile number.',
            'phone_international.regex' => 'Please enter a valid international phone number format with country code.',
            'email.required' => 'Please enter your email address.',
            'email.required_if' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'first_name.regex' => 'Enter a name containing at least one letter. Numbers or punctuation alone are not accepted.',
            'last_name.regex' => 'Enter a name containing at least one letter. Numbers or punctuation alone are not accepted.',
            'identification.required' => 'Please enter your identification number.',
            'identification.unique' => 'This identification number is already linked to another customer. Please check the number or use the email address on that customer account.',
            'address.regex' => 'Enter an address with at least two letters or numbers.',
            'city.regex' => 'Enter a city name containing at least one letter.',
            'country.regex' => 'Enter a country name containing at least one letter.',
            'address.required' => 'Please enter your address.',
            'city.required' => 'Please enter your city.',
            'country.required' => 'Please enter your country.',
            'terms_accepted.*.accepted' => 'You must accept all applicable terms and conditions to proceed.',
        ];

        $validated = $request->validate($rules, $messages);

        $verifiedCustomerId = $customerLookupEnabled ? session('checkout_verified_customer_id') : null;
        if ($verifiedCustomerId && now()->timestamp - (int) session('checkout_verified_email_at', 0) <= 1800) {
            $verifiedCustomer = Customer::query()->with('user')->find($verifiedCustomerId);
            $phoneCallingCode = $validated['phone_country_code'] ?? null;
            $submittedMobile = $this->normalizeCheckoutMobile((string) ($validated['phone_international'] ?? ''));
            $submittedEmail = mb_strtolower(trim((string) ($validated['email'] ?? '')));
            if (! ($verifiedCustomer?->user
                && (! filled($verifiedCustomer->user->email)
                    || mb_strtolower(trim((string) $verifiedCustomer->user->email)) === $submittedEmail)
                && (! filled($verifiedCustomer->user->phone)
                    || $this->normalizeCheckoutMobile((string) $verifiedCustomer->user->phone, $phoneCallingCode) === $submittedMobile))) {
                throw ValidationException::withMessages(['email' => ['For a returning customer, keep the saved email and mobile number. You can update the other details below.']]);
            }
        }

        if (in_array($validated['payment_type'], ['full', 'advance'], true)
            && in_array('online', $allowedPaymentMethodKeys, true)
            && (! filled($validated['email'] ?? Auth::user()?->email)
                || ! filled($validated['phone_international'] ?? $validated['phone'] ?? Auth::user()?->phone))) {
            throw ValidationException::withMessages([
                'payment_type' => ['Online payment needs both an email address and mobile number. Switch the contact choice to enter each one.'],
            ]);
        }

        // Automatically set payment method based on payment type
        $paymentType = $validated['payment_type'] ?? 'full';
        switch ($paymentType) {
            case 'checkin':
                if (!in_array('offline', $allowedPaymentMethodKeys, true)) {
                    return redirect()->back()
                        ->withInput()
                        ->with('error', 'Pay on check-in is currently unavailable. Please choose another payment option.');
                }
                $validated['payment_method'] = 'offline';
                break;
            case 'quotation':
                $validated['payment_method'] = null; // No payment method for quotation
                break;
            case 'advance':
            case 'full':
            default:
                // Default to online (WebXPay) for full and advance payments
                if (!in_array('online', $allowedPaymentMethodKeys, true)) {
                    return redirect()->back()
                        ->withInput()
                        ->with('error', 'Online payment is currently unavailable. Please try again later.');
                }
                $validated['payment_method'] = 'online';
                break;
        }

        // Get cart from database
        $cartModel = $this->cartService->getOrCreateCart();
        $cart = $cartModel->items ?? [];

        if (empty($cart)) {
            return redirect()->route('home')->with('error', 'Your cart is empty.');
        }

        $availabilityState = $this->bookingFlowService->validatePublicCartAvailability($cart);
        if ($paymentType !== 'quotation' && !($availabilityState['available'] ?? false)) {
            return redirect()->back()
                ->withInput()
                ->with(
                    'error',
                    'One or more selected vehicle options are no longer available for these dates. '
                    . 'Choose Request Quotation to send these dates to our team, or return to the results.'
                );
        }

        // Recalculate totals and revalidate any applied portal-owned promo code
        // against the current cart and existing customer before creating a booking.
        $checkoutEmail = mb_strtolower(trim((string) ($validated['email'] ?? '')));
        $emailIdentityConfirmed = filled($checkoutEmail) && ((Auth::check()
                && mb_strtolower(trim((string) Auth::user()->email)) === $checkoutEmail)
            || ($customerLookupEnabled
                && mb_strtolower(trim((string) session('checkout_verified_email'))) === $checkoutEmail
                && now()->timestamp - (int) session('checkout_verified_email_at', 0) <= 1800));
        $existingCustomer = Auth::check()
            ? $this->customerService->customerForUser(Auth::user())
            : ($emailIdentityConfirmed ? $this->customerService->getCustomerByEmail($validated['email']) : null);
        if (! $existingCustomer && $customerLookupEnabled && $this->hasRecentCheckoutVerification()) {
            $existingCustomer = Customer::query()->find(session('checkout_verified_customer_id'));
        }
        $promoState = $this->cartService->updateTotals($cartModel, $existingCustomer?->id);
        $cartModel->refresh();

        if (!($promoState['valid'] ?? true)) {
            return redirect()->back()
                ->withInput()
                ->with(
                    'error',
                    ($promoState['message'] ?? 'The applied promo code is no longer valid.')
                        . ' It has been removed; please review the updated total.'
                );
        }

        // Persist the exact currency snapshot shown to the customer. The cart is
        // stored in LKR, while toArray() converts every customer-facing amount.
        $cartData = $this->cartService->toArray($cartModel);
        $cart = $cartData['items'] ?? [];
        $bookingCurrency = $cartData['currency'] ?? $this->currencyService->getSelectedCurrency();

        // Re-check dynamic Terms & Conditions acceptance based on cart service types
        $requiredTerms = collect($this->getCheckoutServiceTerms($cart))->flatMap(fn ($terms) => $terms);
        $requiredTerms = $requiredTerms->merge(
            TermsAndCondition::getPaymentTermsForCheckout($validated['payment_type'] ?? 'full')
        );
        $requiredTerms = $requiredTerms->unique('id');

        // Validate that all required terms are accepted
        $accepted = $request->input('terms_accepted', []);
        foreach ($requiredTerms->pluck('id')->unique()->toArray() as $tcId) {
            if (empty($accepted[$tcId])) {
                // Throw validation error
                return redirect()->back()->withInput()->withErrors(['terms_accepted' => 'You must accept all applicable terms and conditions to proceed.']);
            }
        }
        try {
            DB::beginTransaction();

            if ($paymentType !== 'quotation') {
                $vehicleGroupIds = collect($cart)
                    ->pluck('vehicle_group_id')
                    ->filter()
                    ->unique()
                    ->all();
                $this->bookingFlowService->lockVehicleGroupCapacity($vehicleGroupIds);
                $lockedAvailability = $this->bookingFlowService->validatePublicCartAvailability($cart);
                if (!($lockedAvailability['available'] ?? false)) {
                    throw ValidationException::withMessages([
                        'availability' => ['One or more selected vehicle options are no longer available. Choose another option or request a quotation.'],
                    ]);
                }
            }

            // Prepare customer data
            $validated['customer_name'] = trim($validated['first_name'] . ' ' . $validated['last_name']);
            $validated['customer_email'] = $validated['email'] ?? null;
            // Use international format phone number for payment gateway
            $validated['customer_phone'] = $validated['phone_international'] ?? $validated['phone'] ?? Auth::user()?->phone;
            $validated['customer_address'] = $validated['address'];
            $validated['customer_city'] = $validated['city'];
            $validated['customer_country'] = $validated['country'];
            $validated['customer_identification'] = $validated['identification'];

            // Step 1: Create or get customer
            $customer = $this->customerService->getOrCreateCustomer(
                $validated,
                $guestBookingEnabled && ! $customerLookupEnabled
            );

            // Use the converted snapshot shown on checkout, not the raw LKR cart.
            $totals = $cartData['totals'] ?? [];
            $subtotal = $totals['subtotal'] ?? 0;
            $serviceFee = $totals['service_fee'] ?? 0;
            $tax = $totals['tax'] ?? 0;
            $vat = $totals['vat'] ?? 0;
            $discount = $totals['coupon_discount'] ?? 0;
            $total = $totals['total'] ?? 0;

            $subtotal = max(0, $this->currencyService->roundAmount($subtotal, $bookingCurrency));
            $serviceFee = max(0, $this->currencyService->roundAmount($serviceFee, $bookingCurrency));
            $tax = max(0, $this->currencyService->roundAmount($tax, $bookingCurrency));
            $vat = max(0, $this->currencyService->roundAmount($vat, $bookingCurrency));
            $discount = max(0, $this->currencyService->roundAmount($discount, $bookingCurrency));
            $total = max(0, $this->currencyService->roundAmount($total, $bookingCurrency));


            // Calculate payment amount based on type
            // Fetch advance percentage from database
            $advanceMinAmount = $this->currencyService->convertFromLKR(
                $paymentSettings['advance_payment_min_amount'],
                $bookingCurrency
            );
            $paymentAmount = match ($validated['payment_type']) {
                'advance' => $total * ($advancePercentage / 100),
                'quotation' => 0,
                'checkin' => $total,
                default => $total
            };
            if ($validated['payment_type'] === 'advance' && $advanceMinAmount > 0) {
                $paymentAmount = max($paymentAmount, $advanceMinAmount);
                $paymentAmount = min($paymentAmount, $total);
            }
            $paymentAmount = max(0, $this->currencyService->roundAmount($paymentAmount, $bookingCurrency));

            // Prepare flight details
            $flightDetails = null;
            // Parenthesize each optional field: without grouping, PHP can
            // evaluate the second array access outside its null-coalescing
            // fallback when the airline field is absent.
            if (($validated['flight_airline'] ?? null) || ($validated['flight_number'] ?? null)) {
                $flightDetails = [
                    'airline' => $validated['flight_airline'] ?? null,
                    'flight_number' => $validated['flight_number'] ?? null,
                    'arrival_date' => $validated['flight_arrival_date'] ?? null,
                    'arrival_time' => $validated['flight_arrival_time'] ?? null,
                ];
            }

            // Get first cart item to extract booking dates (or use default)
            $firstItem = collect($cart)->first();
            $fromDate = $firstItem['pickup_date'] ?? now();
            $toDate = $firstItem['return_date'] ?? now()->addDays(1);

            $serviceTypeId = ServiceType::publicContext()
                ->where('code', $firstItem['service_type'] ?? null)
                ->value('id');
            // return $firstItem;
            // Step 2: Create booking record linked to customer

            switch ($validated['payment_type']) {
                case 'quotation':
                    $number = Booking::generateQuotationNumber();
                    break;
                case 'advance':
                case 'checkin':
                case 'online':
                case 'full':
                    $number = Booking::generateBookingNumber();
                    break;
                default:
                    $number = Booking::generateQuotationNumber();
            }

            $booking = Booking::create([
                'customer_id' => $customer->id,
                'booking_number' => $number,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'from_time' => $firstItem['from_time'] ?? null,
                'to_time' => $firstItem['to_time'] ?? null,
                'base_amount' => $subtotal,
                'service_fee' => $serviceFee,
                'tax_amount' => $tax,
                'vat_amount' => $vat,
                'discount_amount' => $discount,
                'total_estimated' => $total,
                'currency' => $bookingCurrency,
                'payment_method' => $validated['payment_method'] ?? null,
                'payment_status' => 'pending',
                'payment_type' => $validated['payment_type'],
                'amount_to_pay' => $paymentAmount,
                'special_requirements' => $validated['additional_notes'] ?? $validated['special_notes'] ?? null,
                'contact_time' => $validated['contact_time'] ?? null,
                'status' => config('booking.status.draft'),
                'booking_source' => 'public',
                'booking_origin' => 'website',
                'responsible_user_id' => null,
                'created_from' => 'web',
                'created_user_id' => Auth::id(),
            ]);

            // Store additional data in workflow_data
            $workflowData = [
                'additional_notes' => $validated['additional_notes'] ?? $validated['special_notes'] ?? null,
                'budget_range' => $validated['budget_range'] ?? null,
                'checkout_contact' => [
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'] ?? null,
                    'email' => $validated['customer_email'],
                    'phone' => $validated['customer_phone'],
                    'address' => $validated['customer_address'],
                    'city' => $validated['customer_city'],
                    'country' => $validated['customer_country'],
                ],
                'cart_items' => $cart,
                'display_currency' => $bookingCurrency,
                'base_currency' => 'LKR',
                'configured_booking_base_currency' => $bookingBaseCurrency,
                'exchange_rate' => $this->currencyService->getExchangeRate(
                    'LKR',
                    $bookingCurrency
                ),
                'service_packages' => collect($cart)->map(function ($item) {
                    return [
                        'vehicle_group_id' => $item['vehicle_group_id'] ?? null,
                        'service_package_id' => $item['service_package_id'] ?? null,
                        'service_package_info' => $item['service_package_info'] ?? null,
                    ];
                })->toArray(),
            ];

            // Persist accepted Terms & Conditions for this booking if provided
            $acceptedTerms = $request->input('terms_accepted', []);
            if (is_array($acceptedTerms) && !empty($acceptedTerms)) {
                foreach ($acceptedTerms as $termId => $version) {
                    $tc = TermsAndCondition::find($termId);
                    if ($tc) {
                        \App\Models\Booking\BookingTerm::updateOrCreate(
                            ['booking_id' => $booking->id, 'terms_and_condition_id' => $termId],
                            ['terms_version' => $version, 'accepted_at' => now()]
                        );
                    }
                }
            }

            if ($flightDetails) {
                $workflowData['flight_details'] = $flightDetails;
            }

            // Store promo code information if applied
            if (!empty($cartModel->coupon_code)) {
                $workflowData['promo_code'] = [
                    'code' => $cartModel->coupon_code,
                    'discount_amount' => $cartModel->coupon_discount ?? 0,
                    'order_amount' => $subtotal,
                ];
            }

            $booking->workflow_data = $workflowData;
            $booking->save();
            app(\App\Services\BookingResponsibilityService::class)->notifyBookingCreated($booking);

            // Create booking items for each cart item
            foreach ($cart as $cartKey => $item) {
                $extraKmCharge = $this->normalizeCartExtraKm($item);
                $itemFromDate = isset($item['from_date']) ? Carbon::parse($item['from_date']) : $fromDate;
                $itemToDate = isset($item['to_date']) ? Carbon::parse($item['to_date']) : $toDate;
                $itemFromTime = $item['from_time'] ?? $booking->from_time ?? '00:00';
                $itemToTime = $item['to_time'] ?? $booking->to_time ?? '00:00';

                // Ensure dates are Carbon instances
                if (is_string($itemFromDate)) {
                    $itemFromDate = Carbon::parse($itemFromDate);
                }
                if (is_string($itemToDate)) {
                    $itemToDate = Carbon::parse($itemToDate);
                }

                // Calculate duration in days (use from cart if available, otherwise calculate)
                $durationDays = isset($item['days']) ? intval($item['days']) : $itemFromDate->diffInDays($itemToDate);
                if ($durationDays <= 0) {
                    $durationDays = 1;
                }

                // Extract pricing from cart item - cart stores pre-calculated prices
                $unitPrice = 0;
                $totalPrice = 0;

                // Cart items have 'price' (unit price) and 'total_price' (total price)
                if (isset($item['price'])) {
                    $unitPrice = max(0, $this->currencyService->roundAmount($item['price'], $bookingCurrency));
                }

                if (isset($item['total_price'])) {
                    $totalPrice = max(0, $this->currencyService->roundAmount($item['total_price'], $bookingCurrency));
                } elseif (isset($item['total'])) {
                    $totalPrice = max(0, $this->currencyService->roundAmount($item['total'], $bookingCurrency));
                } elseif (isset($item['amount'])) {
                    $totalPrice = max(0, $this->currencyService->roundAmount($item['amount'], $bookingCurrency));
                } elseif ($unitPrice > 0 && $durationDays > 0) {
                    // Fallback: calculate total from unit price and duration
                    $totalPrice = max(0, $this->currencyService->roundAmount($unitPrice * $durationDays, $bookingCurrency));
                }

                // Normalize service_type_id: extract from service_type_data['id'] or ensure it's a valid UUID or null
                $serviceTypeId = null;
                if (isset($item['service_type_data']['id']) && !empty($item['service_type_data']['id'])) {
                    $serviceTypeIdStr = strval($item['service_type_data']['id']);
                    // UUID validation pattern (8-4-4-4-12 hex digits)
                    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $serviceTypeIdStr)) {
                        $serviceTypeId = $serviceTypeIdStr;
                    }
                }

                // In booking_items, 'quantity' field stores duration in days for vehicle rentals
                $durationQuantity = $durationDays;
                $itemAddons = is_array($item['addons'] ?? null) ? $item['addons'] : [];
                $itemCustomizations = is_array($item['customizations'] ?? null) ? $item['customizations'] : [];
                $itemMetadata = [
                    'cart_key' => $cartKey,
                    'item_index' => array_search($cartKey, array_keys($cart)),
                    'service_package_id' => $item['service_package_id'] ?? null,
                    'service_package_info' => $item['service_package_info'] ?? null,
                    // Persist distance and duration details for later communication
                    'distance_details' => $item['distance_details'] ?? null,
                    'calculation_type' => $item['distance_details']['calculation_type'] ?? null,
                    'effective_days' => $item['distance_details']['effective_days'] ?? null,
                    'journey_duration_seconds' => $item['distance_details']['journey_duration_seconds'] ?? null,
                    'is_return_trip' => $item['is_return_trip'] ?? false,
                    'return_trip_date' => $item['return_trip_date'] ?? null,
                    'return_trip_time' => $item['return_trip_time'] ?? null,
                    'return_trip_pricing' => $item['return_trip_pricing'] ?? null,
                    'one_way_price' => $item['one_way_price'] ?? null,
                    'return_price' => $item['return_price'] ?? null,
                    'return_discount_percentage' => $item['return_discount_percentage'] ?? null,
                    'return_pickup_location' => $item['return_pickup_location'] ?? ($item['dropoff_location'] ?? null),
                    'return_dropoff_location' => $item['return_dropoff_location'] ?? ($item['pickup_location'] ?? null),
                ];

                if ($extraKmCharge) {
                    $itemCustomizations[] = $extraKmCharge;
                    $itemMetadata['extra_km'] = $extraKmCharge['km'];
                    $itemMetadata['extra_km_rate'] = $extraKmCharge['rate_per_km'];
                    $itemMetadata['extra_km_total'] = $extraKmCharge['total_cost'];
                    $itemMetadata['extra_km_currency'] = $extraKmCharge['currency'];
                    $itemMetadata['extra_km_details'] = $extraKmCharge;
                }

                // Create booking item
                $bookingItem = BookingItem::create([
                    'booking_id' => $booking->id,
                    'vehicle_group_id' => $item['vehicle_group_id'] ?? null,
                    'vehicle_id' => $item['vehicle_id'] ?? null,
                    'driver_id' => $item['driver_id'] ?? null,
                    'from_date' => $itemFromDate,
                    'to_date' => $itemToDate,
                    'from_time' => $itemFromTime,
                    'to_time' => $itemToTime,
                    'pickup_location' => json_encode([
                        'address' => $item['pickup_location'] ?? '',
                        'city' => $validated['city'] ?? '',
                        'country' => $validated['country'] ?? '',
                        'lat' => $item['pickup_lat'] ?? null,
                        'lng' => $item['pickup_lng'] ?? null,
                    ]),
                    'dropoff_location' => json_encode([
                        'address' => $item['dropoff_location'] ?? $item['pickup_location'] ?? '',
                        'city' => $validated['city'] ?? '',
                        'country' => $validated['country'] ?? '',
                        'lat' => $item['dropoff_lat'] ?? $item['pickup_lat'] ?? null,
                        'lng' => $item['dropoff_lng'] ?? $item['pickup_lng'] ?? null,
                    ]),
                    'duration_days' => $durationDays,
                    'duration_hours' => 0,
                    'service_type_id' => $serviceTypeId,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'quantity' => $durationQuantity,  // Stores duration in days for vehicle rentals
                    'currency' => $bookingCurrency,
                    'exchange_rate' => $this->currencyService->getExchangeRate(
                        'LKR',
                        $bookingCurrency
                    ),
                    'status' => 'confirmed',
                    'item_type' => 'vehicle_group',
                    'pricing_breakdown' => [
                        'base_price' => $unitPrice,
                        'quantity' => $durationQuantity,  // Duration days
                        'total' => $totalPrice,
                        'addon_charges' => array_sum(array_map(
                            fn ($addon) => is_array($addon) ? (float) ($addon['calculated_amount'] ?? 0) : 0,
                            $itemAddons
                        )),
                        'extra_km_charges' => $extraKmCharge['total_cost'] ?? 0,
                    ],
                    'addons' => $itemAddons,
                    'customizations' => $itemCustomizations,
                    'metadata' => $itemMetadata
                ]);

                // Save BookingAddons from this cart item
                if (!empty($item['addons']) && is_array($item['addons'])) {
                    foreach ($item['addons'] as $addonId => $addon) {
                        if (is_array($addon)) {
                            BookingAddon::create([
                                'booking_id' => $booking->id,
                                'addon_id' => $addonId,
                                'qty' => $addon['qty'] ?? 1,
                                'rate' => $addon['amount'] ?? 0,
                                'amount' => $addon['calculated_amount'] ?? 0,
                                'label' => $addon['name'] ?? 'Addon'
                            ]);
                        }
                    }
                }
            }

            // Store cart items for reference
            session()->put('pending_booking_id', $booking->id);
            session()->put('pending_booking_cart', $cart);
            // Handle different payment types

            switch ($validated['payment_type']) {
                case 'quotation':
                    return $this->processQuotationRequest($booking);

                case 'advance':
                    return $this->processPayment($booking, $validated, $paymentAmount);

                case 'full':
                    return $this->processPayment($booking, $validated, $paymentAmount);

                case 'checkin':
                    return $this->processOfflinePayment($booking, 'offline');

                default:
                    throw new \Exception('Invalid payment type');
            }
        } catch (ValidationException $e) {
            DB::rollBack();
            return redirect()->back()
                ->withInput()
                ->withErrors($e->errors());
        } catch (\Exception $e) {
            DB::rollback();
            if ($e instanceof QueryException) {
                $databaseMessage = strtolower($e->getMessage());
                if (str_contains($databaseMessage, 'customers_nic') || str_contains($databaseMessage, 'customers.nic')) {
                    return redirect()->back()->withInput()->withErrors([
                        'identification' => 'This identification number is already linked to another customer. Please check the number or use the email address on that customer account.',
                    ]);
                }
                if (str_contains($databaseMessage, 'users_email') || str_contains($databaseMessage, 'users.email')) {
                    return redirect()->back()->withInput()->withErrors([
                        'email' => 'This email address is already registered. Please use the email address on your existing customer account.',
                    ]);
                }
            }
            Log::error('Checkout processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'An error occurred while processing your booking. Please try again.');
        }
    }

    /**
     * Process quotation request
     */
    protected function processQuotationRequest(Booking $booking)
    {
        try {
            // Update booking status
            $booking->update([
                'status' => config('booking.status.quotation_requested'),
                'payment_status' => 'not_required',
            ]);


            // Ensure booking number uses 'QT' prefix for quotations (6-digit sequence)
            if (strpos($booking->booking_number ?? '', 'QT') !== 0) {
                $booking->booking_number = Booking::generateQuotationNumber();
                $booking->save();
            }

            // Mark cart as checked out
            $dbCart = $this->cartService->getOrCreateCart();
            $this->cartService->markAsCheckedOut($dbCart);

            // Reload booking with eager loaded relations for email
            $booking = $this->reloadBookingForEmail($booking);

            $this->smsAutomationService->queueWebsiteQuotationRequested($booking);

            // Send quotation request email to customer
            try {
                $this->sendBookingEmail($booking, new QuotationRequestMail($booking));
            } catch (\Exception $e) {
                Log::error('Quotation Request: Failed to send quotation email', [
                    'booking_id' => $booking->id,
                    'customer_email' => $booking->customer?->user?->email,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            DB::commit();

            Log::info('Checkout quotation completed', [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'status' => $booking->status,
            ]);


            return redirect()->route('checkout.success', [
                'type' => 'quotation',
                'reference' => $booking->booking_number,
                'initial_currency' => getBookingDisplayCurrency($booking),
            ])->with('success', 'Your quotation request has been submitted successfully! Our team will contact you within 24 hours.');
        } catch (\Exception $e) {
            Log::error('Quotation Request: Process failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Failed to process quotation request: ' . $e->getMessage());
        }
    }

    /**
     * Process payment
     */
    protected function processPayment(Booking $booking, array $validated, float $paymentAmount)
    {
        try {
            $paymentMethod = $validated['payment_method'];


            // Process payment based on method
            switch ($paymentMethod) {
                case 'online':
                    return $this->processOnlinePayment($booking, $paymentAmount);

                case 'offline':
                    return $this->processOfflinePayment($booking, $paymentMethod);

                default:
                    throw new \Exception('Invalid payment method');
            }
        } catch (\Exception $e) {
            Log::error('Payment Processing: Process failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Payment processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Process online payment via WebXPay
     */
    protected function processOnlinePayment(Booking $booking, float $amount)
    {
        if (!$this->webxPayService->isEnabled()) {
            Log::warning('Online Payment: WebXPay is not enabled; rejecting online checkout', [
                'booking_id' => $booking->id,
            ]);
            throw new \RuntimeException('Online payment is not available. Please select another payment method or try again later.');
        }

        try {
            // Create payment request with WebXPay
            $paymentType = $booking->payment_type === 'advance' ? 'advance' : 'full';

            $result = $this->webxPayService->createPayment($booking, $amount, $paymentType);

            if ($result['success']) {
                $this->storeGatewayPaymentSnapshot($booking, $result);
                // Update booking with payment details
                $booking->update([
                    'status' => config('booking.status.payment_processing'),
                    'payment_gateway_order_id' => $result['order_id'] ?? null,
                ]);


                // Store booking ID and RSA encrypted payment data in session
                session()->put('pending_booking_id', $booking->id);
                session()->put('webxpay_payment_data', [
                    'payment_url' => $result['payment_url'],
                    'order_id' => $result['order_id'],
                    'encrypted_payment' => $result['encrypted_payment'],
                    'secret_key' => $result['secret_key'],
                    'custom_fields' => $result['custom_fields'],
                    'enc_method' => $result['enc_method'],
                    'customer_data' => $result['customer_data'],
                    'return_url' => $result['return_url'] ?? null,
                    'cancel_url' => $result['cancel_url'] ?? null,
                    'notify_url' => $result['notify_url'] ?? null,
                ]);

                // Send payment initiated email
                $this->sendPaymentInitiatedEmail($booking, $amount);

                DB::commit();

                Log::info('Checkout payment initiated', [
                    'booking_id' => $booking->id,
                    'payment_type' => $paymentType,
                    'gateway_order_id' => $result['order_id'] ?? null,
                    'status' => $booking->status,
                ]);

                // Check if WebXPay uses RSA form redirect
                if (isset($result['method']) && $result['method'] === 'rsa_redirect') {
                    // Redirect to our payment redirect page that will auto-submit RSA form to WebXPay
                    return redirect()->route('checkout.webxpay.redirect');
                }

                // Direct URL redirect (if needed for other methods)
                return redirect($result['payment_url']);
            } else {
                $errorMessage = $result['message'] ?? $result['error'] ?? 'Payment gateway error';

                Log::warning('Online Payment: Payment gateway error, falling back to offline', [
                    'booking_id' => $booking->id,
                    'error' => $errorMessage
                ]);

                throw new \RuntimeException('Online payment could not be initiated. Please try again.');
            }
        } catch (\Exception $e) {
            Log::error('Online Payment: Exception occurred; checkout will not change the payment method', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException('Online payment could not be initiated. Please try again.');
        }
    }

    /**
     * Process offline payment (pay on check-in)
     */
    protected function processOfflinePayment(Booking $booking, string $method)
    {

        // For offline payments (pay on check-in), booking is confirmed but payment pending
        $booking->update([
            'status' => config('booking.status.confirmed'),
            'payment_status' => 'pending',
        ]);


        return $this->completeBooking($booking, $method, false);
    }

    /**
     * Complete booking and send confirmation
     */
    protected function completeBooking(Booking $booking, string $paymentMethod, bool $paymentProcessed = false, array $paymentDetails = [])
    {
        try {
            // Update booking status based on payment status
            $status = $paymentProcessed
                ? config('booking.status.confirmed')
                : config('booking.status.pending_payment');

            $booking->update([
                'payment_status' => $paymentProcessed ? 'paid' : 'pending',
                'status' => $status,
                'confirmed_at' => $paymentProcessed ? now() : null,
            ]);


            // Mark cart as checked out and record promo code usage
            $dbCart = $this->cartService->getOrCreateCart();

            // Record promo code usage if a promo code was applied
            $this->recordPromoCodeUsageFromCart($dbCart, $booking);

            $this->cartService->markAsCheckedOut($dbCart);

            // Reload booking with eager loaded relations for email
            $booking = $this->reloadBookingForEmail($booking);

            // Website bookings send automatically. The staff-only confirmation
            // checkbox is intentionally not used in this public checkout path.
            $this->smsAutomationService->queueBookingConfirmation($booking, true);

            // Send confirmation email to customer
            try {
                $this->sendBookingEmail($booking, new CheckoutConfirmationMail($booking));
            } catch (\Exception $e) {
                Log::error('Complete Booking: Failed to send confirmation email', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Don't fail the booking if email fails
            }

            DB::commit();

            Log::info('Checkout booking completed', [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'payment_method' => $paymentMethod,
                'payment_status' => $booking->payment_status,
                'status' => $booking->status,
            ]);

            // Prepare success message based on payment status and method
            if ($paymentProcessed) {
                $message = 'Your booking has been confirmed and payment received successfully!';
            } elseif ($paymentMethod === 'online') {
                $message = 'Your booking has been confirmed! Payment gateway is temporarily unavailable. You can pay on check-in or contact us to arrange payment.';
            } else {
                $message = 'Your booking has been received. Payment will be collected when you check-in to collect the vehicle.';
            }


            return redirect()->route('checkout.success', [
                'type' => 'payment',
                'reference' => $booking->booking_number,
                'method' => $paymentMethod,
                'status' => $booking->status,
                'initial_currency' => getBookingDisplayCurrency($booking),
            ])->with('success', $message);
        } catch (\Exception $e) {
            Log::error('Complete Booking: Process failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Failed to complete booking: ' . $e->getMessage());
        }
    }

    /**
     * Success page
     */
    public function success(Request $request)
    {
        $type = $request->get('type'); // quotation, payment
        $reference = $request->get('reference');
        $method = $request->get('method');
        $status = $request->get('status');

        if (!$reference) {
            return redirect()->route('home')->with('error', 'Invalid booking reference.');
        }

        // Fetch booking for display with eager loaded relationships (include terms & customer user)
        $booking = Booking::with([
            'customer.user',
            'bookingAddons.addon',
            'bookingItems.vehicleGroup',
            'bookingItems.serviceType',
            'bookingItems.booking',
            'acceptedTerms.terms'
        ])->where('booking_number', $reference)->first();

        if (!$booking) {
            return redirect()->route('home')->with('error', 'Booking not found.');
        }

        // The payment return carries a one-time currency seed so a lost session is
        // initialized from the booking. Do not reapply it on later page reloads:
        // the visitor may have explicitly switched currencies from the selector.
        $bookingCurrency = getBookingDisplayCurrency($booking);
        $initialCurrency = strtoupper((string) $request->query('initial_currency', ''));
        if ($initialCurrency === $bookingCurrency) {
            $this->currencyService->setSelectedCurrency($bookingCurrency);
        }

        return view('checkout.success', compact('type', 'reference', 'method', 'status', 'booking'));
    }

    /**
     * WebXPay callback handler (return from payment)
     */
    public function webxpayCallback(Request $request)
    {
        $initialTransactionLevel = DB::transactionLevel();
        try {
            // record callback received
            $this->paymentEventService->recordEvent('callback_received', [
                'payload' => $request->all(),
                'source' => 'webxpay',
                'message' => 'Callback received from WebXPay'
            ]);

            // Try to determine booking from session first
            $bookingId = session()->get('pending_booking_id');
            $booking = $bookingId ? Booking::find($bookingId) : null;

            // If booking not found in session, try to extract from custom_fields or verification
            if (!$booking) {
                // Attempt to extract from incoming custom_fields (base64 encoded)
                $customFieldsRaw = $request->input('custom_fields');
                if ($customFieldsRaw) {
                    try {
                        $decoded = base64_decode($customFieldsRaw);

                        $parts = explode('|', $decoded);
                        $possibleBookingId = $parts[0] ?? null;
                        $possibleBookingNumber = $parts[2] ?? null;

                        // record custom fields with extracted values
                        $this->paymentEventService->recordEvent('callback_custom_fields_decoded', [
                            'payload' => ['decoded' => $decoded],
                            'source' => 'webxpay',
                            'booking_id' => null, // Don't use possibleBookingId as it might not be UUID
                            'booking_number' => $possibleBookingNumber ?? null,
                        ]);

                        // Try booking_number FIRST since it's more reliable
                        // (booking_id might be non-UUID in old/test data)
                        if ($possibleBookingNumber) {
                            $booking = Booking::where('booking_number', $possibleBookingNumber)->first();
                        }

                        // Fallback to booking_id only if it looks like a UUID
                        if (!$booking && $possibleBookingId && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $possibleBookingId)) {
                            $booking = Booking::find($possibleBookingId);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse custom_fields from callback', ['error' => $e->getMessage()]);
                    }
                }
            }

            // If still not found, attempt full verification to get booking id (handles signed responses)
            $verificationResult = null;
            if (!$booking && $this->webxPayService->isEnabled()) {
                $callbackData = $request->all();
                $verificationResult = $this->webxPayService->verifyPayment($callbackData);

                // Try to find booking before recording event (prioritize booking_number)
                $verifiedBooking = null;
                if (!empty($verificationResult['booking_number'])) {
                    $verifiedBooking = Booking::where('booking_number', $verificationResult['booking_number'])->first();
                }
                // Only use booking_id if it looks like a valid UUID
                if (
                    !$verifiedBooking && !empty($verificationResult['booking_id']) &&
                    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $verificationResult['booking_id'])
                ) {
                    $verifiedBooking = Booking::find($verificationResult['booking_id']);
                }

                // Store verification result to DB for audit (use actual booking UUID if found)
                $this->paymentEventService->recordEvent('verification_result', [
                    'payload' => $verificationResult,
                    'source' => 'webxpay',
                    'booking_id' => $verifiedBooking?->id ?? null, // Use actual UUID from found booking
                    'booking_number' => $verificationResult['booking_number'] ?? null,
                    'transaction_id' => $verificationResult['transaction_id'] ?? null,
                    'status' => $verificationResult['status'] ?? null,
                ]);

                if ($verifiedBooking) {
                    $booking = $verifiedBooking;
                }
            }

            if (!$booking) {
                Log::error('WebXPay callback: Booking not found after attempts', ['request' => $request->all(), 'verification' => $verificationResult ?? null]);
                // Record unmatched callback for operations to investigate
                $this->paymentEventService->recordEvent('unmatched_callback', [
                    'payload' => $request->all(),
                    'source' => 'webxpay',
                    'message' => 'Callback received but booking could not be resolved'
                ]);

                // Show a friendly callback page so we don't lose context in redirect to cart
                return view('checkout.callback-error', ['message' => 'Booking not found. If you have been charged, contact support with your transaction details.']);
            }

            // Check if this is from mock gateway (test mode)
            if ($request->has('status') && !$this->webxPayService->isEnabled()) {
                $this->paymentEventService->recordEvent('mock_callback_processing', ['booking_id' => $booking->id, 'payload' => $request->all(), 'source' => 'webxpay']);
                DB::beginTransaction();

                if ($request->input('status') === 'success') {
                    $this->paymentEventService->recordEvent('payment_success', ['booking_id' => $booking->id, 'transaction_id' => $request->input('transaction_id'), 'payload' => $request->all(), 'source' => 'webxpay', 'status' => 'success']);
                    $tenantCompanyId = $this->paymentLedger->ensureBookingCompanyAttribution($booking);
                    $company = DB::table('companies')->where('id', $tenantCompanyId)->where('is_active', true)
                        ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                    if (! $company) {
                        $tenantCompanyId = $this->paymentLedger->ensureBookingCompanyAttribution($booking);
                        $company = DB::table('companies')->where('id', $tenantCompanyId)->where('is_active', true)
                            ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                    }
                    abort_unless($company, 409, 'No active default company is configured.');
                    // Mock payment successful
                    $wasPaid = $booking->payment_status === 'paid';
                    $booking->update([
                        'status' => config('booking.status.confirmed'),
                        'payment_gateway_transaction_id' => $request->input('transaction_id'),
                        'paid_at' => now(),
                        'confirmed_at' => now(),
                    ]);
                    $this->recordGatewayReceipt($booking, [
                        'transaction_id' => $request->input('transaction_id'),
                        'amount' => $request->input('amount'),
                        'currency' => $request->input('currency'),
                        'paid_at' => now(),
                        'source' => 'webxpay_mock',
                        'payload' => $request->except(['signature']),
                    ]);


                    // Mark cart as checked out and record promo code usage
                    $dbCart = $this->cartService->getOrCreateCart();

                    // Record promo code usage if a promo code was applied
                    if (!$wasPaid) {
                        $this->recordPromoCodeUsageFromCart($dbCart, $booking);
                    }

                    $this->cartService->markAsCheckedOut($dbCart);

                    // Send confirmation email
                    try {
                        if (!$wasPaid) {
                            // Reload booking with eager loaded relations for email
                            $bookingForEmail = $this->reloadBookingForEmail($booking);
                            $this->sendBookingEmail($bookingForEmail, new CheckoutConfirmationMail($bookingForEmail));
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to send confirmation email', [
                            'booking_id' => $booking->id,
                            'error' => $e->getMessage()
                        ]);
                    }

                    DB::commit();

                    \App\Services\PendingPaymentManager::invalidateLinksForBooking((string) $booking->id);

                    // Clear session
                    session()->forget(['pending_booking_id', 'pending_payment_amount']);

                    return redirect()->route('checkout.success', [
                        'type' => 'payment',
                        'reference' => $booking->booking_number,
                        'method' => 'online',
                        'status' => 'confirmed',
                        'initial_currency' => getBookingDisplayCurrency($booking),
                    ])->with('success', 'Payment successful! Your booking is confirmed.');
                } else {
                    $this->paymentEventService->recordEvent('payment_failed', ['booking_id' => $booking->id, 'payload' => $request->all(), 'source' => 'webxpay', 'status' => 'failed']);
                    // Mock payment failed
                    $booking->update([
                        'status' => config('booking.status.pending_payment'),
                        'payment_status' => 'failed',
                    ]);

                    DB::commit();

                    session()->forget(['pending_booking_id', 'pending_payment_amount']);

                    return redirect()->route('checkout')->with('error', 'Payment failed. Please try again.');
                }
            }

            // Real WebXPay verification (if not already performed)
            if ($verificationResult === null) {
                $callbackData = $request->all();
                $verificationResult = $this->webxPayService->verifyPayment($callbackData);

                // Store verification result for auditing
                $this->paymentEventService->recordEvent('verification_result', [
                    'payload' => $verificationResult,
                    'source' => 'webxpay',
                    'booking_id' => $verificationResult['booking_id'] ?? null,
                    'booking_number' => $verificationResult['booking_number'] ?? null,
                    'transaction_id' => $verificationResult['transaction_id'] ?? null,
                    'status' => $verificationResult['status'] ?? null,
                ]);
            }

            if (!$this->matchesBookingGatewayOrder($booking, $verificationResult)) {
                $this->paymentEventService->recordEvent('payment_callback_rejected', [
                    'booking_id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'transaction_id' => $verificationResult['transaction_id'] ?? null,
                    'payload' => [
                        'reason' => 'gateway_order_mismatch',
                        'order_id' => $verificationResult['order_id'] ?? null,
                        'verification_status' => $verificationResult['status'] ?? null,
                        'verification_success' => $verificationResult['success'] ?? false,
                    ],
                    'source' => 'webxpay',
                    'status' => 'rejected',
                ]);
                Log::warning('WebXPay callback rejected: order does not match booking', [
                    'booking_id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'order_id' => $verificationResult['order_id'] ?? null,
                ]);
                return view('checkout.callback-error', ['message' => 'We could not match this payment to your booking. If you have been charged, contact support with your transaction details.']);
            }

            // verifyPayment returns success only after the signed gateway response
            // has been validated. Do not reject an approved payment because a
            // gateway adapter labels its successful status with another string.
            if (!empty($verificationResult['success'])) {
                $this->paymentEventService->recordEvent('payment_success', ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'transaction_id' => $verificationResult['transaction_id'] ?? null, 'payload' => $verificationResult, 'source' => 'webxpay', 'status' => 'success']);

                $wasPaid = false;
                DB::beginTransaction();

                $tenantCompanyId = $this->paymentLedger->ensureBookingCompanyAttribution($booking);
                $company = DB::table('companies')->where('id', $tenantCompanyId)->where('is_active', true)
                    ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                if (! $company) {
                    $tenantCompanyId = $this->paymentLedger->ensureBookingCompanyAttribution($booking);
                    $company = DB::table('companies')->where('id', $tenantCompanyId)->where('is_active', true)
                        ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                }
                abort_unless($company, 409, 'No active default company is configured.');
                // Re-fetch with a pessimistic lock to guard against concurrent callback/notify
                $lockedBooking = Booking::where('id', $booking->id)->lockForUpdate()->first();
                $attribution = DB::table('sales_booking_attributions')->where('booking_id', $booking->id)
                    ->lockForUpdate()->first(['company_id']);
                abort_unless(\App\Services\CompanyIdScope::sameCompanyId((string) ((string) $attribution?->company_id), (string) ((string) $company->id)), 409,
                    'The booking legal entity changed before payment could be recorded.');

                if ($lockedBooking && $lockedBooking->payment_status !== 'paid') {
                    $workflowData = is_array($lockedBooking->workflow_data) ? $lockedBooking->workflow_data : [];
                    $verifiedOrderId = (string) ($verificationResult['order_id'] ?? '');
                    $gatewayPayment = collect((array) data_get($workflowData, 'gateway_payment_attempts', []))
                        ->first(fn ($attempt) => (string) ($attempt['order_id'] ?? '') === $verifiedOrderId);
                    if (!$gatewayPayment) {
                        $currentAttempt = (array) data_get($workflowData, 'gateway_payment', []);
                        $gatewayPayment = (string) ($currentAttempt['order_id'] ?? '') === $verifiedOrderId
                            ? $currentAttempt
                            : [];
                    }
                    $gatewayPayment = (array) $gatewayPayment;
                    $bookingAmount = (float) ($gatewayPayment['booking_amount'] ?? 0);
                    $gatewayOrderId = (string) ($verificationResult['order_id'] ?? $gatewayPayment['order_id'] ?? '');
                    $transactionId = (string) ($verificationResult['transaction_id'] ?? '');

                    if ($bookingAmount <= 0 || $gatewayOrderId !== $verifiedOrderId) {
                        DB::rollBack();
                        Log::critical('Verified WebXPay callback has no matching payment amount snapshot', [
                            'booking_id' => $lockedBooking->id,
                            'order_id' => $verifiedOrderId,
                            'transaction_id' => $transactionId,
                        ]);
                        $this->paymentEventService->recordEvent('payment_callback_rejected', [
                            'booking_id' => $lockedBooking->id,
                            'booking_number' => $lockedBooking->booking_number,
                            'transaction_id' => $transactionId,
                            'payload' => ['reason' => 'verified_order_amount_snapshot_missing', 'order_id' => $verifiedOrderId],
                            'source' => 'webxpay',
                            'status' => 'reconciliation_required',
                        ]);
                        return view('checkout.callback-error', ['message' => 'Your payment was approved, but we could not match it to the amount requested. Please contact support with your transaction details so we can confirm your booking.']);
                    }

                    $this->recordVerifiedWebXPayReceipt($lockedBooking, $verificationResult);

                    $lockedBooking->update([
                        'status' => config('booking.status.confirmed'),
                        'payment_gateway_transaction_id' => $verificationResult['transaction_id'] ?? null,
                        'paid_at' => $verificationResult['paid_at'] ?? now(),
                        'confirmed_at' => now(),
                    ]);
                    $booking = $lockedBooking;


                    // Mark cart as checked out and record promo code usage
                    $dbCart = $this->cartService->getOrCreateCart();
                    $this->recordPromoCodeUsageFromCart($dbCart, $booking);
                    $this->cartService->markAsCheckedOut($dbCart);

                    $wasPaid = false; // just set to paid — send the email
                } else {
                    // Already paid by concurrent webhook — still commit (nothing to roll back)
                    $wasPaid = true;
                }

                // Send confirmation email
                try {
                    if (!$wasPaid) {
                        // Reload booking with eager loaded relations for email
                        $bookingForEmail = $this->reloadBookingForEmail($booking);
                        $this->sendBookingEmail($bookingForEmail, new CheckoutConfirmationMail($bookingForEmail));
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to send confirmation email', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage()
                    ]);
                }

                DB::commit();

                \App\Services\PendingPaymentManager::invalidateLinksForBooking((string) $booking->id);

                // Clear session
                session()->forget('pending_booking_id');

                return redirect()->route('checkout.success', [
                    'type' => 'payment',
                    'reference' => $booking->booking_number,
                    'method' => 'online',
                    'status' => 'confirmed',
                    'initial_currency' => getBookingDisplayCurrency($booking),
                ])->with('success', 'Payment successful! Your booking is confirmed.');
            } else {
                Log::warning('WebXPay: payment verification failed', ['verification' => $verificationResult, 'booking_id' => $booking->id]);
                $this->paymentEventService->recordEvent('payment_failed', ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'payload' => $verificationResult, 'source' => 'webxpay', 'status' => 'failed']);
                // A late failure callback must never downgrade a booking that another callback paid.
                DB::transaction(function () use ($booking): void {
                    $lockedBooking = Booking::where('id', $booking->id)->lockForUpdate()->first();
                    if ($lockedBooking && $lockedBooking->payment_status !== 'paid') {
                        $lockedBooking->update([
                            'status' => config('booking.status.pending_payment'),
                            'payment_status' => 'failed',
                        ]);
                    }
                });

                return redirect()->route('checkout')->with('error', 'Payment verification failed. Please try again.');
            }
        } catch (\Exception $e) {
            while (DB::transactionLevel() > $initialTransactionLevel) {
                DB::rollBack();
            }
            Log::error('WebXPay callback error', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return redirect()->route('checkout')->with('error', 'Payment processing error. Please contact support.');
        }
    }

    /**
     * WebXPay notification handler (server-to-server webhook).
     * Uses a pessimistic lock to ensure only one concurrent delivery marks the booking paid.
     */
    public function webxpayNotify(Request $request)
    {
        try {
            $callbackData = $request->all();


            $verificationResult = $this->webxPayService->verifyPayment($callbackData);

            if ($verificationResult['success']) {
                $orderId = $verificationResult['order_id'];

                // Extract booking number from the signed order ID (format: BK12345678-timestamp)
                $bookingNumber = explode('-', $orderId)[0] ?? null;

                if ($bookingNumber && in_array($verificationResult['status'] ?? null, ['completed', 'success'], true)) {
                    $matchedBooking = Booking::where('booking_number', $bookingNumber)->first();
                    if (!$matchedBooking || !$this->matchesBookingGatewayOrder($matchedBooking, $verificationResult)
                        || (!empty($verificationResult['booking_number']) && $verificationResult['booking_number'] !== $matchedBooking->booking_number)) {
                        Log::warning('WebXPay notify rejected: order does not match booking', [
                            'booking_number' => $bookingNumber,
                            'order_id' => $orderId,
                        ]);
                        return response('Payment order mismatch', 422);
                    }
                    $emailBooking = null;
                    $tenantCompanyId = $this->paymentLedger->ensureBookingCompanyAttribution($matchedBooking);

                    DB::transaction(function () use ($bookingNumber, $verificationResult, $matchedBooking, $tenantCompanyId, &$emailBooking) {
                        $company = DB::table('companies')->where('id', $tenantCompanyId)->where('is_active', true)
                            ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                        if (! $company) {
                            $tenantCompanyId = $this->paymentLedger->ensureBookingCompanyAttribution($matchedBooking);
                            $company = DB::table('companies')->where('id', $tenantCompanyId)->where('is_active', true)
                                ->whereNull('deleted_at')->lockForUpdate()->first(['id']);
                        }
                        abort_unless($company, 409, 'No active default company is configured.');
                        // Lock the row — prevents duplicate delivery race conditions
                        $booking = Booking::where('booking_number', $bookingNumber)
                            ->lockForUpdate()
                            ->first();

                        if (!$booking) return;
                        $attribution = DB::table('sales_booking_attributions')->where('booking_id', $booking->id)
                            ->lockForUpdate()->first(['company_id']);
                        abort_unless(\App\Services\CompanyIdScope::sameCompanyId((string) ((string) $attribution?->company_id), (string) ((string) $company->id)), 409,
                            'The booking legal entity changed before payment could be recorded.');

                        // Idempotency: if already paid, nothing to do
                        if ($booking->payment_status === 'paid') return;

                        $this->recordVerifiedWebXPayReceipt($booking, $verificationResult);

                        $booking->update([
                            'status'                           => config('booking.status.confirmed'),
                            'payment_gateway_transaction_id'   => $verificationResult['transaction_id'],
                            'paid_at'                          => $verificationResult['paid_at'] ?? now(),
                            'confirmed_at'                     => now(),
                        ]);
                        $emailBooking = $booking;
                    });

                    $paidBookingId = Booking::where('booking_number', $bookingNumber)->value('id');
                    if ($paidBookingId) {
                        \App\Services\PendingPaymentManager::invalidateLinksForBooking((string) $paidBookingId);
                    }

                    if ($emailBooking) {
                        try {
                            $bookingForEmail = $this->reloadBookingForEmail($emailBooking);
                            $this->sendBookingEmail($bookingForEmail, new CheckoutConfirmationMail($bookingForEmail));
                        } catch (\Exception $e) {
                            Log::error('WebXPay notify: failed to send confirmation email', [
                                'booking_id' => $emailBooking->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    return response()->json(['status' => 'success']);
                }
            }

            return response()->json(['status' => 'error', 'message' => 'Verification failed'], 400);
        } catch (\Exception $e) {
            Log::error('WebXPay notification error', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json(['status' => 'error', 'message' => 'Processing error'], 500);
        }
    }

    /**
     * Mock payment gateway (for testing when WebXPay is disabled)
     */
    public function mockGateway()
    {
        $bookingId = session()->get('pending_booking_id');
        $amount = session()->get('pending_payment_amount');

        if (!$bookingId) {
            return redirect()->route('home')->with('error', 'No pending booking found.');
        }

        $booking = Booking::find($bookingId);

        if (!$booking) {
            return redirect()->route('home')->with('error', 'Booking not found.');
        }

        $orderId = $booking->booking_number . '-' . time();

        return view('checkout.mock-gateway', compact('booking', 'amount', 'orderId'));
    }

    /**
     * WebXPay redirect page - displays form that auto-submits to WebXPay (RSA Method)
     */
    public function webxpayRedirect(Request $request)
    {
        // Get payment data from session
        $paymentData = session()->get('webxpay_payment_data');

        if (!$paymentData) {
            Log::error('WebXPay redirect attempted without payment data in session');
            return redirect()->route('checkout')
                ->with('error', 'Payment session expired. Please try again.');
        }

        // Pass RSA encrypted data to view for form submission
        return view('checkout.webxpay-redirect', [
            'payment_url' => $paymentData['payment_url'],
            'order_id' => $paymentData['order_id'],
            'encrypted_payment' => $paymentData['encrypted_payment'],
            'secret_key' => $paymentData['secret_key'],
            'custom_fields' => $paymentData['custom_fields'],
            'enc_method' => $paymentData['enc_method'],
            'customer_data' => $paymentData['customer_data'],
            'return_url' => $paymentData['return_url'] ?? null,
            'cancel_url' => $paymentData['cancel_url'] ?? null,
            'notify_url' => $paymentData['notify_url'] ?? null,
        ]);
    }

    /**
     * WebXPay cancel handler
     */
    public function webxpayCancel(Request $request)
    {
        $bookingId = session()->get('pending_booking_id');

        if ($bookingId) {
            $booking = Booking::find($bookingId);
            if ($booking) {
                $booking->update([
                    'status' => config('booking.status.draft'),
                    'payment_status' => 'cancelled',
                ]);
            }
        }

        return redirect()->route('checkout')
            ->with('error', 'Payment was cancelled. Please try again or choose a different payment method.');
    }

    /**
     * Resolve the customer email address for booking notifications.
     */
    protected function getBookingCustomerEmail(Booking $booking): ?string
    {
        $contact = $booking->workflow_data['checkout_contact'] ?? null;
        if (is_array($contact)) {
            return filled($contact['email'] ?? null) ? (string) $contact['email'] : null;
        }

        return $booking->customer?->user?->email;
    }

    /**
     * Send a booking-related email to the customer with required CC/BCC rules.
     */
    protected function sendBookingEmail(Booking $booking, \Illuminate\Mail\Mailable $mailable): void
    {
        $email = $this->getBookingCustomerEmail($booking);
        if (!$email) {
            Log::warning('Skipping booking email send; missing recipient.', [
                'booking_id' => $booking->id,
                'mailable' => $mailable::class,
            ]);
            return;
        }

        $this->mailDispatchService->sendToCustomer($email, $mailable);
    }

    /**
     * Notify customer and internal addresses when payment is initiated.
     */
    protected function sendPaymentInitiatedEmail(Booking $booking, float $amount): void
    {
        try {
            // Reload booking with eager loaded relations for email
            $booking = $this->reloadBookingForEmail($booking);
            $this->sendBookingEmail($booking, new PaymentInitiatedMail($booking, $amount));
        } catch (\Exception $e) {
            Log::error('Payment Initiated Email: Failed to send', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Record promo code usage from cart when booking is completed.
     * 
     * Creates a PromoCodeUsage record and increments the usage count
     * if a promo code was applied to the cart.
     *
     * @param \App\Models\Cart $cart The cart with potential promo code
     * @param Booking $booking The completed booking
     * @return void
     */
    protected function recordPromoCodeUsageFromCart(\App\Models\Cart $cart, Booking $booking): void
    {
        // Check if a promo code was applied to the cart
        if (empty($cart->coupon_code)) {
            return;
        }

        try {
            // Get the promo code
            $promoCode = $this->promoCodeService->getByCode($cart->coupon_code);

            if (!$promoCode) {
                Log::warning('Promo code not found when recording usage', [
                    'coupon_code' => $cart->coupon_code,
                    'booking_id' => $booking->id,
                ]);
                return;
            }

            // Get order amount from cart totals (subtotal before discount)
            $totals = $cart->totals ?? [];
            $orderAmount = (float) ($totals['subtotal'] ?? 0);
            $discountAmount = (float) ($cart->coupon_discount ?? 0);

            // Get customer ID from booking
            $customerId = $booking->customer_id;

            // Record the usage
            $this->promoCodeService->recordUsage(
                $promoCode,
                $customerId,
                $booking->id,
                $discountAmount,
                $orderAmount
            );

        } catch (\Exception $e) {
            // Log error but don't fail the booking
            Log::error('Failed to record promo code usage', [
                'coupon_code' => $cart->coupon_code,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve payment-related settings with defaults.
     */
    protected function resolvePaymentSettings(): array
    {
        $settings = $this->websiteSettingsService->getPaymentSettings();

        return [
            'webxpay_enabled' => $this->normalizeBoolean(
                $settings['webxpay_enabled'] ?? null,
                (bool) config('booking.webxpay.enabled', false)
            ),
            'payment_online_enabled' => $this->normalizeBoolean(
                $settings['payment_online_enabled'] ?? null,
                (bool) config('booking.payment_methods.online.enabled', true)
            ),
            'payment_offline_enabled' => $this->normalizeBoolean(
                $settings['payment_offline_enabled'] ?? null,
                (bool) config('booking.payment_methods.offline.enabled', true)
            ),
            'advance_payment_enabled' => $this->normalizeBoolean(
                $settings['advance_payment_enabled'] ?? null,
                (bool) config('booking.advance_payment.enabled', true)
            ),
            'advance_payment_percentage' => $this->normalizeDisplayPercentage(
                $settings['advance_payment_percentage'] ?? config('booking.advance_payment.percentage', 50),
                50
            ),
            'advance_payment_min_amount' => $this->normalizeAmount(
                $settings['advance_payment_min_amount'] ?? config('booking.advance_payment.min_amount', 0),
                0
            ),
        ];
    }

    /**
     * Return payment methods filtered by current settings.
     */
    protected function getAvailablePaymentMethods(bool $webxpayEnabled, bool $onlineEnabled, bool $offlineEnabled)
    {
        $methodOverrides = [
            'online' => $onlineEnabled,
            'offline' => $offlineEnabled,
        ];

        return collect(config('booking.payment_methods', []))
            ->filter(function ($method, $key) use ($webxpayEnabled, $methodOverrides) {
                $enabled = $methodOverrides[$key] ?? (bool) ($method['enabled'] ?? false);

                if ($key === 'online') {
                    return $enabled && $webxpayEnabled;
                }

                return $enabled;
            });
    }

    /**
     * Normalize a boolean value coming from settings.
     */
    protected function normalizeBoolean($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * Normalize percentage values to 0-100 for display and calculations.
     */
    protected function normalizeDisplayPercentage($value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = is_string($value) ? str_replace('%', '', $value) : $value;
        $amount = (float) $normalized;
        if ($amount > 0 && $amount <= 1) {
            $amount *= 100;
        }

        if ($amount < 0) {
            $amount = 0;
        }
        if ($amount > 100) {
            $amount = 100;
        }

        return $amount;
    }

    protected function normalizeAmount($value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) $value;
    }

    protected function resolveBookingBaseCurrency(array $bookingSettings): string
    {
        $currency = $bookingSettings['booking_base_currency'] ?? null;
        $currency = strtoupper(trim((string) $currency));
        if ($currency !== '' && $this->currencyService->isValidCurrency($currency)) {
            return $currency;
        }

        return $this->currencyService->getBookingBaseCurrency();
    }

    /**
     * Resume payment for a pending booking from email link
     * Uses PendingPaymentManager to retrieve full booking context
     * Allows customers to complete payment for bookings with pending payment status
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function resumePayment(Request $request)
    {
        try {
            // Prefer route parameter token for path-based links (e.g., /payment-resume/{token})
            $token = $request->route('token') ?? $request->get('token');


            if (!$token) {
                Log::warning('CheckoutController: resumePayment called without token', [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                ]);

                return redirect()->route('home')->with('error', 'Invalid payment link.');
            }

            // Retrieve payment link with full booking context using PendingPaymentManager
            $paymentLinkData = \App\Services\PendingPaymentManager::retrievePaymentLink($token);
            if (!$paymentLinkData) {
                Log::warning('CheckoutController: retrievePaymentLink returned null', [
                    'token' => $token,
                    'url' => $request->fullUrl(),
                ]);

                return redirect()->route('home')->with('error', 'Payment link expired or invalid.');
            }

            $booking = $paymentLinkData['booking'];
            if (!$booking) {
                return redirect()->route('home')->with('error', 'Booking not found.');
            }

            // Extract necessary data
            $context = $paymentLinkData['context'];
            $amountDue = $paymentLinkData['amount_due'];

            // Log the payment resume attempt
            logger('Payment resume accessed', [
                'token' => $token,
                'booking_id' => $booking->id,
                'customer_email' => $context['customer']['email'] ?? 'unknown',
                'amount_due' => $amountDue,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
            ]);

            // Show dedicated payment resume page with all booking details
            return view('checkout.payment-resume', [
                'paymentData' => $paymentLinkData,
                'booking' => $booking,
                'context' => $context,
                'amountDue' => $amountDue,
                'token' => $token,
            ]);

        } catch (\Exception $e) {
            Log::error('Error resuming payment from email link', [
                'error' => $e->getMessage(),
                'token' => $request->get('token'),
                'exception' => $e
            ]);

            return redirect()->route('home')->with('error', 'Unable to load payment details. Please contact support for assistance.');
        }
    }

    /** Save a customer's vehicle selection as a revision while retaining the original quote snapshot. */
    public function updatePaymentResumeVehicles(Request $request, string $token)
    {
        $link = \App\Models\Website\PendingPaymentLink::where('token', $token)
            ->where('expires_at', '>', now())->whereNull('invalidated_at')->first();
        if (!$link) {
            return redirect()->route('home')->with('error', 'This payment link has expired or is invalid.');
        }
        $linkedBooking = $link->booking;
        if (!$linkedBooking || $linkedBooking->payment_status === 'paid'
            || $linkedBooking->status === config('booking.status.payment_processing')) {
            return back()->with('error', 'This booking can no longer be changed through this payment link.');
        }

        $context = $link->booking_context ?? [];
        $items = $context['booking_items'] ?? [];
        $validIds = collect($items)->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();
        $selected = collect($request->input('item_ids', []))->map(fn ($id) => (string) $id)->unique()->values()->all();
        if (!$selected || array_diff($selected, $validIds)) {
            return back()->with('error', 'Keep at least one quoted vehicle in your booking.');
        }

        // BookingFlowService stores each vehicle's final calculated price in total_price.
        // Summing those saved prices avoids accepting or inventing client-side prices.
        $allLineTotal = collect($items)->sum(fn ($item) => (float) ($item['total_price'] ?? 0));
        $selectedLineTotal = collect($items)->filter(fn ($item) => in_array((string) ($item['id'] ?? ''), $selected, true))
            ->sum(fn ($item) => (float) ($item['total_price'] ?? 0));
        if ($allLineTotal <= 0 || $selectedLineTotal <= 0) {
            return back()->with('error', 'We could not recalculate this quotation. Please contact our team.');
        }

        $history = $link->revision_history ?? [];
        $history[] = [
            'updated_at' => now()->toIso8601String(),
            'item_ids' => $selected,
            'total' => round($selectedLineTotal, 2),
            'amount_due' => max(0, round($selectedLineTotal - (float) ($context['pricing']['amount_paid'] ?? 0), 2)),
            'booking_totals_before_edit' => [
                'base_amount' => (float) ($linkedBooking->base_amount ?? 0),
                'addons_cost' => (float) ($linkedBooking->addons_cost ?? 0),
                'discount_amount' => (float) ($linkedBooking->discount_amount ?? 0),
                'tax_amount' => (float) ($linkedBooking->tax_amount ?? 0),
                'total_estimated' => (float) ($linkedBooking->total_estimated ?? 0),
                'amount_to_pay' => (float) ($linkedBooking->amount_to_pay ?? 0),
                'pricing_snapshot' => $linkedBooking->pricing_snapshot ?? [],
            ],
        ];
        $isOriginalSelection = count($selected) === count($validIds);
        $link->revision_item_ids = $selected;
        $link->revision_total = $isOriginalSelection
            ? (float) ($context['pricing']['total_estimated'] ?? $selectedLineTotal)
            : round($selectedLineTotal, 2);
        $link->revision_amount_due = $isOriginalSelection
            ? (float) $link->amount_due
            : max(0, round((float) $link->revision_total - (float) ($context['pricing']['amount_paid'] ?? 0), 2));
        $link->revision_history = $history;
        $link->save();
        return redirect()->route('checkout.payment-resume', ['token' => $token])->with('success', $isOriginalSelection
            ? 'Your original vehicle selection is restored.'
            : 'Your revised vehicle selection has been saved.');
    }

    /**
     * Process payment from the dedicated payment resume page
     */
    public function processPaymentResume(Request $request)
    {
        try {
            $token = $request->input('payment_token');
            $bookingId = $request->input('booking_id');
            $paymentMethod = $request->input('payment_method', 'webxpay');

            // Validate the payment link
            $paymentLinkData = \App\Services\PendingPaymentManager::retrievePaymentLink($token);
            if (!$paymentLinkData) {
                return back()->with('error', 'Payment session expired. Please try again.');
            }

            $booking = $paymentLinkData['booking'];
            $amount = (float) $paymentLinkData['amount_due'];
            if ($amount <= 0) {
                return back()->with('success', 'No payment is due for this booking.');
            }

            // Validate booking ID matches
            if ($booking->id !== $bookingId) {
                return back()->with('error', 'Invalid payment request.');
            }

            // Apply the saved selection only when the customer commits to payment.
            $link = \App\Models\Website\PendingPaymentLink::where('token', $token)->firstOrFail();
            if ($link->revision_item_ids) {
                $allIds = collect($paymentLinkData['context']['booking_items'] ?? [])->pluck('id')->filter()->all();
                $keepIds = array_values(array_intersect($allIds, $link->revision_item_ids));
                if (!$keepIds) {
                    return back()->with('error', 'Keep at least one vehicle before continuing to payment.');
                }
                \Illuminate\Support\Facades\DB::transaction(function () use ($booking, $keepIds, $allIds, $amount, $link, $paymentLinkData) {
                    $booking->bookingItems()->withTrashed()->whereIn('id', $keepIds)->restore();
                    $booking->bookingItems()->whereNotIn('id', $keepIds)->delete();
                    $isOriginalSelection = count($keepIds) === count($allIds);
                    if ($isOriginalSelection) {
                        $pricing = $paymentLinkData['context']['pricing'] ?? [];
                        $originalTotals = data_get($link->revision_history, '0.booking_totals_before_edit', []);
                        $booking->update([
                            'base_amount' => (float) ($originalTotals['base_amount'] ?? $pricing['base_amount'] ?? $booking->base_amount),
                            'addons_cost' => (float) ($originalTotals['addons_cost'] ?? $pricing['addon_charges'] ?? $booking->addons_cost),
                            'discount_amount' => (float) ($originalTotals['discount_amount'] ?? $pricing['discount_amount'] ?? $booking->discount_amount),
                            'tax_amount' => (float) ($originalTotals['tax_amount'] ?? $pricing['tax_amount'] ?? $booking->tax_amount),
                            'total_estimated' => (float) ($originalTotals['total_estimated'] ?? $pricing['total_estimated'] ?? $link->revision_total),
                            'amount_to_pay' => $amount,
                            'pricing_snapshot' => $originalTotals['pricing_snapshot'] ?? $paymentLinkData['context']['pricing_snapshot'] ?? $booking->pricing_snapshot,
                        ]);
                        return;
                    }
                    $items = $booking->bookingItems()->whereIn('id', $keepIds)->get();
                    $pricingBreakdowns = $items->map(fn ($item) => is_array($item->pricing_breakdown) ? $item->pricing_breakdown : []);
                    $total = round((float) $items->sum('total_price'), 2);
                    if (abs($total - (float) ($link->revision_total ?? 0)) > 0.02) {
                        throw new \RuntimeException('Saved vehicle prices no longer match this quotation revision.');
                    }
                    $booking->update([
                        'base_amount' => round((float) $items->sum('unit_price'), 2),
                        'addons_cost' => round((float) $pricingBreakdowns->sum(fn ($p) => (float) data_get($p, 'addons_pricing.addons_total', 0)), 2),
                        'discount_amount' => round((float) $pricingBreakdowns->sum(fn ($p) => (float) data_get($p, 'discount_summary.total_discount_amount', 0)), 2),
                        'tax_amount' => round((float) $pricingBreakdowns->sum(fn ($p) => (float) (data_get($p, 'tax_amount') ?? data_get($p, 'taxes.total') ?? 0)), 2),
                        'total_estimated' => $total,
                        'amount_to_pay' => $amount,
                        'pricing_snapshot' => [
                            'items_count' => $items->count(),
                            'total_base' => round((float) $items->sum('unit_price'), 2),
                            'total_addons' => round((float) $pricingBreakdowns->sum(fn ($p) => (float) data_get($p, 'addons_pricing.addons_total', 0)), 2),
                            'total_discount' => round((float) $pricingBreakdowns->sum(fn ($p) => (float) data_get($p, 'discount_summary.total_discount_amount', 0)), 2),
                            'total' => $total,
                            'revision_source' => 'payment_resume',
                            'original_quote_total' => (float) data_get($link->booking_context, 'pricing.total_estimated', $link->amount_due),
                            'removed_item_ids' => array_values(array_diff($allIds, $keepIds)),
                            'revision_history' => $link->revision_history ?? [],
                        ],
                    ]);
                });
            }

            // Store necessary data in session for payment processing
            session([
                'resume_booking_id' => $booking->id,
                'resume_payment_amount' => $amount,
                'resume_booking_context' => $paymentLinkData['context'],
                'payment_link_token' => $token,
            ]);

            // Redirect to appropriate payment method
            if ($paymentMethod === 'webxpay') {
                return $this->processWebXPayPayment($booking, $amount);
            }

            // Default to regular checkout flow if other payment methods
            return redirect()->route('checkout.process');

        } catch (\Exception $e) {
            Log::error('Error processing payment resume', [
                'error' => $e->getMessage(),
                'token' => $request->input('payment_token'),
                'booking_id' => $request->input('booking_id'),
                'exception' => $e
            ]);

            return back()->with('error', 'An error occurred processing your payment. Please try again.');
        }
    }

    /**
     * Process WebXPay payment for resume flow
     */
    private function processWebXPayPayment($booking, $amount)
    {
        try {
            if (!$this->webxPayService->isEnabled()) {
                Log::warning('WebXPay is not enabled; cannot process resume payment', ['booking_id' => $booking->id]);
                return back()->with('error', 'Online payments are not available.');
            }

            $paymentType = $booking->payment_type === 'advance' ? 'advance' : 'full';
            $result = $this->webxPayService->createPayment($booking, $amount, $paymentType);

            if (!($result['success'] ?? false)) {
                Log::error('WebXPay resume payment creation failed', ['booking_id' => $booking->id, 'error' => $result['message'] ?? 'Unknown']);
                return back()->with('error', 'Unable to initiate payment. Please try again.');
            }

            $this->storeGatewayPaymentSnapshot($booking, $result);

            // Update booking to payment_processing
            $booking->update(['status' => config('booking.status.payment_processing'), 'payment_gateway_order_id' => $result['order_id'] ?? null]);

            // Store booking ID and RSA encrypted payment data in session (same as regular flow)
            session()->put('pending_booking_id', $booking->id);
            session()->put('webxpay_payment_data', [
                'payment_url' => $result['payment_url'],
                'order_id' => $result['order_id'],
                'encrypted_payment' => $result['encrypted_payment'],
                'secret_key' => $result['secret_key'],
                'custom_fields' => $result['custom_fields'],
                'enc_method' => $result['enc_method'],
                'customer_data' => $result['customer_data'],
                'return_url' => $result['return_url'] ?? null,
                'cancel_url' => $result['cancel_url'] ?? null,
                'notify_url' => $result['notify_url'] ?? null,
            ]);

            // Send payment initiated email
            $this->sendPaymentInitiatedEmail($booking, $amount);

            // Redirect to our RSA auto-submit page if required
            if (isset($result['method']) && $result['method'] === 'rsa_redirect') {
                return redirect()->route('checkout.webxpay.redirect');
            }

            // Otherwise redirect directly to the payment URL
            return redirect($result['payment_url']);

        } catch (\Exception $e) {
            Log::error('Error initiating WebXPay payment for resume', [
                'booking_id' => $booking->id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);

            return back()->with('error', 'Unable to initiate payment. Please try again.');
        }
    }

    /** Persist both the checkout amount and the amount/currency sent to WebXPay. */
    private function storeGatewayPaymentSnapshot(Booking $booking, array $gatewayResult): void
    {
        $workflowData = is_array($booking->workflow_data) ? $booking->workflow_data : [];
        $snapshot = [
            'amount' => (float) ($gatewayResult['amount'] ?? 0),
            'currency' => strtoupper((string) ($gatewayResult['currency'] ?? '')),
            'booking_amount' => (float) ($gatewayResult['booking_amount'] ?? $booking->amount_to_pay ?? 0),
            'booking_currency' => strtoupper((string) ($gatewayResult['booking_currency'] ?? $booking->currency ?? $this->currencyService->getDefaultCurrency())),
            'order_id' => $gatewayResult['order_id'] ?? null,
            'fx_rate_to_lkr' => $gatewayResult['fx_rate_to_lkr'] ?? null,
            'gateway_fx_rate_to_lkr' => $gatewayResult['gateway_fx_rate_to_lkr'] ?? null,
            'fx_rate_at' => $gatewayResult['fx_rate_at'] ?? null,
        ];
        $workflowData['gateway_payment'] = $snapshot;
        $attempts = (array) ($workflowData['gateway_payment_attempts'] ?? []);
        $attempts = array_values(array_filter($attempts, fn ($attempt) => (string) ($attempt['order_id'] ?? '') !== (string) $snapshot['order_id']));
        $attempts[] = $snapshot;
        $workflowData['gateway_payment_attempts'] = array_slice($attempts, -20);
        $booking->workflow_data = $workflowData;
        $booking->save();
    }

    /** Record the booking-currency amount and retain the exact gateway charge evidence. */
    private function recordVerifiedWebXPayReceipt(Booking $booking, array $verification): void
    {
        $workflowData = is_array($booking->workflow_data) ? $booking->workflow_data : [];
        $orderId = (string) ($verification['order_id'] ?? '');
        $snapshot = collect((array) data_get($workflowData, 'gateway_payment_attempts', []))
            ->first(fn ($attempt) => (string) ($attempt['order_id'] ?? '') === $orderId);
        if (!$snapshot) {
            $current = (array) data_get($workflowData, 'gateway_payment', []);
            $snapshot = (string) ($current['order_id'] ?? '') === $orderId ? $current : null;
        }

        $snapshot = (array) $snapshot;
        $bookingAmount = (float) ($snapshot['booking_amount'] ?? 0);
        if ($orderId === '' || $bookingAmount <= 0 || (string) ($snapshot['order_id'] ?? '') !== $orderId) {
            throw new \RuntimeException('Verified WebXPay order has no matching saved amount snapshot.');
        }

        $transactionId = (string) ($verification['transaction_id'] ?? '');
        $gatewayCurrency = strtoupper((string) ($snapshot['currency'] ?? ''));
        $bookingCurrency = strtoupper((string) ($snapshot['booking_currency'] ?? $booking->currency ?? $this->currencyService->getDefaultCurrency()));
        app(\App\Services\BookingPaymentLedgerService::class)->receive($booking, [
            'amount' => $bookingAmount,
            ...$this->gatewayReceiptCurrencyFacts($snapshot),
            'payment_method' => 'online',
            'payment_stage' => ($verification['payment_type'] ?? $booking->payment_type) === 'advance' ? 'advance' : 'full',
            'payment_purpose' => 'booking_payment',
            'reference' => $transactionId !== '' ? $transactionId : $orderId,
            'idempotency_key' => 'webxpay:' . ($transactionId !== '' ? $transactionId : $orderId),
            'received_at' => $verification['paid_at'] ?? $verification['transaction_date'] ?? now(),
            'received_via' => 'company',
            'notes' => 'Verified WebXPay payment.',
            'metadata' => [
                'gateway' => 'webxpay',
                'gateway_order_id' => $orderId,
                'gateway_transaction_id' => $transactionId !== '' ? $transactionId : null,
                'gateway_amount' => (float) ($snapshot['amount'] ?? 0),
                'gateway_currency' => $gatewayCurrency,
                'gateway_fx_rate_to_lkr' => $snapshot['gateway_fx_rate_to_lkr'] ?? ($gatewayCurrency === 'LKR' ? 1.0 : null),
                'booking_amount' => $bookingAmount,
                'booking_currency' => $bookingCurrency,
                'verification_status' => $verification['status'] ?? null,
            ],
        ], null);
    }

    private function gatewayReceiptCurrencyFacts(array $snapshot): array
    {
        $bookingAmount = (float) ($snapshot['booking_amount'] ?? 0);
        $gatewayAmount = (float) ($snapshot['amount'] ?? 0);
        $bookingCurrency = strtoupper(trim((string) ($snapshot['booking_currency'] ?? '')));
        $gatewayCurrency = strtoupper(trim((string) ($snapshot['currency'] ?? '')));
        if (!is_finite($bookingAmount) || $bookingAmount <= 0 || !is_finite($gatewayAmount) || $gatewayAmount <= 0
            || !preg_match('/^[A-Z]{3}$/', $bookingCurrency) || !preg_match('/^[A-Z]{3}$/', $gatewayCurrency)) {
            throw new \RuntimeException('The saved WebXPay charge has invalid currency or amount evidence.');
        }

        // Recover older LKR charge snapshots using the exact amount sent to the gateway.
        $rate = $bookingCurrency === 'LKR' ? 1.0 : ($gatewayCurrency === 'LKR'
            ? $gatewayAmount / $bookingAmount
            : (float) ($snapshot['fx_rate_to_lkr'] ?? 0));
        if (!is_finite($rate) || $rate <= 0) {
            throw new \RuntimeException('The saved WebXPay charge has no approved LKR conversion rate.');
        }

        return [
            'source_amount' => $bookingAmount,
            'source_currency' => $bookingCurrency,
            'fx_rate_to_lkr' => $rate,
            'fx_rate_at' => $snapshot['fx_rate_at'] ?? null,
            'fx_source' => $gatewayCurrency === 'LKR' ? 'webxpay_charge_snapshot' : 'configured_currency_rate_at_payment',
        ];
    }

    /** Bind a verified WebXPay response to the booking, including legacy rows without an order snapshot. */
    private function matchesBookingGatewayOrder(Booking $booking, array $verification): bool
    {
        $expectedOrderId = (string) ($booking->payment_gateway_order_id ?? '');
        $receivedOrderId = (string) ($verification['order_id'] ?? '');
        $verifiedBookingNumber = (string) ($verification['booking_number'] ?? '');
        $bookingNumber = (string) ($booking->booking_number ?? '');

        if ($receivedOrderId === '' || $bookingNumber === '' || ($verifiedBookingNumber !== '' && !hash_equals($bookingNumber, $verifiedBookingNumber))) {
            return false;
        }

        if ($expectedOrderId === '') {
            $workflowData = is_array($booking->workflow_data) ? $booking->workflow_data : [];
            $expectedOrderId = (string) data_get($workflowData, 'gateway_payment.order_id', '');
        }

        if ($expectedOrderId !== '' && hash_equals($expectedOrderId, $receivedOrderId)) {
            return true;
        }

        // A booking can have several payment attempts, while the booking stores only the latest order ID.
        // WebXPay signs the response containing this order ID; bind its known format to this booking.
        return preg_match('/^' . preg_quote($bookingNumber, '/') . '-\d{10,}$/D', $receivedOrderId) === 1;
    }

    /**
     * Convert quotation booking to actual booking
     * Called when customer accepts quotation and clicks checkout link from email
     * Pre-fills the checkout form with quotation details
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function convertQuotationToBooking(Request $request)
    {
        try {
            $token = (string) $request->route('token', '');
            if ($token === '') {
                return redirect()->route('home')->with('error', 'Invalid quotation link.');
            }

            $quote = PendingPaymentLink::query()->where('token', $token)->where('type', 'quotation_conversion')->first();
            if ($quote) {
                if (! $quote->isValid()) {
                    return redirect()->route('home')->with('error', 'This quotation has expired or is no longer valid. Please contact our team.');
                }
                $booking = Booking::query()->with(['customer.user', 'bookingItems.serviceType'])->find($quote->booking_id);
                $quotation = data_get($quote->booking_context, 'quotation', []);
                if (! $booking || (string) $booking->status !== (string) config('booking.status.quotation_requested')) {
                    return redirect()->route('home')->with('error', 'This quotation is no longer awaiting acceptance. Please contact our team.');
                }
                $snapshotHash = (string) data_get($quotation, 'snapshot_hash', '');
                if ($snapshotHash === '' || ! hash_equals($snapshotHash, BookingLinkHelper::quotationSnapshotHash($booking))) {
                    return redirect()->route('home')->with('error', 'This quotation has changed. Please contact our team for an updated offer.');
                }
                if (! is_numeric($booking->total_estimated) || (float) $booking->total_estimated <= 0) {
                    return redirect()->route('home')->with('error', 'This quotation has no valid saved total. Please contact our team to review it.');
                }
                $terms = is_array($quotation['terms'] ?? null)
                    ? $quotation['terms']
                    : \App\Services\PendingPaymentManager::quotationTermsSnapshot($booking);
                $termsHash = (string) ($quotation['terms_hash'] ?? hash('sha256', \App\Support\Foundation\CanonicalJson::encode($terms)));
                if (! hash_equals($termsHash, hash('sha256', \App\Support\Foundation\CanonicalJson::encode($terms)))) {
                    return redirect()->route('home')->with('error', 'The quotation terms could not be verified. Please contact our team.');
                }

                $quotation['display_snapshot'] ??= $quotation['snapshot'] ?? [];
                return $this->quotationAcceptanceView($booking, $token, $quotation, $terms, $termsHash,
                    $quote->expires_at, (int) ($quote->revision_number ?: 1));
            }

            $quotationData = BookingLinkHelper::decryptBookingData($token);

            if (!$quotationData || ($quotationData['type'] ?? null) !== 'quotation_conversion') {
                return redirect()->route('home')->with('error', 'Invalid quotation link.');
            }

            $quotationBooking = Booking::find($quotationData['booking_id'] ?? null);

            if (!$quotationBooking) {
                return redirect()->route('home')->with('error', 'Quotation not found.');
            }

            if (empty($quotationData['snapshot_hash'])) {
                return redirect()->route('home')->with('error', 'This older quotation cannot be verified. Please contact our team for an updated offer.');
            }

            if (empty($quotationData['expires_at']) || Carbon::parse($quotationData['expires_at'])->isPast()) {
                return redirect()->route('home')->with('error', 'This quotation link has expired. Please contact our team for an updated quote.');
            }

            if ((string) $quotationBooking->status !== (string) config('booking.status.quotation_requested')) {
                return redirect()->route('home')->with('error', 'This quotation is no longer awaiting acceptance. Please contact our team.');
            }

            if (!hash_equals((string) $quotationData['snapshot_hash'], BookingLinkHelper::quotationSnapshotHash($quotationBooking))) {
                return redirect()->route('home')->with('error', 'This quotation has changed. Please contact our team for an updated offer.');
            }

            if (!is_numeric($quotationBooking->total_estimated) || (float) $quotationBooking->total_estimated <= 0) {
                Log::warning('Quotation conversion blocked because the saved total is missing or invalid', [
                    'booking_id' => $quotationBooking->id,
                    'total_estimated' => $quotationBooking->total_estimated,
                ]);

                return redirect()->route('home')->with('error', 'This quotation has no valid saved total. Please contact our team to review it.');
            }

            $quotationBooking->loadMissing(['customer.user', 'bookingItems.serviceType']);
            $snapshot = BookingLinkHelper::quotationSnapshot($quotationBooking);
            $terms = \App\Services\PendingPaymentManager::quotationTermsSnapshot($quotationBooking);
            $termsHash = hash('sha256', \App\Support\Foundation\CanonicalJson::encode($terms));
            return $this->quotationAcceptanceView($quotationBooking, $token, [
                'snapshot_hash' => $quotationData['snapshot_hash'],
                'snapshot' => $snapshot,
                'display_snapshot' => BookingLinkHelper::quotationDisplaySnapshot($quotationBooking),
                'terms' => $terms,
                'terms_hash' => $termsHash,
            ], $terms, $termsHash, $quotationData['expires_at'], 1);

        } catch (\Exception $e) {
            Log::error('Error converting quotation to booking from email link', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'token' => $request->route('token')
            ]);

            return redirect()->route('home')->with('error', 'An error occurred. Please try again.');
        }
    }

    private function quotationAcceptanceView(Booking $booking, string $token, array $quotation, array $terms,
        string $termsHash, mixed $expiresAt, int $revisionNumber): \Illuminate\Contracts\View\View
    {
        return view('checkout.quotation-acceptance', compact('booking', 'token', 'quotation', 'terms', 'termsHash', 'expiresAt', 'revisionNumber'));
    }

    public function acceptQuotation(Request $request, string $token)
    {
        $data = $request->validate([
            'accept_terms' => ['required', 'accepted'],
            'terms_hash' => ['required', 'string', 'size:64'],
        ]);
        $issuedQuote = PendingPaymentLink::query()->where('token', $token)->where('type', 'quotation_conversion')->exists();

        [$paymentLink, $error] = DB::transaction(function () use ($request, $token, $data, $issuedQuote): array {
            $quote = null;
            if ($issuedQuote) {
                $quote = PendingPaymentLink::query()->where('token', $token)->where('type', 'quotation_conversion')
                    ->lockForUpdate()->first();
                if (! $quote || ! $quote->isValid()) return [null, 'This quotation has expired or is no longer valid. Please contact our team.'];
                $booking = Booking::query()->lockForUpdate()->find($quote->booking_id);
            } else {
                $legacy = BookingLinkHelper::decryptBookingData($token);
                if (! $legacy || ($legacy['type'] ?? null) !== 'quotation_conversion') return [null, 'Invalid quotation link.'];
                if (empty($legacy['snapshot_hash'])) return [null, 'This older quotation cannot be verified. Please contact our team for an updated offer.'];
                if (empty($legacy['expires_at']) || Carbon::parse($legacy['expires_at'])->isPast()) return [null, 'This quotation has expired. Please contact our team for an updated offer.'];
                $booking = Booking::query()->lockForUpdate()->find($legacy['booking_id'] ?? null);
                if ($booking && ! empty($legacy['snapshot_hash'])
                    && ! hash_equals((string) $legacy['snapshot_hash'], BookingLinkHelper::quotationSnapshotHash($booking))) {
                    return [null, 'This quotation has changed. Please contact our team for an updated offer.'];
                }
            }
            if (! $booking) return [null, 'Quotation not found.'];
            if ($booking->payment_status === 'paid') return [null, 'This booking has already been paid.'];
            if ((string) $booking->status !== (string) config('booking.status.quotation_requested')) {
                return [null, 'This quotation is no longer awaiting acceptance. Please contact our team.'];
            }
            if (! is_numeric($booking->total_estimated) || (float) $booking->total_estimated <= 0) {
                return [null, 'This quotation has no valid saved total. Please contact our team.'];
            }

            if ($quote) {
                $quotation = data_get($quote->booking_context, 'quotation', []);
                $snapshotHash = (string) ($quotation['snapshot_hash'] ?? '');
                if ($snapshotHash === '' || ! hash_equals($snapshotHash, BookingLinkHelper::quotationSnapshotHash($booking))) {
                    return [null, 'This quotation has changed. Please contact our team for an updated offer.'];
                }
                $terms = is_array($quotation['terms'] ?? null)
                    ? $quotation['terms']
                    : \App\Services\PendingPaymentManager::quotationTermsSnapshot($booking);
                $termsHash = (string) ($quotation['terms_hash'] ?? hash('sha256', \App\Support\Foundation\CanonicalJson::encode($terms)));
                if (! hash_equals($termsHash, hash('sha256', \App\Support\Foundation\CanonicalJson::encode($terms)))) {
                    return [null, 'The quotation terms could not be verified. Please contact our team.'];
                }
                if (! hash_equals($termsHash, $data['terms_hash'])) return [null, 'The terms changed. Review the current offer and accept again.'];
            } else {
                $terms = \App\Services\PendingPaymentManager::quotationTermsSnapshot($booking);
                $termsHash = hash('sha256', \App\Support\Foundation\CanonicalJson::encode($terms));
                if (! hash_equals($termsHash, $data['terms_hash'])) return [null, 'The terms changed. Review the current offer and accept again.'];
                $legacyHash = hash('sha256', $token);
                $quote = PendingPaymentLink::query()->where('booking_id', $booking->id)->where('type', 'quotation_conversion')
                    ->orderByDesc('revision_number')->get()->first(fn (PendingPaymentLink $candidate): bool =>
                        data_get($candidate->acceptance_evidence, 'legacy_token_hash') === $legacyHash);
                if (! $quote) {
                    $quote = \App\Services\PendingPaymentManager::createQuotationLink($booking);
                    $quotation = $quote->booking_context['quotation'] ?? [];
                    if (! hash_equals((string) ($quotation['terms_hash'] ?? ''), $termsHash)) {
                        throw new \RuntimeException('Quotation terms changed during acceptance.');
                    }
                }
            }

            $paymentLink = \App\Services\PendingPaymentManager::createPaymentLink($booking);
            if (! is_numeric($paymentLink->amount_due) || (float) $paymentLink->amount_due <= 0) {
                return [null, 'Quotation amount is invalid. Please contact our team.'];
            }

            if (empty(data_get($quote->acceptance_evidence, 'accepted_terms_hash'))) {
                $now = now();
                $evidence = [
                    'confirmed_at' => $now->copy()->utc()->toIso8601String(),
                    'accepted_at' => ($quote->accepted_at ?: $now)->copy()->utc()->toIso8601String(),
                    'accepted_terms' => $terms,
                    'accepted_terms_hash' => $termsHash,
                    'accepted_quote_snapshot_hash' => $snapshotHash ?? data_get($quote->booking_context, 'quotation.snapshot_hash'),
                    'revision_number' => (int) ($quote->revision_number ?: 1),
                    'user_id' => Auth::id() ? (string) Auth::id() : null,
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                ];
                if (! $issuedQuote) $evidence['legacy_token_hash'] = hash('sha256', $token);
                $quoteContext = $quote->booking_context ?? [];
                if (! is_array(data_get($quoteContext, 'quotation.terms'))) {
                    data_set($quoteContext, 'quotation.terms', $terms);
                    data_set($quoteContext, 'quotation.terms_hash', $termsHash);
                }
                $quote->forceFill([
                    'accepted_at' => $quote->accepted_at ?: $now,
                    'accessed_at' => $quote->accessed_at ?: $now,
                    'access_count' => (int) $quote->access_count + 1,
                    'booking_context' => $quoteContext,
                    'acceptance_evidence' => $evidence,
                ])->save();
                PendingPaymentLink::query()->where('booking_id', $booking->id)->where('type', 'quotation_conversion')
                    ->where('id', '!=', $quote->id)->whereNull('accepted_at')->whereNull('invalidated_at')
                    ->update(['invalidated_at' => $now]);
            }

            return [$paymentLink, null];
        });

        if ($error) return back()->withInput()->with('error', $error);
        return redirect()->route('checkout.payment-resume', ['token' => $paymentLink->token])
            ->with('info', 'Quotation accepted. Please complete your booking payment.');
    }

    /**
     * Route legacy direct-payment quotation links through the current offer review.
     */
    public function quotationToPayment(Request $request)
    {
        try {
            $quotationData = BookingLinkHelper::decryptBookingData((string) $request->route('token', ''));
            if (! $quotationData || ($quotationData['type'] ?? null) !== 'quotation_payment') {
                return redirect()->route('checkout')->with('error', 'Invalid quotation link.');
            }

            $booking = Booking::query()->with(['bookingItems.serviceType'])->find($quotationData['booking_id'] ?? null);
            if (! $booking) return redirect()->route('checkout')->with('error', 'Quotation not found.');
            if ((string) $booking->status !== (string) config('booking.status.quotation_requested')
                || ! is_numeric($booking->total_estimated) || (float) $booking->total_estimated <= 0) {
                return redirect()->route('checkout')->with('error', 'This quotation is no longer available. Please contact our team for a current offer.');
            }

            $snapshot = BookingLinkHelper::quotationSnapshot($booking);
            $snapshotHash = hash('sha256', \App\Support\Foundation\CanonicalJson::encode($snapshot));
            $acceptedQuote = PendingPaymentLink::query()->where('booking_id', $booking->id)
                ->where('type', 'quotation_conversion')->whereNotNull('accepted_at')
                ->orderByDesc('revision_number')->first();
            if (hash_equals($snapshotHash, (string) data_get($acceptedQuote?->acceptance_evidence, 'accepted_quote_snapshot_hash', ''))
                && data_get($acceptedQuote?->acceptance_evidence, 'accepted_terms_hash')) {
                $paymentLink = PendingPaymentLink::query()->where('booking_id', $booking->id)
                    ->where('type', 'payment_reminder')->whereNull('invalidated_at')
                    ->where('expires_at', '>', now())->first();
                if ($paymentLink) return redirect()->route('checkout.payment-resume', ['token' => $paymentLink->token]);
            }

            $quote = PendingPaymentLink::query()->where('booking_id', $booking->id)
                ->where('type', 'quotation_conversion')->whereNull('accepted_at')->whereNull('invalidated_at')
                ->where('expires_at', '>', now())->orderByDesc('revision_number')->first();
            if (! $quote || ! hash_equals($snapshotHash, (string) data_get($quote->booking_context, 'quotation.snapshot_hash', ''))) {
                $quote = \App\Services\PendingPaymentManager::createQuotationLink($booking);
            }

            return redirect()->route('checkout.quotation-convert', ['token' => $quote->token]);
        } catch (\Throwable $exception) {
            Log::warning('Legacy quotation link could not be routed to offer review', ['error' => $exception->getMessage()]);
            return redirect()->route('checkout')->with('error', 'We could not open this quotation. Please contact our team for help.');
        }
    }

    private function recordGatewayReceipt(Booking $booking, array $gateway): void
    {
        $payload = $gateway['payload'] ?? $gateway;
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $transactionId = (string) ($gateway['transaction_id'] ?? $gateway['payment_id'] ?? $booking->payment_gateway_transaction_id ?? hash('sha256', $payloadJson));
        $amount = (float) ($gateway['amount'] ?? $gateway['paid_amount'] ?? $booking->amount_to_pay ?? $booking->total_actual ?? $booking->total_estimated ?? 0);

        $this->paymentLedger->receive($booking, [
            'amount' => $amount,
            'source_amount' => $amount,
            'source_currency' => strtoupper((string) ($gateway['currency'] ?? $booking->currency ?? 'LKR')),
            'payment_method' => 'webxpay',
            'payment_stage' => 'gateway_settlement',
            'payment_purpose' => 'booking_payment',
            'reference' => $transactionId,
            'idempotency_key' => "webxpay:{$transactionId}",
            'provider_event_id' => $transactionId,
            'provider_payload_checksum' => hash('sha256', $payloadJson),
            'received_at' => $gateway['paid_at'] ?? now(),
            'finalized_at' => $gateway['paid_at'] ?? now(),
            'received_via' => 'company',
            'notes' => 'Canonically recorded from verified WebXPay settlement.',
        ], Auth::id());
    }

    /**
     * Invalidate payment link after successful payment
     * Called when payment is completed to prevent reuse of payment link
     * 
     * @param Request $request
     * @return void
     */
    public function invalidatePaymentLink(Request $request): void
    {
        try {
            $token = session()->get('payment_link_token');
            if ($token) {
                \App\Services\PendingPaymentManager::invalidatePaymentLink($token);
                session()->forget('payment_link_token');
            }
        } catch (\Exception $e) {
            Log::warning('Error invalidating payment link', [
                'error' => $e->getMessage(),
                'token' => $token ?? null
            ]);
        }
    }
}
