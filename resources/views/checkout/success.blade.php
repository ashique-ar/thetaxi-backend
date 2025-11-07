@extends('layouts.app')

@section('title', 'Booking Confirmation - TheTaxi')

@section('content')
<!-- Breadcrumb section -->
<div class="breadcrumb-section" style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ asset('assets/img/innerpages/breadcrumb-bg1.jpg') }});">  
    <div class="container">
        <div class="banner-content">
            <h1>Booking Confirmation</h1>
            <ul class="breadcrumb-list">
                <li><a href="{{ route('home') }}">Home</a></li>
                <li><a href="{{ route('cart') }}">Cart</a></li>
                <li><a href="{{ route('checkout') }}">Checkout</a></li>
                <li>Confirmation</li>
            </ul>
        </div>
    </div>
</div>
<!-- End Breadcrumb section -->

<!-- Success Page Start-->
<div class="checkout-success pt-100 mb-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="success-content text-center">
                    @if($type === 'quotation')
                        <!-- Quotation Request Success -->
                        <div class="success-icon mb-4">
                            <i class="bi bi-file-text-fill text-primary" style="font-size: 4rem;"></i>
                        </div>
                        <h2 class="mb-3">Quotation Request Submitted!</h2>
                        <p class="lead mb-4">Thank you for your quotation request. We have received your inquiry and our team will contact you soon with detailed pricing and booking information.</p>
                        
                        <div class="alert alert-info">
                            <h5><i class="bi bi-info-circle"></i> What's Next?</h5>
                            <ul class="text-start mt-3">
                                <li>Our team will review your requirements within 2-4 business hours</li>
                                <li>You will receive a detailed quotation via email</li>
                                <li>We'll contact you at your preferred time to discuss the booking</li>
                                <li>Once approved, we'll send you a secure payment link</li>
                            </ul>
                        </div>
                        
                    @else
                        <!-- Payment Success -->
                        @if($status === 'completed')
                            <div class="success-icon mb-4">
                                <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                            </div>
                            <h2 class="mb-3">Booking Confirmed!</h2>
                            <p class="lead mb-4">Your payment has been processed successfully and your vehicle rental booking is confirmed.</p>
                        @elseif($status === 'pending_payment')
                            <div class="success-icon mb-4">
                                <i class="bi bi-clock-fill text-warning" style="font-size: 4rem;"></i>
                            </div>
                            <h2 class="mb-3">Booking Received!</h2>
                            <p class="lead mb-4">Your booking has been received. Please complete the bank transfer to confirm your reservation.</p>
                            
                            <div class="alert alert-warning">
                                <h5><i class="bi bi-bank"></i> Bank Transfer Details</h5>
                                <div class="row mt-3">
                                    <div class="col-md-6 text-start">
                                        <p><strong>Account Name:</strong> Casons Rent A Car</p>
                                        <p><strong>Bank:</strong> Commercial Bank of Ceylon</p>
                                    </div>
                                    <div class="col-md-6 text-start">
                                        <p><strong>Account No:</strong> 12345678901</p>
                                        <p><strong>Branch Code:</strong> 001</p>
                                    </div>
                                </div>
                                <p class="mb-0"><strong>Reference:</strong> {{ $reference }}</p>
                                <small>Please use your booking reference as the transfer description.</small>
                            </div>
                        @endif
                        
                        @if(isset($method))
                            <div class="payment-method-info mb-4">
                                <p><strong>Payment Method:</strong> 
                                    @switch($method)
                                        @case('paypal')
                                            PayPal
                                            @break
                                        @case('stripe')
                                            Credit/Debit Card
                                            @break
                                        @case('bank_transfer')
                                            Bank Transfer
                                            @break
                                        @default
                                            {{ ucfirst($method) }}
                                    @endswitch
                                </p>
                            </div>
                        @endif
                    @endif
                    
                    <!-- Booking Reference -->
                    <div class="booking-reference mb-4">
                        <h4>Booking Reference</h4>
                        <div class="reference-code p-3 bg-light border rounded">
                            <h3 class="text-primary mb-0">{{ $reference }}</h3>
                            <small class="text-muted">Keep this reference number for your records</small>
                        </div>
                    </div>
                    
                    <!-- Next Steps -->
                    <div class="next-steps mb-5">
                        <h5>What happens next?</h5>
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="step-item p-3">
                                    <i class="bi bi-envelope-fill text-primary mb-2" style="font-size: 2rem;"></i>
                                    <h6>Confirmation Email</h6>
                                    <p class="small">You'll receive a detailed confirmation email within 5 minutes</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="step-item p-3">
                                    <i class="bi bi-telephone-fill text-primary mb-2" style="font-size: 2rem;"></i>
                                    <h6>Contact Verification</h6>
                                    <p class="small">Our team will contact you 24-48 hours before pickup</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="step-item p-3">
                                    <i class="bi bi-car-front-fill text-primary mb-2" style="font-size: 2rem;"></i>
                                    <h6>Vehicle Preparation</h6>
                                    <p class="small">Your vehicle will be prepared and ready at the scheduled time</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        @if($type === 'quotation')
                            <a href="{{ route('home') }}" class="primary-btn1 me-3">
                                <span>Back to Home</span>
                            </a>
                            <a href="{{ route('search') }}" class="primary-btn1 btn-outline">
                                <span>Browse More Vehicles</span>
                            </a>
                        @else
                            <a href="{{ route('home') }}" class="primary-btn1 me-3">
                                <span>Back to Home</span>
                            </a>
                            <button class="primary-btn1 btn-outline" onclick="window.print()">
                                <span><i class="bi bi-printer"></i> Print Confirmation</span>
                            </button>
                        @endif
                    </div>
                    
                    <!-- Contact Information -->
                    <div class="contact-info mt-5 p-4 bg-light rounded">
                        <h6>Need Help?</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <p><i class="bi bi-telephone"></i> <strong>Phone:</strong><br>
                                <a href="tel:+94112345678">+94 11 234 5678</a></p>
                            </div>
                            <div class="col-md-4">
                                <p><i class="bi bi-envelope"></i> <strong>Email:</strong><br>
                                <a href="mailto:bookings@thetaxi.com">bookings@thetaxi.com</a></p>
                            </div>
                            <div class="col-md-4">
                                <p><i class="bi bi-whatsapp"></i> <strong>WhatsApp:</strong><br>
                                <a href="https://wa.me/94712345678">+94 71 234 5678</a></p>
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
.success-content {
    padding: 40px 20px;
}

