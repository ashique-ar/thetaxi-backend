@extends('layouts.app')

@section('title', 'Corporate Transfers - TheTaxi')

@section('content')
    <!-- Corporate Transport Banner Section Start-->
    <div class="home4-banner-section mb-100">
        <div class="banner-video-area">
            <video autoplay loop muted playsinline src="{{ asset('assets/video/home4-banner-video.mp4')}}"></video>
        </div>
        <div class="banner-content-wrap">
            <div class="container">
                <div class="banner-content">
                    <h1>Corporate Transport Solutions</h1>
                    <p>Professional transportation services tailored for your business needs</p>
                    
                    <!-- Corporate Transport Booking Form -->
                    <div class="filter-wrapper">
                        <div class="filter-input-wrap">
                            <!-- Validation Errors Display -->
                            @if ($errors->any())
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <strong>Please correct the following errors:</strong>
                                    <ul class="mb-0 mt-2">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            @endif

                            <!-- Success Message Display -->
                            @if (session('success'))
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    {{ session('success') }}
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            @endif

                            <!-- Corporate Transport Form -->
                            <form id="corporate-transport-form" class="filter-input show" data-service="corporate-transport"
                                action="{{ route('booking.enquiry') }}" method="POST">
                                @csrf
                                <input type="hidden" name="service_type" value="corporate-transport">

                                <!-- Company Name -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M2 2h14v14H2V2zm2 2v10h10V4H4zm2 2h6v1H6V6zm0 2h6v1H6V8zm0 2h4v1H6v-1z"/>
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="text" name="company_name" placeholder="Company Name"
                                            class="nice-select @error('company_name') is-invalid @enderror"
                                            value="{{ old('company_name') }}" required autocomplete="off">
                                    </div>
                                    @error('company_name')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Contact Person -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M9 9c2.5 0 4.5-2 4.5-4.5S11.5 0 9 0 4.5 2 4.5 4.5 6.5 9 9 9zm0 1.5c-3 0-9 1.5-9 4.5V18h18v-3c0-3-6-4.5-9-4.5z"/>
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="text" name="contact_person" placeholder="Contact Person"
                                            class="nice-select @error('contact_person') is-invalid @enderror"
                                            value="{{ old('contact_person') }}" required autocomplete="off">
                                    </div>
                                    @error('contact_person')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Email -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M16 2H2c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 2l-7 4.5L2 4h14zm0 10H2V6l7 4.5L16 6v8z"/>
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="email" name="email" placeholder="Email Address"
                                            class="nice-select @error('email') is-invalid @enderror"
                                            value="{{ old('email') }}" required autocomplete="off">
                                    </div>
                                    @error('email')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Phone -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M3.5 1C2.67 1 2 1.67 2 2.5v13c0 .83.67 1.5 1.5 1.5h11c.83 0 1.5-.67 1.5-1.5v-13C16 1.67 15.33 1 14.5 1h-11zM4 3h10v10H4V3zm5 11.5c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/>
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="tel" name="phone" placeholder="Phone Number"
                                            class="nice-select @error('phone') is-invalid @enderror"
                                            value="{{ old('phone') }}" required autocomplete="off">
                                    </div>
                                    @error('phone')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Service Requirements - Full Width -->
                                <div class="corporate-requirements-field">
                                    <textarea name="requirements" placeholder="Describe your corporate transport requirements..."
                                        class="@error('requirements') is-invalid @enderror" rows="4" required>{{ old('requirements') }}</textarea>
                                    @error('requirements')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <button type="submit" class="primary-btn1 corporate-submit-btn">
                                    <span>Submit Enquiry</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Corporate Transport Banner Section End-->

    <!-- Corporate Features Section Start-->
    <div class="home4-feature-section mb-100">
        <div class="container">
            <div class="row text-center mb-5">
                <div class="col-12">
                    <h2 class="wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                        Why Choose Our Corporate Transport?
                    </h2>
                    <p class="wow animate fadeInDown" data-wow-delay="300ms" data-wow-duration="1500ms">
                        Professional, reliable, and efficient transportation solutions for your business
                    </p>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="feature-card">
                        <div class="icon">
                            <img src="{{ asset('assets/img/home4/icon/feature-icon1.svg')}}" alt="">
                        </div>
                        <h4>Executive Fleet</h4>
                        <p>Premium vehicles maintained to the highest standards for your corporate image and comfort.</p>
                        <img src="{{ asset('assets/img/home4/vector/feature-card-vector.svg')}}" alt="" class="vector">
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <div class="feature-card two">
                        <div class="icon">
                            <img src="{{ asset('assets/img/home4/icon/feature-icon2.svg')}}" alt="">
                        </div>
                        <h4>Professional Chauffeurs</h4>
                        <p>Experienced, uniformed drivers who understand corporate etiquette and punctuality.</p>
                        <img src="{{ asset('assets/img/home4/vector/feature-card-vector.svg')}}" alt="" class="vector">
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="600ms" data-wow-duration="1500ms">
                    <div class="feature-card three">
                        <div class="icon">
                            <img src="{{ asset('assets/img/home4/icon/feature-icon3.svg')}}" alt="">
                        </div>
                        <h4>Account Management</h4>
                        <p>Dedicated account managers and monthly billing options for seamless corporate integration.</p>
                        <img src="{{ asset('assets/img/home4/vector/feature-card-vector.svg')}}" alt="" class="vector">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Corporate Features Section End-->

    <!-- Corporate Services Section Start-->
    <div class="package-section mb-100">
        <div class="container">
            <div class="row">
                <div class="col-lg-6 wow animate fadeInLeft" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="package-content-wrap">
                        <div class="section-title1 mb-4">
                            <span>Corporate Solutions</span>
                            <h2>Tailored Business Transport</h2>
                        </div>
                        <p class="mb-4">Our corporate transport services are designed to meet the unique needs of businesses, from executive travel to employee shuttles and client transportation.</p>
                        
                        <div class="service-features">
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Executive airport transfers</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Corporate event transportation</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Employee shuttle services</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Client meeting transportation</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>Monthly billing and reporting</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>24/7 customer support</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 wow animate fadeInRight" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <div class="package-img-area">
                        <img src="{{ asset('assets/img/home4/package-img.jpg')}}" alt="Corporate Transport Service" class="img-fluid">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Corporate Services Section End-->

    <!-- Benefits Section Start-->
    <div class="testimonial-section mb-100">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <div class="section-title1 text-center mb-5">
                        <span>Corporate Benefits</span>
                        <h2>What Makes Us Different</h2>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        <div class="benefit-icon mb-3">
                            <i class="bi bi-clock-history" style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                        </div>
                        <h5>Punctuality Guaranteed</h5>
                        <p>On-time arrivals with real-time tracking and proactive communication.</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        <div class="benefit-icon mb-3">
                            <i class="bi bi-shield-check" style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                        </div>
                        <h5>Secure & Safe</h5>
                        <p>Fully licensed, insured vehicles with background-checked professional drivers.</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        <div class="benefit-icon mb-3">
                            <i class="bi bi-graph-up-arrow" style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                        </div>
                        <h5>Cost Effective</h5>
                        <p>Competitive rates with volume discounts and transparent pricing structure.</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        <div class="benefit-icon mb-3">
                            <i class="bi bi-headset" style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                        </div>
                        <h5>24/7 Support</h5>
                        <p>Round-the-clock customer support and emergency assistance when needed.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Benefits Section End-->

    <!-- FAQ Section Start-->
    <div class="faq-section mb-100">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="section-title1 text-center mb-5">
                        <span>Frequently Asked Questions</span>
                        <h2>Corporate Transport Questions</h2>
                    </div>
                    
                    <div class="accordion" id="corporateFAQ">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                                    How do I set up a corporate account?
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse show" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    Setting up a corporate account is simple. Submit an enquiry through our form, and our corporate sales team will contact you within 24 hours to discuss your requirements and set up your account with preferred payment terms.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingTwo">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                                    What types of vehicles do you offer for corporate clients?
                                </button>
                            </h2>
                            <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    We offer a premium fleet including executive sedans, luxury SUVs, people carriers for groups, and minibuses for larger corporate events. All vehicles are less than 3 years old and maintained to the highest standards.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingThree">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                                    Do you provide monthly billing?
                                </button>
                            </h2>
                            <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    Yes, we offer monthly billing with detailed journey reports for all corporate accounts. Invoices include trip details, passenger information, and cost center allocation for easy expense management.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingFour">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                                    Can employees book directly?
                                </button>
                            </h2>
                            <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    Yes, we can provide your employees with access to our corporate booking portal where they can book rides directly using their employee ID. All bookings are automatically allocated to your corporate account.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingFive">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFive">
                                    What are your service hours?
                                </button>
                            </h2>
                            <div id="collapseFive" class="accordion-collapse collapse" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    Our corporate transport service operates 24/7, 365 days a year. Whether you need early morning airport transfers or late-night client transportation, we're available whenever your business requires it.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- FAQ Section End-->

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add loading states to form submission
        const form = document.getElementById('corporate-transport-form');
        
        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            const btnText = submitBtn.querySelector('span');
            const originalText = btnText.textContent;

            // Prevent double submission
            if (submitBtn.disabled) {
                e.preventDefault();
                return;
            }

            // Add loading state
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.7';
            btnText.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

            // If form submission takes too long, restore button (fallback)
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                btnText.textContent = originalText;
            }, 30000); // 30 seconds timeout
        });

        // Auto-resize textarea
        const textarea = document.querySelector('textarea[name="requirements"]');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        }
    });
</script>

@push('styles')
<style>
    .benefit-card {
        padding: 2rem 1rem;
        border-radius: 10px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        background: white;
        height: 100%;
    }
    
    .benefit-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    }
    
    .corporate-requirements-field {
        grid-column: 1 / -1;
        margin-bottom: 1rem;
    }
    
    .corporate-requirements-field textarea {
        width: 100%;
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-family: inherit;
        font-size: 14px;
        resize: vertical;
        min-height: 120px;
    }
    
    .corporate-requirements-field textarea:focus {
        outline: none;
        border-color: var(--primary-color1);
        box-shadow: 0 0 0 2px rgba(191, 38, 41, 0.1);
    }
    
    .corporate-submit-btn {
        width: 100%;
        margin-top: 1rem;
    }
</style>
@endpush
@endsection