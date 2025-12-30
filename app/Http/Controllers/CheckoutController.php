<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\CheckoutConfirmationMail;
use App\Mail\PaymentInitiatedMail;
use App\Mail\QuotationRequestMail;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingAddon;
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
    protected MailDispatchService $mailDispatchService;
    protected PromoCodeService $promoCodeService;

    public function __construct(
        BookingFlowService $bookingFlowService,
        CustomerService $customerService,
        \App\Services\CartService $cartService,
        WebXPayService $webxPayService,
        CurrencyService $currencyService,
        MailDispatchService $mailDispatchService,
        PromoCodeService $promoCodeService
    ) {
        $this->bookingFlowService = $bookingFlowService;
        $this->customerService = $customerService;
        $this->cartService = $cartService;
        $this->webxPayService = $webxPayService;
        $this->currencyService = $currencyService;
        $this->mailDispatchService = $mailDispatchService;
        $this->promoCodeService = $promoCodeService;
    }

    /**
     * Display checkout page
     */
    public function index(Request $request)
    {
        try {
            $cartModel = $this->cartService->getOrCreateCart();
            $cartData = $this->cartService->toArray($cartModel);
            $cart = $cartData['items'] ?? [];

            if (empty($cart)) {
                return redirect()->route('cart')->with('error', 'Your cart is empty.');
            }
        } catch (\Exception $e) {
            Log::error('Error loading cart for checkout: ' . $e->getMessage());
            return redirect()->route('cart')->with('error', 'Error loading your cart.');
        }

        $paymentType = $request->get('type', 'full');

        // Validate payment type
        if (!in_array($paymentType, ['full', 'advance', 'quotation'])) {
            $paymentType = 'full';
        }

        // Get dynamic T&C based on service type and payment type
        $termsAndConditions = TermsAndCondition::getForCheckout('vehicle_rental', $paymentType);

        // Get available payment methods from config
        $paymentMethods = collect(config('booking.payment_methods', []))
            ->filter(fn($method) => $method['enabled'] ?? false);

        return view('checkout', compact('cart', 'cartData', 'paymentType', 'termsAndConditions', 'paymentMethods'));
    }

    /**
     * Process checkout form submission
     */
    public function process(Request $request)
    {
        // Define validation rules
        $rules = [
            'payment_type' => 'required|in:full,advance,quotation',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|min:5|max:20',
            'phone_country_code' => 'required|string|max:5',
            'phone_international' => 'required|string|regex:/^\+[0-9]{1,3}[0-9]{6,14}$/',
            'email' => 'required|email|max:255',
            'identification' => 'required|string|max:50',
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
            'terms_accepted' => 'required|accepted'
        ];

        // Add payment method validation only if not quotation
        if ($request->input('payment_type') !== 'quotation') {
            $rules['payment_method'] = 'required|in:online,offline';
        }

        // Custom validation messages
        $messages = [
            'first_name.required' => 'Please enter your first name.',
            'last_name.required' => 'Please enter your last name.',
            'phone.required' => 'Please enter your phone number.',
            'phone.min' => 'Phone number is too short.',
            'phone_country_code.required' => 'Please select a valid country for your phone number.',
            'phone_international.required' => 'Please enter a valid international phone number.',
            'phone_international.regex' => 'Please enter a valid international phone number format (e.g., +94771234567).',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'identification.required' => 'Please enter your identification number.',
            'address.required' => 'Please enter your address.',
            'city.required' => 'Please enter your city.',
            'country.required' => 'Please enter your country.',
            'payment_method.required' => 'Please select a payment method.',
            'terms_accepted.required' => 'You must accept the terms and conditions.',
            'terms_accepted.accepted' => 'You must accept the terms and conditions to proceed.',
        ];

        $validated = $request->validate($rules, $messages);

        // Get cart from database
        $cartModel = $this->cartService->getOrCreateCart();
        $cart = $cartModel->items ?? [];

        if (empty($cart)) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }

        // Ensure cart totals are calculated
        if (empty($cartModel->totals)) {
            Log::warning('Cart totals are empty, recalculating', [
                'cart_id' => $cartModel->id,
                'items_count' => count($cart),
                'items' => $cart
            ]);
            $this->cartService->updateTotals($cartModel);
            // Refresh the model to get updated totals
            $cartModel->refresh();
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
            $validated['customer_identification'] = $validated['identification'];

            // Step 1: Create or get customer
            $customer = $this->customerService->getOrCreateCustomer($validated);

            // Get totals from cart (already calculated with proper currency and fees)
            $totals = $cartModel->totals ?? [];
            $subtotal = $totals['subtotal'] ?? 0;
            $serviceFee = $totals['service_fee'] ?? 0;
            $tax = $totals['tax'] ?? 0;
            $vat = $totals['vat'] ?? 0;
            $discount = $totals['coupon_discount'] ?? 0;
            $total = $totals['total'] ?? 0;

            // Log total amounts for debugging
            Log::info('Cart totals retrieved', [
                'cart_id' => $cartModel->id,
                'cart_totals_json' => $cartModel->totals,
                'subtotal' => $subtotal,
                'service_fee' => $serviceFee,
                'tax' => $tax,
                'vat' => $vat,
                'discount' => $discount,
                'total' => $total,
            ]);

            // All amounts are in LKR (base currency)
            // Calculate payment amount based on type
            // Fetch advance percentage from database
            try {
                $advancePercentage = (int)WebsiteSetting::getValue('advance_payment_percentage', 50);
            } catch (\Exception $e) {
                Log::warning('Failed to fetch advance_payment_percentage from database', ['error' => $e->getMessage()]);
                $advancePercentage = config('booking.advance_payment.percentage', 50);
            }
            
            $paymentAmount = match ($validated['payment_type']) {
                'advance' => $total * ($advancePercentage / 100),
                'quotation' => 0,
                default => $total
            };

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

            $serviceTypeId = ServiceType::where('code', $firstItem['service_type'] ?? null)->value('id');
            // return $firstItem;
            // Step 2: Create booking record linked to customer

            switch ($validated['payment_type']) {
                case 'quotation':
                    $number = 'QT' . strtoupper(substr(md5(microtime()), 0, 8));
                    break;
                case 'advance':
                case 'online':
                case 'full':
                    $number = 'BK' . strtoupper(substr(md5(microtime()), 0, 8));
                    break;
                default:
                    $number = 'QT' . strtoupper(substr(md5(microtime()), 0, 8));
            }

            $booking = Booking::create([
                'customer_id' => $customer->id,
                'booking_number' => $number,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'service_type_id' => $serviceTypeId,
                'vehicle_group_id' => $firstItem['vehicle_group_id'] ?? null,
                'pickup_location' => json_encode([
                    'address' => $firstItem['pickup_location'] ?? '',
                    'city' => $validated['city'],
                    'country' => $validated['country'],
                    'lat' => $firstItem['pickup_lat'] ?? null,
                    'lng' => $firstItem['pickup_lng'] ?? null,
                ]),
                'dropoff_location' => json_encode([
                    'address' => $firstItem['dropoff_location'] ?? $firstItem['pickup_location'] ?? '',
                    'city' => $validated['city'],
                    'country' => $validated['country'],
                    'lat' => $firstItem['dropoff_lat'] ?? $firstItem['pickup_lat'] ?? null,
                    'lng' => $firstItem['dropoff_lng'] ?? $firstItem['pickup_lng'] ?? null,
                ]),
                'base_amount' => $subtotal,
                'service_fee' => $serviceFee,
                'tax_amount' => $tax,
                'vat_amount' => $vat,
                'discount_amount' => $discount,
                'total_estimated' => $total,
                'currency' => config('booking.base_currency', 'LKR'),
                'payment_method' => $validated['payment_method'] ?? null,
                'payment_status' => 'pending',
                'payment_type' => $validated['payment_type'],
                'amount_to_pay' => $paymentAmount,
                'special_requirements' => $validated['special_notes'] ?? null,
                'contact_time' => $validated['contact_time'] ?? null,
                'status' => config('booking.status.draft'),
                'created_from' => 'web',
                'created_user_id' => Auth::id(),
            ]);

            // Store additional data in workflow_data
            $workflowData = [
                'additional_notes' => $validated['additional_notes'] ?? null,
                'budget_range' => $validated['budget_range'] ?? null,
                'cart_items' => $cart,
            ];

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

            // Save BookingAddons from cart items
            foreach ($cart as $cartKey => $item) {
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
            Log::info('Pending booking data', ['booking_id' => $booking->id, 'cart' => $cart, 'validated' => $validated]);
            // Handle different payment types
            switch ($validated['payment_type']) {
                case 'quotation':
                    return $this->processQuotationRequest($booking);

                case 'advance':
                case 'full':
                    return $this->processPayment($booking, $validated, $paymentAmount);

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

            // Mark cart as checked out
            $dbCart = $this->cartService->getOrCreateCart();
            $this->cartService->markAsCheckedOut($dbCart);

            // Send quotation request email to customer
            try {
                $this->sendBookingEmail($booking, new QuotationRequestMail($booking));
            } catch (\Exception $e) {
                Log::error('Failed to send quotation email', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage()
                ]);
            }

            DB::commit();

            return redirect()->route('checkout.success', [
                'type' => 'quotation',
                'reference' => $booking->booking_number
            ])->with('success', 'Your quotation request has been submitted successfully! Our team will contact you within 24 hours.');
        } catch (\Exception $e) {
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
            throw new \Exception('Payment processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Process online payment via WebXPay
     */
    protected function processOnlinePayment(Booking $booking, float $amount)
    {
        if (!$this->webxPayService->isEnabled()) {
            // If WebXPay is not enabled, mark as pending for manual processing
            return redirect()->back()->with('error', 'Online payment is currently unavailable. Please try again later or choose offline payment.');
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
                ]);

                $this->sendPaymentInitiatedEmail($booking, $amount);

                DB::commit();

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

                Log::warning('Payment gateway error, falling back to offline payment', [
                    'booking_id' => $booking->id,
                    'error' => $errorMessage
                ]);

                return $this->processOfflinePayment($booking, 'online');
            }
        } catch (\Exception $e) {
            Log::error('Online payment processing error, falling back to offline payment', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
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
        return redirect()->back()->with('error', 'Online payment is currently unavailable. Please try again later or choose offline payment.');
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

            // Send confirmation email to customer
            try {
                $this->sendBookingEmail($booking, new CheckoutConfirmationMail($booking));
            } catch (\Exception $e) {
                Log::error('Failed to send confirmation email', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the booking if email fails
            }

            DB::commit();

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
            Log::error('Failed to complete booking', [
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

        // Fetch booking for display
        $booking = Booking::where('booking_number', $reference)->first();

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
            $bookingId = session()->get('pending_booking_id');

            if (!$bookingId) {
                return redirect()->route('cart')->with('error', 'No pending booking found.');
            }

            $booking = Booking::find($bookingId);

            if (!$booking) {
                return redirect()->route('cart')->with('error', 'Booking not found.');
            }

            // Check if this is from mock gateway (test mode)
            if ($request->has('status') && !$this->webxPayService->isEnabled()) {
                DB::beginTransaction();

                if ($request->input('status') === 'success') {
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
                            $this->sendBookingEmail($booking, new CheckoutConfirmationMail($booking));
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

            // Real WebXPay verification
            $callbackData = $request->all();
            $verificationResult = $this->webxPayService->verifyPayment($callbackData);

            if ($verificationResult['success'] && $verificationResult['status'] === 'completed') {
                DB::beginTransaction();

                // Update booking with payment confirmation
                $wasPaid = $booking->payment_status === 'paid';
                $booking->update([
                    'status' => config('booking.status.confirmed'),
                    'payment_status' => 'paid',
                    'payment_gateway_transaction_id' => $verificationResult['transaction_id'],
                    'paid_at' => $verificationResult['paid_at'] ?? now(),
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
                        $this->sendBookingEmail($booking, new CheckoutConfirmationMail($booking));
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
     * WebXPay notification handler (webhook)
     */
    public function webxpayNotify(Request $request)
    {
        try {
            $callbackData = $request->all();

            Log::info('WebXPay notification received', $callbackData);

            // Verify payment
            $verificationResult = $this->webxPayService->verifyPayment($callbackData);

            if ($verificationResult['success']) {
                $orderId = $verificationResult['order_id'];

                // Extract booking number from order ID (format: BK12345678-timestamp)
                $bookingNumber = explode('-', $orderId)[0] ?? null;

                if ($bookingNumber) {
                    $booking = Booking::where('booking_number', $bookingNumber)->first();

                    if ($booking && $verificationResult['status'] === 'completed') {
                        $wasPaid = $booking->payment_status === 'paid';
                        $booking->update([
                            'status' => config('booking.status.confirmed'),
                            'payment_status' => 'paid',
                            'payment_gateway_transaction_id' => $verificationResult['transaction_id'],
                            'paid_at' => $verificationResult['paid_at'] ?? now(),
                            'confirmed_at' => now(),
                        ]);

                        if (!$wasPaid) {
                            $this->sendBookingEmail($booking, new CheckoutConfirmationMail($booking));
                        }

                        return response()->json(['status' => 'success']);
                    }
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
            return redirect()->route('cart')->with('error', 'No pending booking found.');
        }

        $booking = Booking::find($bookingId);

        if (!$booking) {
            return redirect()->route('cart')->with('error', 'Booking not found.');
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
            $this->sendBookingEmail($booking, new PaymentInitiatedMail($booking, $amount));
        } catch (\Exception $e) {
            Log::error('Failed to send payment initiated email', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
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
            $orderAmount = (float)($totals['subtotal'] ?? 0);
            $discountAmount = (float)($cart->coupon_discount ?? 0);

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

            Log::info('Promo code usage recorded for booking', [
                'promo_code' => $promoCode->code,
                'booking_id' => $booking->id,
                'customer_id' => $customerId,
                'discount_amount' => $discountAmount,
                'order_amount' => $orderAmount,
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the booking
            Log::error('Failed to record promo code usage', [
                'coupon_code' => $cart->coupon_code,
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
