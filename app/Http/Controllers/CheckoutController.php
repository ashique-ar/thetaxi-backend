<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\CheckoutConfirmationMail;
use App\Mail\QuotationRequestMail;
use App\Models\Booking\Booking;
use App\Models\TermsAndCondition;
use App\Models\Vehicle\VehicleGroup;
use App\Services\BookingFlowService;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    protected $bookingFlowService;
    protected $customerService;
    protected $cartService;
    
    public function __construct(
        BookingFlowService $bookingFlowService, 
        CustomerService $customerService,
        \App\Services\CartService $cartService
    )
    {
        $this->bookingFlowService = $bookingFlowService;
        $this->customerService = $customerService;
        $this->cartService = $cartService;
    }
    
    /**
     * Display checkout page
     */
    public function index(Request $request)
    {
        try {
            $cartModel = $this->cartService->getOrCreateCart();
            $cart = $cartModel->items ?? [];
            
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
        
        return view('checkout', compact('cart', 'paymentType', 'termsAndConditions'));
    }
    
    /**
     * Process checkout form submission
     */
    public function process(Request $request)
    {
        $validated = $request->validate([
            'payment_type' => 'required|in:full,advance,quotation',
            'full_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
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
            'payment_method' => 'required_unless:payment_type,quotation|in:paypal,stripe,bank_transfer,online_banking',
            'card_number' => 'required_if:payment_method,stripe|string|max:19',
            'card_expiry' => 'required_if:payment_method,stripe|string|max:5',
            'card_cvc' => 'required_if:payment_method,stripe|string|max:4',
            'save_info' => 'boolean',
            'terms_accepted' => 'required|accepted'
        ]);
        
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }
        
        try {
            DB::beginTransaction();
            
            // Step 1: Create or get customer
            $customer = $this->customerService->getOrCreateCustomer($validated);
            
            // Calculate totals
            $subtotal = collect($cart)->sum(function ($item) {
                return ($item['price'] ?? 0) * ($item['days'] ?? 1);
            });
            
            $serviceFee = 25.00;
            $tax = $subtotal * 0.1;
            $discount = session()->get('cart_discount', 0);
            $total = $subtotal + $serviceFee + $tax - $discount;
            
            // Calculate payment amount based on type
            $paymentAmount = match($validated['payment_type']) {
                'advance' => $total * 0.5,
                'quotation' => 0,
                default => $total
            };
            
            // Prepare flight details
            $flightDetails = null;
            if ($validated['flight_airline'] || $validated['flight_number']) {
                $flightDetails = [
                    'airline' => $validated['flight_airline'],
                    'flight_number' => $validated['flight_number'],
                    'arrival_date' => $validated['flight_arrival_date'],
                    'arrival_time' => $validated['flight_arrival_time'],
                ];
            }
            
            // Step 2: Create booking record linked to customer
            $booking = Booking::create([
                'customer_id' => $customer->id,
                'booking_number' => 'BK' . strtoupper(substr(md5(microtime()), 0, 8)),
                'from_date' => now(),
                'to_date' => now()->addDays(1),
                'service_type_id' => 1, // Default service type ID
                'pickup_location' => json_encode([
                    'city' => $validated['city'],
                    'country' => $validated['country'],
                ]),
                'dropoff_location' => json_encode([
                    'city' => $validated['city'],
                    'country' => $validated['country'],
                ]),
                'base_amount' => $subtotal,
                'service_fee' => $serviceFee,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'total_estimated' => $total,
                'currency' => 'LKR',
                'payment_method' => $validated['payment_method'] ?? null,
                'payment_status' => 'pending',
                'special_requirements' => $validated['special_notes'],
                'contact_time' => $validated['contact_time'],
                'status' => 'draft',
                'created_from' => 'web',
                'created_user_id' => Auth::id(),
            ]);
            
            // Store flight details as JSON in workflow_data if provided
            if ($flightDetails) {
                $booking->workflow_data = [
                    'flight_details' => $flightDetails,
                    'additional_notes' => $validated['additional_notes'],
                ];
                $booking->save();
            }
            
            // Store cart items for reference
            session()->put('pending_booking_id', $booking->id);
            session()->put('pending_booking_cart', $cart);
            
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
            $booking->update(['status' => 'quotation_pending']);
            
            // Send quotation request email to customer
            Mail::send(new QuotationRequestMail($booking));
            
            // Clear cart
            session()->forget(['cart', 'cart_discount', 'applied_coupon']);
            
            DB::commit();
            
            return redirect()->route('checkout.success', [
                'type' => 'quotation',
                'reference' => $booking->reference
            ])->with('success', 'Your quotation request has been submitted successfully!');
            
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
                case 'paypal':
                    return $this->processPayPalPayment($booking);
                    
                case 'stripe':
                    return $this->processStripePayment($booking, $validated);
                    
                case 'bank_transfer':
                case 'online_banking':
                    return $this->processBankTransfer($booking);
                    
                default:
                    throw new \Exception('Invalid payment method');
            }
            
        } catch (\Exception $e) {
            throw new \Exception('Payment processing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process PayPal payment
     */
    protected function processPayPalPayment(Booking $booking)
    {
        // Store booking for PayPal callback
        session()->put('pending_booking_id', $booking->id);
        
        // In a real implementation, you would redirect to PayPal
        // For now, we'll simulate successful payment
        return $this->completeBooking($booking, 'paypal', true);
    }
    
    /**
     * Process Stripe payment
     */
    protected function processStripePayment(Booking $booking, array $validated)
    {
        // In a real implementation, you would integrate with Stripe API
        $cardNumber = str_replace(' ', '', $validated['card_number']);
        
        // Basic card validation
        if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            throw new \Exception('Invalid card number');
        }
        
        // Simulate payment processing (in real implementation, use Stripe SDK)
        $paymentSuccessful = true;
        
        if ($paymentSuccessful) {
            return $this->completeBooking($booking, 'stripe', true, ['last4' => substr($cardNumber, -4)]);
        } else {
            throw new \Exception('Payment failed. Please check your card details.');
        }
    }
    
    /**
     * Process bank transfer
     */
    protected function processBankTransfer(Booking $booking)
    {
        // For bank transfer, booking is pending until payment confirmation
        return $this->completeBooking($booking, $booking->payment_method, false);
    }
    
    /**
     * Complete booking and send confirmation
     */
    protected function completeBooking(Booking $booking, string $paymentMethod, bool $paymentProcessed = false, array $paymentDetails = [])
    {
        try {
            // Update booking status
            $booking->update([
                'payment_status' => $paymentProcessed,
                'status' => $paymentProcessed ? 'confirmed' : 'pending_payment'
            ]);
            
            // Mark cart as checked out
            $dbCart = $this->cartService->getOrCreateCart();
            $this->cartService->markAsCheckedOut($dbCart);
            
            // Send confirmation email to customer
            Mail::send(new CheckoutConfirmationMail($booking));
            
            // Send notification email to admin (optional)
            // Mail::send(new BookingAdminNotificationMail($booking));
            
            DB::commit();
            
            return redirect()->route('checkout.success', [
                'type' => 'payment',
                'reference' => $booking->reference,
                'method' => $paymentMethod,
                'status' => $booking->status
            ])->with('success', 'Your booking has been processed successfully!');
            
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
        $booking = Booking::where('reference', $reference)->first();
        
        return view('checkout.success', compact('type', 'reference', 'method', 'status', 'booking'));
    }
    
    /**
     * PayPal callback handlers
     */
    public function paypalSuccess(Request $request)
    {
        $bookingId = session()->get('pending_booking_id');
        
        if (!$bookingId) {
            return redirect()->route('cart')->with('error', 'No pending booking found.');
        }
        
        $booking = Booking::find($bookingId);
        
        if (!$booking) {
            return redirect()->route('cart')->with('error', 'Booking not found.');
        }
        
        // Verify PayPal payment (in real implementation)
        return $this->completeBooking($booking, 'paypal', true);
    }
    
    public function paypalCancel(Request $request)
    {
        return redirect()->route('checkout')
            ->with('error', 'Payment was cancelled. Please try again.');
    }
    
    /**
     * Generate unique booking reference
     */
    protected function generateBookingReference(): string
    {
        return 'TXT-' . strtoupper(Str::random(8)) . '-' . date('Ymd');
    }
}
