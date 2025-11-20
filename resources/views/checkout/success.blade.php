@extends('layouts.app')

@section('title', 'Booking Confirmation - TheTaxi')

@section('content')
    @php
        $isQuotation = $type === 'quotation';
        $isPaid = $booking && $booking->payment_status === 'paid';
        $isPending = $booking && $booking->payment_status === 'pending';
        $currencySymbol = $booking ? getCurrencySymbol($booking->currency) : '$';
    @endphp
    <!-- Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg1.jpg') }});">
        <div class="container">
            <div class="banner-content">
                @if ($isQuotation)
                    {{-- <div class="success-icon quotation">
                        <i class="bi bi-file-text-fill"></i>
                    </div> --}}
                    <h1>Quotation Request Submitted!</h1>
                    <p class="lead">Thank you for your interest. Our team will review your request and send you a detailed
                        quotation within 24 hours.</p>
                @elseif($isPaid)
                    {{-- <div class="success-icon paid">
                        <i class="bi bi-check-circle-fill"></i>
                    </div> --}}
                    <h1>Payment Successful!</h1>
                    <p class="lead">Your booking has been confirmed. You will receive a confirmation email shortly.</p>
                @else
                    {{-- <div class="success-icon pending">
                        <i class="bi bi-clock-fill"></i>
                    </div> --}}
                    <h1>Booking Received!</h1>
                    <p class="lead">Your booking has been received. Please complete the payment to confirm your
                        reservation.</p>
                @endif
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->



    <!-- Success Page Start-->
    <div class="checkout-success pt-100 mb-100">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <!-- Success Message -->
                    {{-- <div class="success-message text-center mb-5">
                        @if ($isQuotation)
                            <div class="success-icon quotation">
                                <i class="bi bi-file-text-fill"></i>
                            </div>
                            <h2>Quotation Request Submitted!</h2>
                            <p class="lead">Thank you for your interest. Our team will review your request and send you a
                                detailed quotation within 24 hours.</p>
                        @elseif($isPaid)
                            <div class="success-icon paid">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                            <h2>Payment Successful!</h2>
                            <p class="lead">Your booking has been confirmed. You will receive a confirmation email
                                shortly.</p>
                        @else
                            <div class="success-icon pending">
                                <i class="bi bi-clock-fill"></i>
                            </div>
                            <h2>Booking Received!</h2>
                            <p class="lead">Your booking has been received. Please complete the payment to confirm your
                                reservation.</p>
                        @endif
                    </div> --}}

                    @if ($booking)
                        <!-- Booking Receipt -->
                        <div class="booking-receipt card shadow-lg">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="mb-0"><i class="bi bi-receipt"></i> Booking Receipt</h4>
                                    {{-- <button onclick="window.print()" class="btn btn-light btn-sm">
                                <i class="bi bi-printer"></i> Print Receipt
                            </button> --}}
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Booking Reference -->
                                <div class="receipt-section">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h5>Booking Reference</h5>
                                            <p class="booking-ref">{{ $booking->booking_number }}</p>
                                            <p class="text-muted small">Please keep this reference number for your records
                                            </p>
                                        </div>
                                        <div class="col-md-6 text-md-end">
                                            <h5>Booking Date</h5>
                                            <p>{{ $booking->created_at->format('M d, Y H:i A') }}</p>
                                            <p
                                                class="badge 
                                        @if ($isPaid) bg-success
                                        @elseif($isPending) bg-warning text-dark
                                        @elseif($isQuotation) bg-info
                                        @else bg-secondary @endif">
                                                {{ ucfirst(str_replace('_', ' ', $booking->status)) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <hr>

                                <!-- Customer Information -->
                                <div class="receipt-section">
                                    <h5><i class="bi bi-person-fill"></i> Customer Information</h5>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Name:</strong> {{ $booking->customer?->user?->full_name ?? 'N/A' }}
                                            </p>
                                            <p><strong>Email:</strong> {{ $booking->customer?->user?->email ?? 'N/A' }}</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Phone:</strong> {{ $booking->customer?->user?->phone ?? 'N/A' }}</p>
                                            @if ($booking->customer->identification ?? null)
                                                <p><strong>ID/Passport:</strong> {{ $booking->customer->identification }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                <hr>

                                <!-- Booking Details -->
                                <div class="receipt-section">
                                    <h5><i class="bi bi-calendar-check"></i> Booking Details</h5>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Pickup Date:</strong>
                                                {{ \Carbon\Carbon::parse($booking->from_date)->format('M d, Y H:i A') }}
                                            </p>
                                            <p><strong>Pickup Location:</strong>
                                                @php
                                                    $pickupLocation = is_string($booking->pickup_location)
                                                        ? json_decode($booking->pickup_location, true)
                                                        : $booking->pickup_location;
                                                @endphp
                                                {{ $pickupLocation['address'] ?? 'N/A' }}
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Return Date:</strong>
                                                {{ \Carbon\Carbon::parse($booking->to_date)->format('M d, Y H:i A') }}</p>
                                            <p><strong>Return Location:</strong>
                                                @php
                                                    $dropoffLocation = is_string($booking->dropoff_location)
                                                        ? json_decode($booking->dropoff_location, true)
                                                        : $booking->dropoff_location;
                                                @endphp
                                                {{ $dropoffLocation['address'] ?? 'N/A' }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <hr>

                                <!-- Payment Summary -->
                                <div class="receipt-section">
                                    <h5><i class="bi bi-credit-card"></i> Payment Summary</h5>
                                    <div class="payment-breakdown">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Subtotal:</span>
                                            <span>{{ $currencySymbol }}{{ number_format($booking->base_amount, 2) }}</span>
                                        </div>
                                        @if ($booking->service_fee > 0)
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>Service Fee:</span>
                                                <span>{{ $currencySymbol }}{{ number_format($booking->service_fee, 2) }}</span>
                                            </div>
                                        @endif
                                        @if ($booking->tax_amount > 0)
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>{{ config('booking.tax.label', 'NBT') }}
                                                    ({{ config('booking.tax.rate', 2.5) }}%):</span>
                                                <span>{{ $currencySymbol }}{{ number_format($booking->tax_amount, 2) }}</span>
                                            </div>
                                        @endif
                                        @if (($booking->vat_amount ?? 0) > 0)
                                            <div class="d-flex justify-content-between mb-2">
                                                <span>{{ config('booking.vat.label', 'VAT') }}
                                                    ({{ config('booking.vat.rate', 18) }}%):</span>
                                                <span>{{ $currencySymbol }}{{ number_format($booking->vat_amount, 2) }}</span>
                                            </div>
                                        @endif
                                        @if ($booking->discount_amount > 0)
                                            <div class="d-flex justify-content-between mb-2 text-success">
                                                <span>Discount:</span>
                                                <span>-{{ $currencySymbol }}{{ number_format($booking->discount_amount, 2) }}</span>
                                            </div>
                                        @endif
                                        <hr>
                                        <div class="d-flex justify-content-between mb-3">
                                            <strong>Total Amount:</strong>
                                            <strong
                                                class="text-primary fs-5">{{ $currencySymbol }}{{ number_format($booking->total_estimated, 2) }}</strong>
                                        </div>

                                        @if ($booking->payment_type === 'advance')
                                            <div class="alert alert-info mb-3">
                                                <div class="d-flex justify-content-between">
                                                    <span><strong>Amount Paid
                                                            ({{ config('booking.advance_payment.percentage', 50) }}%):</strong></span>
                                                    <strong>{{ $currencySymbol }}{{ number_format($booking->amount_to_pay ?? 0, 2) }}</strong>
                                                </div>
                                                <div class="d-flex justify-content-between mt-2">
                                                    <span>Balance Due at Pickup:</span>
                                                    <span>{{ $currencySymbol }}{{ number_format($booking->total_estimated - ($booking->amount_to_pay ?? 0), 2) }}</span>
                                                </div>
                                            </div>
                                        @endif

                                        <!-- Payment Method -->
                                        @if ($booking->payment_method)
                                            <div class="mt-3">
                                                <p><strong>Payment Method:</strong>
                                                    @switch($booking->payment_method)
                                                        @case('online')
                                                            Online Payment
                                                        @break

                                                        @case('bank_transfer')
                                                            Bank Transfer
                                                        @break

                                                        @case('online_banking')
                                                            Online Banking
                                                        @break

                                                        @default
                                                            {{ ucfirst(str_replace('_', ' ', $booking->payment_method)) }}
                                                    @endswitch
                                                </p>
                                                @if ($isPaid && $booking->payment_gateway_transaction_id)
                                                    <p><strong>Transaction ID:</strong>
                                                        {{ $booking->payment_gateway_transaction_id }}</p>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                @if ($isPending && ($booking->payment_method === 'bank_transfer' || $booking->payment_method === 'online_banking'))
                                    <hr>
                                    <!-- Bank Transfer Instructions -->
                                    <div class="receipt-section">
                                        <h5><i class="bi bi-bank"></i> Bank Transfer Instructions</h5>
                                        <div class="alert alert-warning">
                                            <p class="mb-2"><strong>Please transfer the payment to:</strong></p>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p><strong>Account Name:</strong> Casons Rent A Car (Pvt) Ltd</p>
                                                    <p><strong>Bank:</strong> Commercial Bank of Ceylon PLC</p>
                                                    <p><strong>Account No:</strong> 1234567890</p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><strong>Branch:</strong> Colombo Main Branch</p>
                                                    <p><strong>SWIFT Code:</strong> CCEYLKLX</p>
                                                    <p><strong>Reference:</strong> <span
                                                            class="text-danger">{{ $booking->booking_number }}</span></p>
                                                </div>
                                            </div>
                                            <hr>
                                            <p class="mb-0 small"><i class="bi bi-info-circle"></i>
                                                <strong>Important:</strong> Please email your payment receipt to: <a
                                                    href="mailto:payments@casonsrentacar.lk">payments@casonsrentacar.lk</a>
                                                with the booking reference in the subject line.
                                            </p>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- Next Steps -->
                    <div class="next-steps mt-5">
                        <h4 class="mb-4 text-center">What's Next?</h4>
                        <div class="row">
                            @if ($isQuotation)
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">1</div>
                                        <h5>Wait for Quotation</h5>
                                        <p>Our team will review your request and prepare a detailed quotation.</p>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">2</div>
                                        <h5>Review & Approve</h5>
                                        <p>You'll receive the quotation via email within 24 hours for your review.</p>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">3</div>
                                        <h5>Confirm Booking</h5>
                                        <p>Once approved, we'll process your booking and send confirmation.</p>
                                    </div>
                                </div>
                            @elseif($isPending)
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">1</div>
                                        <h5>Complete Payment</h5>
                                        <p>Transfer the payment using the bank details provided above.</p>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">2</div>
                                        <h5>Payment Verification</h5>
                                        <p>Our team will verify your payment within 2-4 business hours.</p>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">3</div>
                                        <h5>Booking Confirmation</h5>
                                        <p>You'll receive a confirmation email once payment is verified.</p>
                                    </div>
                                </div>
                            @else
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">1</div>
                                        <h5>Check Your Email</h5>
                                        <p>A confirmation email has been sent to
                                            {{ $booking->customer->email ?? 'your email' }}.</p>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">2</div>
                                        <h5>Prepare Documents</h5>
                                        <p>Bring your ID/Passport and driver's license on the pickup date.</p>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="step-card">
                                        <div class="step-number">3</div>
                                        <h5>Enjoy Your Ride</h5>
                                        <p>Arrive at the pickup location and start your journey!</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons text-center mt-5">
                        <a href="{{ route('home') }}" class="primary-btn1 me-3">
                            <span>Back to Home</span>
                        </a>
                        @if ($isPending && ($booking->payment_method === 'bank_transfer' || $booking->payment_method === 'online_banking'))
                            <a href="mailto:payments@casonsrentacar.lk?subject=Payment Receipt - {{ $booking->booking_number }}"
                                class="primary-btn1 btn-outline">
                                <span><i class="bi bi-envelope"></i> Email Payment Receipt</span>
                            </a>
                        @endif
                    </div>

                    <!-- Contact Information -->
                    <div class="contact-info mt-5 p-4 bg-light rounded">
                        <h6>Need Help?</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <p><i class="bi bi-telephone"></i> <strong>Phone:</strong><br>
                                    <a href="tel:+94112345678">+94 11 234 5678</a>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <p><i class="bi bi-envelope"></i> <strong>Email:</strong><br>
                                    <a href="mailto:bookings@thetaxi.com">bookings@thetaxi.com</a>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <p><i class="bi bi-whatsapp"></i> <strong>WhatsApp:</strong><br>
                                    <a href="https://wa.me/94712345678">+94 71 234 5678</a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
    <!--Success Page End-->
@endsection

@push('styles')
    <style>
        .success-message {
            padding: 40px 20px;
        }

        .success-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            animation: bounceIn 0.8s ease-out;
        }

        .success-icon.paid {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .success-icon.pending {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }

        .success-icon.quotation {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
        }

        @keyframes bounceIn {
            0% {
                opacity: 0;
                transform: scale(0.3);
            }

            50% {
                opacity: 1;
                transform: scale(1.05);
            }

            70% {
                transform: scale(0.9);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        .success-message h2 {
            color: #333;
            margin-bottom: 15px;
        }

        .success-message .lead {
            color: #666;
            font-size: 18px;
        }

        .booking-receipt {
            margin-bottom: 30px;
        }

        .booking-receipt .card-header {
            background: var(--primary-color1) !important;
            color: white;
            padding: 20px;
        }

        .booking-receipt .card-body {
            padding: 30px;
        }

        .receipt-section {
            margin-bottom: 25px;
        }

        .receipt-section h5 {
            color: var(--primary-color1);
            margin-bottom: 15px;
            font-weight: 600;
        }

        .booking-ref {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-color1);
            letter-spacing: 2px;
            margin-bottom: 5px;
        }

        .payment-breakdown {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
        }

        .step-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 30px 20px;
            text-align: center;
            height: 100%;
            transition: all 0.3s ease;
        }

        .step-card:hover {
            border-color: var(--primary-color1);
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .step-number {
            width: 50px;
            height: 50px;
            background: var(--primary-color1);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            margin: 0 auto 20px;
        }

        .step-card h5 {
            color: #333;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .step-card p {
            color: #666;
            font-size: 14px;
            margin: 0;
        }

        .btn-outline {
            background: transparent !important;
            color: var(--primary-color1) !important;
            border: 2px solid var(--primary-color1) !important;
        }

        .btn-outline:hover {
            background: var(--primary-color1) !important;
            color: white !important;
        }

        .contact-info {
            border: 1px solid #ddd;
        }

        .contact-info a {
            color: var(--primary-color1);
            text-decoration: none;
        }

        .contact-info a:hover {
            text-decoration: underline;
        }

        @media print {

            .breadcrumb-section,
            .next-steps,
            .action-buttons,
            .contact-info,
            .btn {
                display: none !important;
            }

            .booking-receipt {
                box-shadow: none;
                border: 2px solid #000;
            }

            .booking-receipt .card-header {
                background: #333 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media (max-width: 768px) {
            .success-icon {
                width: 80px;
                height: 80px;
                font-size: 40px;
            }

            .success-message h2 {
                font-size: 24px;
            }

            .booking-receipt .card-body {
                padding: 20px;
            }

            .action-buttons .primary-btn1 {
                display: inline-block;
                margin-bottom: 10px;
            }
        }
    </style>
@endpush

@push('scripts')
    <script>
        $(document).ready(function() {
            // Copy booking reference to clipboard on click
            $('.booking-ref').on('click', function() {
                const referenceNumber = $(this).text().trim();

                if (navigator.clipboard) {
                    navigator.clipboard.writeText(referenceNumber).then(function() {
                        // Show temporary tooltip
                        const $ref = $('.booking-ref');
                        const originalText = $ref.text();
                        $ref.html('<i class="bi bi-check-circle-fill text-success"></i> Copied!');

                        setTimeout(function() {
                            $ref.text(originalText);
                        }, 2000);
                    }).catch(function(err) {
                        console.error('Failed to copy:', err);
                    });
                }
            });

            // Add cursor pointer and title to booking reference
            $('.booking-ref').css('cursor', 'pointer').attr('title', 'Click to copy reference number');
        });
    </script>
@endpush
