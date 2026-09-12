<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\CheckoutConfirmationMail;
use App\Mail\PaymentInitiatedMail;
use App\Mail\QuotationRequestMail;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingAddon;
use App\Models\Booking\BookingItem;
use App\Models\Service\ServiceType;
use App\Models\TermsAndCondition;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Website\WebsiteSetting;
use App\Services\BookingFlowService;
use App\Services\CustomerService;
use App\Services\MailDispatchService;
use App\Services\WebXPayService;
use App\Services\CurrencyService;
use App\Services\PromoCodeService;
use App\Services\WebsiteSettingsService;
use App\Services\Sms\SmsAutomationService;
use App\Services\PaymentEventService;
use App\Helpers\BookingLinkHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

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
            'currency' => $extraKm['currency'] ?? config('booking.base_currency', 'LKR'),
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
        $bookingBaseCurrency = $this->resolveBookingBaseCurrency($bookingSettings);
        if (!$guestBookingEnabled && !Auth::check()) {
            return redirect()->route('home')
                ->with('error', 'Guest booking is currently disabled. Please sign in to continue.');
        }

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

        $paymentSettings = $this->resolvePaymentSettings();
        $paymentType = $request->old('payment_type', $request->query('type', 'full'));
        $offlinePaymentEnabled = $this->normalizeBoolean($paymentSettings['payment_offline_enabled'] ?? null, false);

        // Validate payment type
        $allowedPaymentTypes = ['full', 'quotation'];
        if ($paymentSettings['advance_payment_enabled']) {
            $allowedPaymentTypes[] = 'advance';
        }
        if ($offlinePaymentEnabled) {
            $allowedPaymentTypes[] = 'checkin';
        }
        if (!in_array($paymentType, $allowedPaymentTypes, true)) {
            $paymentType = 'full';
        }
        if (!$paymentSettings['advance_payment_enabled'] && $paymentType === 'advance') {
            $paymentType = 'full';
        }
        if (!$offlinePaymentEnabled && $paymentType === 'checkin') {
            $paymentType = 'full';
        }

        // Get dynamic T&C grouped by service type and payment type based on cart items
        $serviceMap = [
            'airport_transfers' => 'vehicle_rental',
            'ride_now' => 'vehicle_rental',
            'day_rental' => 'vehicle_rental',
            'point_to_point' => 'vehicle_rental',
            'corporate_transport' => 'vehicle_rental',
        ];

        $serviceCodes = collect($cart)->pluck('service_type')->filter()->unique();
        $termsByService = [];
        $termsByPaymentType = [];

        foreach ($serviceCodes as $code) {
            // First, try to find ServiceType records that match the cart code
            $serviceTypes = ServiceType::publicContext()->where('code', $code)->get();

            // If this service code maps to a legacy grouping (eg. vehicle_rental), also try to find matching service types
            $mapped = $serviceMap[$code] ?? null;
            if ($mapped) {
                $serviceTypes = $serviceTypes->merge(
                    ServiceType::publicContext()
                        ->where(function ($query) use ($mapped) {
                            $query->where('code', $mapped)->orWhere('type', $mapped);
                        })
                        ->get()
                );
            }

            // Prefer fetching terms by service_type_id for discovered service types
            if ($serviceTypes && $serviceTypes->count()) {
                foreach ($serviceTypes->unique('id') as $st) {
                    $terms = TermsAndCondition::getServiceTerms($st->id);
                    if ($terms && $terms->count()) {
                        $termsByService[$st->code] = $terms;
                    }
                }
            } else {
                // Fallback to legacy behaviour: use mapped code or the code itself
                $mappedFallback = $mapped ?? $code;
                $terms = TermsAndCondition::getServiceTerms($mappedFallback);
                if ($terms && $terms->count()) {
                    $termsByService[$mappedFallback] = $terms;
                }
            }
        }

        // Always include general service terms if available
        $general = TermsAndCondition::getGeneralServiceTerms();
        if ($general && $general->count()) {
            $termsByService['general'] = $general;
        }

        $availablePaymentTypes = ['full', 'quotation'];
        if ($paymentSettings['advance_payment_enabled']) {
            $availablePaymentTypes[] = 'advance';
        }
        if ($offlinePaymentEnabled) {
            $availablePaymentTypes[] = 'checkin';
        }

        foreach ($availablePaymentTypes as $type) {
            $paymentTerms = TermsAndCondition::getPaymentTermsForCheckout($type);
            if ($paymentTerms && $paymentTerms->count()) {
                $termsByPaymentType[$type] = $paymentTerms;
            }
        }

        $paymentMethods = $this->getAvailablePaymentMethods(
            $paymentSettings['webxpay_enabled'],
            $paymentSettings['payment_online_enabled'],
            $paymentSettings['payment_offline_enabled']
        );

        $advancePaymentEnabled = $paymentSettings['advance_payment_enabled'];
        $advancePercentage = $paymentSettings['advance_payment_percentage'];
        $advanceMinAmount = $this->currencyService->convertFromLKR(
            $paymentSettings['advance_payment_min_amount'],
            $cartData['currency'] ?? $this->currencyService->getSelectedCurrency()
        );

        // Load countries for dynamic dropdown
        $countries = \App\Models\Country::orderBy('name')->get(['id', 'name', 'code', 'callcode']);

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
            'countries'
        ));
    }

    /**
     * Process checkout form submission
     */
    public function process(Request $request)
    {
        $bookingSettings = $this->websiteSettingsService->getBookingSettings();
        $bookingBaseCurrency = $this->resolveBookingBaseCurrency($bookingSettings);
        $guestBookingEnabled = $this->normalizeBoolean($bookingSettings['guest_booking_enabled'] ?? null, true);
        if (!$guestBookingEnabled && !Auth::check()) {
            return redirect()->route('home')
                ->with('error', 'Guest booking is currently disabled. Please sign in to continue.');
        }

        $paymentSettings = $this->resolvePaymentSettings();
        $advancePaymentEnabled = $paymentSettings['advance_payment_enabled'];
        $advancePercentage = $paymentSettings['advance_payment_percentage'];
        $webxpayEnabled = $paymentSettings['webxpay_enabled'];
        $offlinePaymentEnabled = $this->normalizeBoolean($paymentSettings['payment_offline_enabled'] ?? null, false);
        $allowedPaymentTypes = $advancePaymentEnabled ? ['full', 'advance', 'quotation'] : ['full', 'quotation'];
        if ($offlinePaymentEnabled) {
            $allowedPaymentTypes[] = 'checkin';
        }

        $allowedPaymentMethodKeys = $this->getAvailablePaymentMethods(
            $webxpayEnabled,
            $paymentSettings['payment_online_enabled'],
            $paymentSettings['payment_offline_enabled']
        )->keys()->all();
        if (empty($allowedPaymentMethodKeys)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'No payment methods are currently available. Please try again later.');
        }

        // Define validation rules
        $rules = [
            'payment_type' => 'required|in:' . implode(',', $allowedPaymentTypes),
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|min:5|max:20',
            'phone_country_code' => 'required|string|max:5',
            'phone_international' => 'required|string|regex:/^\+[0-9]{1,3}[0-9]{6,14}$/',
            'email' => 'required|email|max:255',
            'identification' => 'nullable|string|max:50',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:100',
            'country' => 'required|string|max:100',
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
            'first_name.required' => 'Please enter your first name.',
            'last_name.required' => 'Please enter your last name.',
            'phone.required' => 'Please enter your phone number.',
            'phone.min' => 'Phone number is too short.',
            'phone_country_code.required' => 'Please select a valid country for your phone number.',
            'phone_international.required' => 'Please enter a valid international phone number.',
            'phone_international.regex' => 'Please enter a valid international phone number format with country code.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'identification.required' => 'Please enter your identification number.',
            'address.required' => 'Please enter your address.',
            'city.required' => 'Please enter your city.',
            'country.required' => 'Please enter your country.',
            'terms_accepted.*.accepted' => 'You must accept all applicable terms and conditions to proceed.',
        ];

        $validated = $request->validate($rules, $messages);

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
        $existingCustomer = $this->customerService->getCustomerByEmail($validated['email']);
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
        $serviceMap = [
            'airport_transfers' => 'vehicle_rental',
            'ride_now' => 'vehicle_rental',
            'day_rental' => 'vehicle_rental',
            'point_to_point' => 'vehicle_rental',
            'corporate_transport' => 'vehicle_rental',
        ];
        $serviceCodes = collect($cart)->pluck('service_type')->filter()->unique();
        $requiredTerms = collect();
        foreach ($serviceCodes as $code) {
            $mapped = $serviceMap[$code] ?? $code;
            $requiredTerms = $requiredTerms->merge(TermsAndCondition::getServiceTerms($mapped));
        }
        $requiredTerms = $requiredTerms->merge(TermsAndCondition::getGeneralServiceTerms());
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

            // Prepare customer data
            $validated['customer_name'] = trim($validated['first_name'] . ' ' . $validated['last_name']);
            $validated['customer_email'] = $validated['email'];
            // Use international format phone number for payment gateway
            $validated['customer_phone'] = $validated['phone_international'] ?? $validated['phone'];
            $validated['customer_address'] = $validated['address'];
            $validated['customer_city'] = $validated['city'];
            $validated['customer_country'] = $validated['country'];
            $validated['customer_identification'] = $validated['identification'];

            // Step 1: Create or get customer
            $customer = $this->customerService->getOrCreateCustomer($validated);

            // Use the converted snapshot shown on checkout, not the raw LKR cart.
            $totals = $cartData['totals'] ?? [];
            $subtotal = $totals['subtotal'] ?? 0;
            $serviceFee = $totals['service_fee'] ?? 0;
            $tax = $totals['tax'] ?? 0;
            $vat = $totals['vat'] ?? 0;
            $discount = $totals['coupon_discount'] ?? 0;
            $total = $totals['total'] ?? 0;

            $subtotal = max(0, $this->currencyService->normalizeAmount($subtotal));
            $serviceFee = max(0, $this->currencyService->normalizeAmount($serviceFee));
            $tax = max(0, $this->currencyService->normalizeAmount($tax));
            $vat = max(0, $this->currencyService->normalizeAmount($vat));
            $discount = max(0, $this->currencyService->normalizeAmount($discount));
            $total = max(0, $this->currencyService->normalizeAmount($total));


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
            $paymentAmount = max(0, $this->currencyService->normalizeAmount($paymentAmount));

            // Prepare flight details
            $flightDetails = null;
            if ($validated['flight_airline'] ?? null || $validated['flight_number'] ?? null) {
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
                'special_requirements' => $validated['special_notes'] ?? null,
                'contact_time' => $validated['contact_time'] ?? null,
                'status' => config('booking.status.draft'),
                'booking_source' => 'public',
                'created_from' => 'web',
                'created_user_id' => Auth::id(),
            ]);

            // Store additional data in workflow_data
            $workflowData = [
                'additional_notes' => $validated['additional_notes'] ?? null,
                'budget_range' => $validated['budget_range'] ?? null,
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
                    $unitPrice = max(0, $this->currencyService->normalizeAmount($item['price']));
                }

                if (isset($item['total_price'])) {
                    $totalPrice = max(0, $this->currencyService->normalizeAmount($item['total_price']));
                } elseif (isset($item['total'])) {
                    $totalPrice = max(0, $this->currencyService->normalizeAmount($item['total']));
                } elseif (isset($item['amount'])) {
                    $totalPrice = max(0, $this->currencyService->normalizeAmount($item['amount']));
                } elseif ($unitPrice > 0 && $durationDays > 0) {
                    // Fallback: calculate total from unit price and duration
                    $totalPrice = max(0, $this->currencyService->normalizeAmount($unitPrice * $durationDays));
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
        } catch (\Exception $e) {
            DB::rollback();
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
                'reference' => $booking->booking_number
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
            // If WebXPay is not enabled, fall back to offline payment flow
            Log::warning('Online Payment: WebXPay not enabled, falling back to offline', [
                'booking_id' => $booking->id,
            ]);
            return $this->processOfflinePayment($booking, 'online');
        }

        try {
            // Create payment request with WebXPay
            $paymentType = $booking->payment_type === 'advance' ? 'advance' : 'full';

            $result = $this->webxPayService->createPayment($booking, $amount, $paymentType);

            if ($result['success']) {
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
                // Payment gateway returned error - fallback to offline payment
                $errorMessage = $result['message'] ?? $result['error'] ?? 'Payment gateway error';

                Log::warning('Online Payment: Payment gateway error, falling back to offline', [
                    'booking_id' => $booking->id,
                    'error' => $errorMessage
                ]);

                return $this->processOfflinePayment($booking, 'online');
            }
        } catch (\Exception $e) {
            Log::error('Online Payment: Exception occurred, falling back to offline', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Fallback to offline payment on any error
            return $this->processOfflinePayment($booking, 'online');
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
                'status' => $booking->status
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

        return view('checkout.success', compact('type', 'reference', 'method', 'status', 'booking'));
    }

    /**
     * WebXPay callback handler (return from payment)
     */
    public function webxpayCallback(Request $request)
    {
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
                    // Mock payment successful
                    $wasPaid = $booking->payment_status === 'paid';
                    $booking->update([
                        'status' => config('booking.status.confirmed'),
                        'payment_status' => 'paid',
                        'payment_gateway_transaction_id' => $request->input('transaction_id'),
                        'paid_at' => now(),
                        'confirmed_at' => now(),
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

                    // Clear session
                    session()->forget(['pending_booking_id', 'pending_payment_amount']);

                    return redirect()->route('checkout.success', [
                        'type' => 'payment',
                        'reference' => $booking->booking_number,
                        'method' => 'online',
                        'status' => 'confirmed'
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

            if (!empty($verificationResult['success']) && ($verificationResult['status'] === 'completed' || $verificationResult['status'] === 'success')) {
                $this->paymentEventService->recordEvent('payment_success', ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'transaction_id' => $verificationResult['transaction_id'] ?? null, 'payload' => $verificationResult, 'source' => 'webxpay', 'status' => 'success']);

                $wasPaid = false;
                DB::beginTransaction();

                // Re-fetch with a pessimistic lock to guard against concurrent callback/notify
                $lockedBooking = Booking::where('id', $booking->id)->lockForUpdate()->first();

                if ($lockedBooking && $lockedBooking->payment_status !== 'paid') {
                    $lockedBooking->update([
                        'status' => config('booking.status.confirmed'),
                        'payment_status' => 'paid',
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

                // Clear session
                session()->forget('pending_booking_id');

                return redirect()->route('checkout.success', [
                    'type' => 'payment',
                    'reference' => $booking->booking_number,
                    'method' => 'online',
                    'status' => 'confirmed'
                ])->with('success', 'Payment successful! Your booking is confirmed.');
            } else {
                Log::warning('WebXPay: payment verification failed', ['verification' => $verificationResult, 'booking_id' => $booking->id]);
                $this->paymentEventService->recordEvent('payment_failed', ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'payload' => $verificationResult, 'source' => 'webxpay', 'status' => 'failed']);
                // Payment failed or pending
                $booking->update([
                    'status' => config('booking.status.pending_payment'),
                    'payment_status' => 'failed',
                ]);

                return redirect()->route('checkout')->with('error', 'Payment verification failed. Please try again.');
            }
        } catch (\Exception $e) {
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

                // Extract booking number from order ID (format: BK12345678-timestamp)
                $bookingNumber = explode('-', $orderId)[0] ?? null;

                if ($bookingNumber && $verificationResult['status'] === 'completed') {
                    $emailBooking = null;

                    DB::transaction(function () use ($bookingNumber, $verificationResult, &$emailBooking) {
                        // Lock the row — prevents duplicate delivery race conditions
                        $booking = Booking::where('booking_number', $bookingNumber)
                            ->lockForUpdate()
                            ->first();

                        if (!$booking) return;

                        // Idempotency: if already paid, nothing to do
                        if ($booking->payment_status === 'paid') return;

                        $booking->update([
                            'status'                           => config('booking.status.confirmed'),
                            'payment_status'                   => 'paid',
                            'payment_gateway_transaction_id'   => $verificationResult['transaction_id'],
                            'paid_at'                          => $verificationResult['paid_at'] ?? now(),
                            'confirmed_at'                     => now(),
                        ]);

                        $emailBooking = $booking;
                    });

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
        if ($currency !== '') {
            return $currency;
        }

        return config('booking.base_currency', 'LKR');
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

    /**
     * Process payment from the dedicated payment resume page
     */
    public function processPaymentResume(Request $request)
    {
        try {
            $token = $request->input('payment_token');
            $bookingId = $request->input('booking_id');
            $amount = $request->input('amount');
            $paymentMethod = $request->input('payment_method', 'webxpay');

            // Validate the payment link
            $paymentLinkData = \App\Services\PendingPaymentManager::retrievePaymentLink($token);
            if (!$paymentLinkData) {
                return back()->with('error', 'Payment session expired. Please try again.');
            }

            $booking = $paymentLinkData['booking'];

            // Validate booking ID matches
            if ($booking->id !== $bookingId) {
                return back()->with('error', 'Invalid payment request.');
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
            $token = $request->route('token');

            if (!$token) {
                return redirect()->route('home')->with('error', 'Invalid quotation link.');
            }

            $quotationData = BookingLinkHelper::decryptBookingData($token);

            if (!$quotationData || $quotationData['type'] !== 'quotation_conversion') {
                return redirect()->route('home')->with('error', 'Invalid quotation link.');
            }

            $quotationBooking = Booking::find($quotationData['booking_id']);

            if (!$quotationBooking) {
                return redirect()->route('home')->with('error', 'Quotation not found.');
            }

            // For quotations, ensure we have payment data
            if (!$quotationBooking->total_estimated && !$quotationBooking->amount_to_pay) {
                // Set a default amount or use the quotation amount if available
                $quotationBooking->total_estimated = $quotationBooking->quotation_amount ?? 1000; // Fallback amount
                $quotationBooking->save();
            }

            // Generate payment link directly using the PendingPaymentManager
            try {
                $paymentLink = \App\Services\PendingPaymentManager::createPaymentLink($quotationBooking);

                // Guard: if payment amount is not valid, stop and report
                if (($paymentLink->amount_due ?? 0) <= 0) {
                    Log::warning('Quotation conversion payment link has zero amount_due', [
                        'booking_id' => $quotationBooking->id,
                        'payment_link_id' => $paymentLink->id ?? null,
                        'amount_due' => $paymentLink->amount_due
                    ]);

                    return redirect()->route('home')->with('error', 'Quotation amount is invalid. Please contact support.');
                }

                return redirect()->route('checkout.payment-resume', ['token' => $paymentLink->token])
                    ->with('info', 'Your quotation is ready for payment. Please complete the booking below.');

            } catch (\Exception $e) {
                Log::error('Failed to generate payment link', ['error' => $e->getMessage()]);
                return redirect()->route('home')->with('error', 'Unable to process quotation. Please contact support.');
            }

        } catch (\Exception $e) {
            Log::error('Error converting quotation to booking from email link', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'token' => $request->route('token')
            ]);

            return redirect()->route('home')->with('error', 'An error occurred. Please try again.');
        }
    }

    /**
     * Convert quotation to payment booking
     * Allows customer to directly proceed to payment from quotation email link
     * Creates a new confirmed booking with same details and discounts
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function quotationToPayment(Request $request)
    {
        try {
            $token = $request->get('token');
            if (!$token) {
                return redirect()->route('checkout')->with('error', 'Invalid quotation link.');
            }

            $quotationData = BookingLinkHelper::decryptBookingData($token);
            if (!$quotationData || $quotationData['type'] !== 'quotation_payment') {
                return redirect()->route('checkout')->with('error', 'Invalid quotation link.');
            }

            $quotationBooking = Booking::find($quotationData['booking_id']);
            if (!$quotationBooking) {
                return redirect()->route('checkout')->with('error', 'Quotation not found.');
            }

            // Check if customer is logged in, if not redirect to login
            if (!Auth::check() && $quotationBooking->customer && $quotationBooking->customer->user) {
                session()->put('quotation_booking_id', $quotationBooking->id);
                return redirect()->route('login')->with('info', 'Please log in to proceed with your quotation booking.');
            }

            DB::beginTransaction();

            try {
                // Get quotation booking details
                $customer = $quotationBooking->customer;
                $workflowData = $quotationBooking->workflow_data ?? [];
                $cartItems = $workflowData['cart_items'] ?? [];

                // Create new booking from quotation (booking-level data only)
                $newBooking = Booking::create([
                    'customer_id' => $customer->id,
                    'booking_number' => Booking::generateBookingNumber(),
                    // Remove all item-related fields - they will be in booking_items
                    'base_amount' => $quotationBooking->base_amount,
                    'service_fee' => $quotationBooking->service_fee,
                    'tax_amount' => $quotationBooking->tax_amount,
                    'vat_amount' => $quotationBooking->vat_amount,
                    'discount_amount' => $quotationBooking->discount_amount,
                    'total_estimated' => $quotationBooking->total_estimated,
                    'currency' => $quotationBooking->currency,
                    'payment_method' => 'online',
                    'payment_status' => 'pending',
                    'payment_type' => 'full',
                    'amount_to_pay' => $quotationBooking->total_estimated,
                    'special_requirements' => $quotationBooking->special_requirements,
                    'contact_time' => $quotationBooking->contact_time,
                    'status' => config('booking.status.payment_processing'),
                    'booking_source' => 'public',
                    'created_from' => 'quotation_acceptance',
                    'created_user_id' => Auth::id(),
                    'workflow_data' => $workflowData,
                ]);

                // Copy booking items from quotation
                foreach ($quotationBooking->bookingItems as $quotationItem) {
                    BookingItem::create([
                        'booking_id' => $newBooking->id,
                        'vehicle_group_id' => $quotationItem->vehicle_group_id,
                        'vehicle_id' => $quotationItem->vehicle_id,
                        'driver_id' => $quotationItem->driver_id,
                        'service_type_id' => $quotationItem->service_type_id,
                        'from_date' => $quotationItem->from_date,
                        'to_date' => $quotationItem->to_date,
                        'from_time' => $quotationItem->from_time,
                        'to_time' => $quotationItem->to_time,
                        'pickup_location' => $quotationItem->pickup_location,
                        'dropoff_location' => $quotationItem->dropoff_location,
                        'pickup_latitude' => $quotationItem->pickup_latitude,
                        'pickup_longitude' => $quotationItem->pickup_longitude,
                        'pickup_landmark' => $quotationItem->pickup_landmark,
                        'dropoff_latitude' => $quotationItem->dropoff_latitude,
                        'dropoff_longitude' => $quotationItem->dropoff_longitude,
                        'dropoff_landmark' => $quotationItem->dropoff_landmark,
                        'is_self_driven' => $quotationItem->is_self_driven,
                        'unit_price' => $quotationItem->unit_price,
                        'total_price' => $quotationItem->total_price,
                        'quantity' => $quotationItem->quantity,
                        'duration_days' => $quotationItem->duration_days,
                        'duration_hours' => $quotationItem->duration_hours,
                        'currency' => $quotationItem->currency,
                        'status' => 'confirmed',
                        'item_type' => $quotationItem->item_type,
                        'pricing_breakdown' => $quotationItem->pricing_breakdown,
                        'addons' => $quotationItem->addons,
                        'customizations' => $quotationItem->customizations,
                        'metadata' => $quotationItem->metadata,
                    ]);
                }

                // Copy booking addons from quotation
                $quotationBooking->addons()->get()->each(function ($addon) use ($newBooking) {
                    BookingAddon::create([
                        'booking_id' => $newBooking->id,
                        'addon_id' => $addon->addon_id,
                        'qty' => $addon->qty,
                        'rate' => $addon->rate,
                        'amount' => $addon->amount,
                        'is_insurance' => $addon->is_insurance,
                        'is_milage' => $addon->is_milage,
                        'label' => $addon->label,
                    ]);
                });

                // Store booking ID in session for payment processing
                session()->put('pending_booking_id', $newBooking->id);
                session()->put('quotation_source_id', $quotationBooking->id);

                DB::commit();

                // Redirect to checkout payment with new booking
                return redirect()->route('checkout')->with('info', 'Quotation converted to booking. Please complete payment.');
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error converting quotation to payment booking from email link', [
                'error' => $e->getMessage(),
                'token' => $request->get('token')
            ]);

            return redirect()->route('checkout')->with('error', 'An error occurred. Please try again.');
        }
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
