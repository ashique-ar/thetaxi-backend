<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleGroup;
use App\Services\BookingFlowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    protected $bookingFlowService;
    
    public function __construct(BookingFlowService $bookingFlowService)
    {
        $this->bookingFlowService = $bookingFlowService;
    }
    
    /**
     * Display checkout page
     */
    public function index(Request $request)
    {
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return redirect()->route('cart')->with('error', 'Your cart is empty.');
        }
        
        $paymentType = $request->get('type', 'full');
        
        // Validate payment type
        if (!in_array($paymentType, ['full', 'advance', 'quotation'])) {
            $paymentType = 'full';
        }
        
        return view('checkout', compact('cart', 'paymentType'));
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
            'special_notes' => 'nullable|string|max:1000',
            'contact_time' => 'nullable|string|in:morning,afternoon,evening,anytime',
            'budget_range' => 'nullable|string|in:under-500,500-1000,1000-2000,over-2000',
            'payment_method' => 'required_unless:payment_type,quotation|in:paypal,stripe,bank_transfer',
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
            
            // Generate booking reference
            $bookingReference = $this->generateBookingReference();
            
            // Create booking data
            $bookingData = [
                'reference' => $bookingReference,
                'customer_info' => [
                    'full_name' => $validated['full_name'],
                    'phone' => $validated['phone'],
                    'email' => $validated['email'],
                    'identification' => $validated['identification'],
                    'address' => $validated['address'],
                    'city' => $validated['city']
                ],
                'payment_info' => [
                    'payment_type' => $validated['payment_type'],
                    'payment_method' => $validated['payment_method'] ?? null,
                    'subtotal' => $subtotal,
                    'service_fee' => $serviceFee,
                    'tax' => $tax,
                    'discount' => $discount,
                    'total' => $total,
                    'payment_amount' => $paymentAmount,
                    'currency' => 'USD'
                ],
                'booking_details' => [
                    'special_notes' => $validated['special_notes'],
                    'contact_time' => $validated['contact_time'] ?? null,
                    'budget_range' => $validated['budget_range'] ?? null,
                    'cart_items' => $cart
                ],
                'created_at' => now()
            ];
            
            // Handle different payment types
            switch ($validated['payment_type']) {
                case 'quotation':
                    return $this->processQuotationRequest($bookingData, $validated);
                    
                case 'advance':
                case 'full':
                    return $this->processPayment($bookingData, $validated);
                    
                default:
                    throw new \Exception('Invalid payment type');
            }
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Checkout processing error', [
                'error' => $e->getMessage(),
                'request_data' => $validated
            ]);
            
            return redirect()->back()
                ->withInput()
                ->with('error', 'An error occurred while processing your booking. Please try again.');
        }
    }
    
    /**
     * Process quotation request
     */
    protected function processQuotationRequest(array $bookingData, array $validated)
    {
        try {
            // Store quotation request in session or database
            session()->put('quotation_request', $bookingData);
            
            // Here you would typically:
            // 1. Save to quotations table
            // 2. Send notification to admin
            // 3. Send confirmation email to customer
            
            // Clear cart
            session()->forget(['cart', 'cart_discount', 'applied_coupon']);
            
            DB::commit();
            
            return redirect()->route('checkout.success', [
                'type' => 'quotation',
                'reference' => $bookingData['reference']
            ])->with('success', 'Your quotation request has been submitted successfully!');
            
        } catch (\Exception $e) {
            throw new \Exception('Failed to process quotation request: ' . $e->getMessage());
        }
    }
    
    /**
     * Process payment
     */
    protected function processPayment(array $bookingData, array $validated)
    {
        try {
            $paymentMethod = $validated['payment_method'];
            $paymentAmount = $bookingData['payment_info']['payment_amount'];
            
            // Process payment based on method
            switch ($paymentMethod) {
                case 'paypal':
                    return $this->processPayPalPayment($bookingData, $paymentAmount);
                    
                case 'stripe':
                    return $this->processStripePayment($bookingData, $validated, $paymentAmount);
                    
                case 'bank_transfer':
                    return $this->processBankTransfer($bookingData);
                    
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
    protected function processPayPalPayment(array $bookingData, float $amount)
    {
        // Store booking data for PayPal callback
        session()->put('pending_booking', $bookingData);
        
        // In a real implementation, you would redirect to PayPal
        // For now, we'll simulate successful payment
        
        return $this->completePendingBooking($bookingData['reference'], 'paypal', 'completed');
    }
    
    /**
     * Process Stripe payment
     */
    protected function processStripePayment(array $bookingData, array $validated, float $amount)
    {
        // In a real implementation, you would integrate with Stripe API
        // For now, we'll simulate the payment process
        
        $cardNumber = str_replace(' ', '', $validated['card_number']);
        
        // Basic card validation (in real implementation, use Stripe's validation)
        if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            throw new \Exception('Invalid card number');
        }
        
        // Simulate payment processing
        $paymentSuccessful = true; // In real implementation, this would come from Stripe
        
        if ($paymentSuccessful) {
            return $this->completePendingBooking(
                $bookingData['reference'], 
                'stripe', 
                'completed',
                ['last4' => substr($cardNumber, -4)]
            );
        } else {
            throw new \Exception('Payment failed. Please check your card details.');
        }
    }
    
    /**
     * Process bank transfer
     */
    protected function processBankTransfer(array $bookingData)
    {
        // For bank transfer, booking is pending until payment confirmation
        return $this->completePendingBooking(
            $bookingData['reference'], 
            'bank_transfer', 
            'pending_payment'
        );
    }
    
    /**
     * Complete pending booking
     */
    protected function completePendingBooking(string $reference, string $paymentMethod, string $status, array $paymentDetails = [])
    {
        try {
            // Here you would typically:
            // 1. Create booking record in database using BookingFlowService
            // 2. Create customer record if doesn't exist
            // 3. Update vehicle availability
            // 4. Send confirmation emails
            // 5. Generate booking confirmation
            
            // For now, we'll simulate this process
            
            // Clear cart and related session data
            session()->forget(['cart', 'cart_discount', 'applied_coupon', 'pending_booking']);
            
            DB::commit();
            
            return redirect()->route('checkout.success', [
                'type' => 'payment',
                'reference' => $reference,
                'method' => $paymentMethod,
                'status' => $status
            ])->with('success', 'Your booking has been processed successfully!');
            
        } catch (\Exception $e) {
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
        
        return view('checkout.success', compact('type', 'reference', 'method', 'status'));
    }
    
    /**
     * PayPal callback handlers
     */
    public function paypalSuccess(Request $request)
    {
        $pendingBooking = session()->get('pending_booking');
        
        if (!$pendingBooking) {
            return redirect()->route('cart')->with('error', 'No pending booking found.');
        }
        
        // Verify PayPal payment (in real implementation)
        // For now, we'll assume payment was successful
        
        return $this->completePendingBooking(
            $pendingBooking['reference'], 
            'paypal', 
            'completed'
        );
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
