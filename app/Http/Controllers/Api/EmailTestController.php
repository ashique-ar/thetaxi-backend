<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\VehicleGroup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailTestController
{
    /**
     * Test Checkout/Booking Confirmation Email
     */
    public function testCheckoutConfirmation(): View
    {
        // Create mock booking data
        $booking = (object) [
            'booking_number' => 'BK' . date('YmdHis'),
            'customer' => (object) [
                'full_name' => 'John Doe',
                'email' => 'john@example.com',
                'phone' => '+94 71 234 5678',
            ],
            'from_date' => Carbon::now()->addDay()->setHour(9),
            'to_date' => Carbon::now()->addDays(4)->setHour(9),
            'pickup_location' => json_encode(['address' => 'Colombo Airport, Sri Lanka']),
            'dropoff_location' => json_encode(['address' => 'Galle Face Hotel, Colombo']),
            'currency' => 'LKR',
            'payment_status' => 'paid',
            'payment_type' => 'full',
            'payment_method' => 'online',
            'status' => 'confirmed',
            'base_amount' => 15000,
            'service_fee' => 500,
            'tax_amount' => 450,
            'vat_amount' => 3000,
            'discount_amount' => 1000,
            'total_estimated' => 17950,
            'amount_to_pay' => 17950,
            'special_requirements' => 'Airport pickup, child seat required',
            'workflow_data' => json_encode([
                'cart_items' => [
                    ['name' => 'Toyota Prius', 'days' => 4, 'price' => 3750],
                    ['name' => 'Fuel Surcharge', 'days' => 1, 'price' => 500],
                ],
                'flight_details' => [
                    'airline' => 'SriLankan Airlines',
                    'flight_number' => 'UL504',
                    'arrival_date' => '2026-01-10',
                    'arrival_time' => '14:30',
                ],
            ]),
        ];

        return view('emails.checkout-confirmation', [
            'booking' => $booking,
        ]);
    }

    /**
     * Test General/Information Email
     */
    public function testGeneral(): View
    {
        $companyName = env('COMPANY_NAME', 'Casons Rent A Car');
        $message = "Thank you for choosing {$companyName}!\n\nWe are excited to serve you with our premium car rental services. If you have any questions, please don't hesitate to contact us.\n\nBest regards,\n{$companyName} Team";

        return view('emails.general', [
            'message' => $message,
        ]);
    }

    /**
     * Test Inquiry Confirmation Email
     */
    public function testInquiryConfirmation(): View
    {
        $inquiry = (object) [
            'id' => 'INQ' . date('YmdHis'),
            'email' => 'inquiry@example.com',
            'phone' => '+94 71 987 6543',
            'message' => 'I am interested in renting a vehicle for my family trip.',
            'created_at' => Carbon::now(),
            'payload' => [
                'form' => [
                    'company_name' => 'ABC Corporation',
                    'contact_person' => 'Jane Smith',
                    'name' => 'Jane Smith',
                    'country' => 'United States',
                    'pickup_location' => 'Colombo International Airport',
                    'dropoff_location' => 'Kandy City Center',
                    'travel_date' => '2026-02-15',
                    'travel_time' => '14:00',
                    'passengers' => '5',
                    'requirements' => 'Air conditioning, GPS navigation',
                    'message' => 'Please provide rates for a 5-day rental.',
                ],
            ],
        ];

        return view('emails.inquiry-confirmation', [
            'contactName' => 'Jane Smith',
            'typeLabel' => 'General Inquiry',
            'intro' => 'Thank you for contacting us. We have received your inquiry and our team will get back to you soon.',
            'supportEmail' => 'support@casonsrentacar.lk',
            'supportPhone' => '+94 11 234 5678',
            'inquiry' => $inquiry,
        ]);
    }

    /**
     * Test Payment Initiated Email
     */
    public function testPaymentInitiated(): View
    {
        $booking = (object) [
            'booking_number' => 'BK' . date('YmdHis'),
            'customer' => (object) [
                'full_name' => 'Ahmed Hassan',
                'email' => 'ahmed@example.com',
                'phone' => '+94 71 111 2222',
            ],
            'currency' => 'LKR',
            'payment_type' => 'advance',
            'payment_method' => 'online_banking',
            'status' => 'pending_payment',
        ];

        return view('emails.payment-initiated', [
            'booking' => $booking,
            'amount' => 8975.00,
            'supportEmail' => 'payments@casonsrentacar.lk',
            'supportPhone' => '+94 11 234 5678',
        ]);
    }

    /**
     * Test Quotation Request Confirmation Email (Customer View)
     */
    public function testQuotationRequestConfirmation(): View
    {
        $vehicleGroup = (object) [
            'name' => 'Premium SUV',
            'description' => 'Luxury SUV for corporate and leisure travel',
        ];

        $inquiry = (object) [
            'created_at' => Carbon::now(),
        ];

        return view('emails.quotation-request-confirmation', [
            'customerName' => 'Robert Wilson',
            'inquiryNumber' => 'QR' . date('YmdHis'),
            'vehicleGroup' => $vehicleGroup,
            'inquiry' => $inquiry,
            'estimatedResponseTime' => 'Within 24 hours',
            'supportEmail' => 'quotations@casonsrentacar.lk',
            'supportPhone' => '+94 11 234 5678',
        ]);
    }

    /**
     * Test Quotation Request Notification Email (Internal Staff)
     */
    public function testQuotationRequestNotification(): View
    {
        $vehicleGroup = (object) [
            'name' => 'Premium SUV',
        ];

        $inquiry = (object) [
            'id' => 'INQ' . date('YmdHis'),
            'created_at' => Carbon::now(),
        ];

        return view('emails.quotation-request-notification', [
            'inquiry' => $inquiry,
            'vehicleGroup' => $vehicleGroup,
            'customerName' => 'Robert Wilson',
            'customerEmail' => 'robert@example.com',
            'customerPhone' => '+94 71 555 4444',
            'companyName' => 'Wilson Enterprises',
            'serviceType' => 'Corporate Transport',
            'travelDate' => '2026-02-20',
            'travelTime' => '10:00 AM',
            'pickupLocation' => 'Colombo Business Park',
            'dropoffLocation' => 'Kandy Convention Center',
            'passengers' => '8',
            'specialRequirements' => 'WiFi enabled, mineral water provided',
        ]);
    }

    /**
     * Test Quotation Request Email
     */
    public function testQuotationRequest(): View
    {
        $booking = (object) [
            'booking_number' => 'BK' . date('YmdHis'),
            'customer' => (object) [
                'full_name' => 'Michael Brown',
                'email' => 'michael@example.com',
                'phone' => '+94 71 666 7777',
            ],
            'from_date' => Carbon::now()->addDays(5)->setHour(10),
            'to_date' => Carbon::now()->addDays(8)->setHour(10),
            'pickup_location' => json_encode(['address' => 'Hotel Mount Lavinia, Colombo']),
            'contact_time' => 'morning',
            'status' => 'quotation_requested',
            'special_requirements' => 'English speaking driver preferred, sightseeing tour guide needed',
            'workflow_data' => json_encode([
                'cart_items' => [
                    ['name' => 'Toyota Highlander', 'days' => 3, 'price' => 5000],
                ],
                'budget_range' => '1000-2000',
                'flight_details' => [],
            ]),
        ];

        return view('emails.quotation-request', [
            'booking' => $booking,
        ]);
    }

    /**
     * List all test email routes
     */
    public function listAll()
    {
        $routes = [
            [
                'name' => 'Checkout/Booking Confirmation',
                'url' => '/api/test-emails/checkout-confirmation',
                'description' => 'Tests the booking confirmation email with full booking details and payment status',
            ],
            [
                'name' => 'General/Information Email',
                'url' => '/api/test-emails/general',
                'description' => 'Tests the general purpose email template',
            ],
            [
                'name' => 'Inquiry Confirmation',
                'url' => '/api/test-emails/inquiry-confirmation',
                'description' => 'Tests the inquiry confirmation email sent to customers',
            ],
            [
                'name' => 'Payment Initiated',
                'url' => '/api/test-emails/payment-initiated',
                'description' => 'Tests the payment initiation email with payment details',
            ],
            [
                'name' => 'Quotation Request Confirmation (Customer)',
                'url' => '/api/test-emails/quotation-request-confirmation',
                'description' => 'Tests the quotation confirmation email sent to customers',
            ],
            [
                'name' => 'Quotation Request Notification (Staff)',
                'url' => '/api/test-emails/quotation-request-notification',
                'description' => 'Tests the internal notification email for staff',
            ],
            [
                'name' => 'Quotation Request',
                'url' => '/api/test-emails/quotation-request',
                'description' => 'Tests the quotation request details email',
            ],
        ];

        return response()->json([
            'message' => 'Email Test Routes',
            'routes' => $routes,
            'notes' => [
                'All routes return HTML preview of the email',
                'Sample data is used for testing',
                'Access directly in browser or via API',
            ],
        ]);
    }
}