.success-icon {
    animation: bounceIn 0.8s ease-out;
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

.reference-code {
    border: 2px dashed var(--primary-color1) !important;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%) !important;
}

.step-item {
    border: 1px solid #eee;
    border-radius: 8px;
    height: 100%;
    transition: all 0.3s ease;
}

.step-item:hover {
    border-color: var(--primary-color1);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.next-steps .row {
    gap: 15px;
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

@media (max-width: 768px) {
    .action-buttons .primary-btn1 {
        display: block;
        width: 100%;
        margin-bottom: 10px;
    }
    
    .action-buttons .me-3 {
        margin-right: 0 !important;
    }
    
    .next-steps .col-md-4 {
        margin-bottom: 20px;
    }
}

@media print {
    .breadcrumb-section,
    .action-buttons,
    .contact-info {
        display: none !important;
    }
    
    .success-content {
        padding: 20px 0;
    }
}
</style>
@endpush

@push('scripts')
<script>
$(document).ready(function() {
    // Auto-hide alerts after 10 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 10000);
    
    // Copy reference number to clipboard
    $('.reference-code').on('click', function() {
        const referenceNumber = $(this).find('h3').text();
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(referenceNumber).then(function() {
                // Show temporary success message
                const originalContent = $('.reference-code').html();
                $('.reference-code').html('<i class="bi bi-check-circle-fill text-success"></i><br><small>Copied to clipboard!</small>');
                
                setTimeout(function() {
                    $('.reference-code').html(originalContent);
                }, 2000);
            });
        }
    });
    
    // Add tooltip for reference number
    $('.reference-code').attr('title', 'Click to copy reference number');
});
</script>
@endpush