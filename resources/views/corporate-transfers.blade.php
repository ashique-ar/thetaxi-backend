@extends('layouts.app')

@section('title', 'Corporate Transfers - TheTaxi')

@section('content')
    <!-- Corporate Transport Banner Section Start-->
    <div class="home4-banner-section mb-100">
        <div class="banner-video-area">
            @php
                $corporateBanner = $settings['corporate_banner_image'] ?? null;
                $fallbackBanner = $settings['banner_image'] ?? null;
            @endphp
            <img src="{{ $corporateBanner ? s3_asset($corporateBanner) : ($fallbackBanner ? s3_asset($fallbackBanner) : asset('assets/img/home4/home4-banner-img.jpg')) }}"
                alt="{{ $settings['corporate_hero_heading'] ?? 'Corporate Transport Solutions' }}" loading="lazy">
            {{-- <video autoplay loop muted playsinline src="{{ asset('assets/video/home4-banner-video.mp4')}}"></video> --}}
        </div>
        <div class="banner-content-wrap">
            <div class="container">
                <div class="banner-content">
                    <h1>{{ $settings['corporate_hero_heading'] ?? 'Corporate Transport Solutions' }}</h1>
                    <p>{{ $settings['corporate_hero_subheading'] ?? 'Professional transportation services tailored for your business needs' }}
                    </p>

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
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                                        aria-label="Close"></button>
                                </div>
                            @endif

                            <!-- Success Message Display -->
                            @if (session('success'))
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    {{ session('success') }}
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                                        aria-label="Close"></button>
                                </div>
                            @endif

                            <!-- Corporate Transport Form -->
                            <form id="corporate-transport-form" class="filter-input show "
                                data-service="corporate-transport" action="{{ route('booking.enquiry') }}" method="POST">
                                @csrf
                                <input type="hidden" name="service_type" value="corporate-transport">
                                <input type="hidden" name="inquiry_type" value="corporate">

                                <!-- Company Name -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path d="M2 2h14v14H2V2zm2 2v10h10V4H4zm2 2h6v1H6V6zm0 2h6v1H6V8zm0 2h4v1H6v-1z" />
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="text" name="company_name"
                                            placeholder="{{ $settings['corporate_form_company_placeholder'] ?? 'Company Name' }}"
                                            class="@error('company_name') is-invalid @enderror"
                                            value="{{ old('company_name') }}" required autocomplete="off">
                                    </div>
                                    @error('company_name')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Contact Person -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9 9c2.5 0 4.5-2 4.5-4.5S11.5 0 9 0 4.5 2 4.5 4.5 6.5 9 9 9zm0 1.5c-3 0-9 1.5-9 4.5V18h18v-3c0-3-6-4.5-9-4.5z" />
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="text" name="contact_person"
                                            placeholder="{{ $settings['corporate_form_contact_placeholder'] ?? 'Contact Person' }}"
                                            class="@error('contact_person') is-invalid @enderror"
                                            value="{{ old('contact_person') }}" required autocomplete="off">
                                    </div>
                                    @error('contact_person')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Email -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M16 2H2c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 2l-7 4.5L2 4h14zm0 10H2V6l7 4.5L16 6v8z" />
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="email" name="email"
                                            placeholder="{{ $settings['corporate_form_email_placeholder'] ?? 'Email Address' }}"
                                            class="@error('email') is-invalid @enderror" value="{{ old('email') }}"
                                            required autocomplete="off">
                                    </div>
                                    @error('email')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Phone -->
                                <div class="single-search-box">
                                    {{-- <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M3.5 1C2.67 1 2 1.67 2 2.5v13c0 .83.67 1.5 1.5 1.5h11c.83 0 1.5-.67 1.5-1.5v-13C16 1.67 15.33 1 14.5 1h-11zM4 3h10v10H4V3zm5 11.5c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z" />
                                    </svg> --}}
                                    {{-- <div class="custom-select-dropdown"> --}}
                                    <input type="tel" id="corporate-mobile" name="phone"
                                        placeholder="{{ $settings['corporate_form_phone_placeholder'] ?? 'Mobile Number' }}"
                                        class="@error('phone') is-invalid @enderror" value="{{ old('phone') }}" required
                                        autocomplete="off">
                                    {{-- </div> --}}
                                    @error('phone')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Type of Services -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path d="M9 1L2 4v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V4L9 1z" />
                                    </svg>
                                    <select name="service_type_select" id="service-type-select"
                                        class="form-select @error('service_type_select') is-invalid @enderror" required>
                                        <option value="">Select Service Type</option>
                                        <option value="airport_transfer"
                                            {{ old('service_type_select') == 'airport_transfer' ? 'selected' : '' }}>
                                            Airport Transfer</option>
                                        <option value="corporate_event"
                                            {{ old('service_type_select') == 'corporate_event' ? 'selected' : '' }}>
                                            Corporate Event</option>
                                        <option value="employee_shuttle"
                                            {{ old('service_type_select') == 'employee_shuttle' ? 'selected' : '' }}>
                                            Employee Shuttle</option>
                                        <option value="client_meeting"
                                            {{ old('service_type_select') == 'client_meeting' ? 'selected' : '' }}>
                                            Client Meeting</option>
                                        <option value="other"
                                            {{ old('service_type_select') == 'other' ? 'selected' : '' }}>Other
                                        </option>
                                    </select>
                                    @error('service_type_select')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Other Service Type Input (hidden by default) -->
                                <div class="single-search-box" id="other-service-container" style="display: none;">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path d="M9 1L2 4v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V4L9 1z" />
                                    </svg>
                                    <input type="text" id="other-service-input" name="other_service_type"
                                        placeholder="Please specify the service type"
                                        class="@error('other_service_type') is-invalid @enderror"
                                        value="{{ old('other_service_type') }}" autocomplete="off">
                                    @error('other_service_type')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Employee Strength -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9 9c2.5 0 4.5-2 4.5-4.5S11.5 0 9 0 4.5 2 4.5 4.5 6.5 9 9 9zm0 1.5c-3 0-9 1.5-9 4.5V18h18v-3c0-3-6-4.5-9-4.5z" />
                                    </svg>
                                    <select name="employee_strength" id="employee-strength"
                                        class="form-select @error('employee_strength') is-invalid @enderror" required>
                                        <option value="">Select Employee Strength</option>
                                        <option value="1-10" {{ old('employee_strength') == '1-10' ? 'selected' : '' }}>
                                            1-10 Employees
                                        </option>
                                        <option value="11-50"
                                            {{ old('employee_strength') == '11-50' ? 'selected' : '' }}>11-50 Employees
                                        </option>
                                        <option value="51-100"
                                            {{ old('employee_strength') == '51-100' ? 'selected' : '' }}>51-100
                                            Employees</option>
                                        <option value="101-500"
                                            {{ old('employee_strength') == '101-500' ? 'selected' : '' }}>101-500
                                            Employees</option>
                                        <option value="500+" {{ old('employee_strength') == '500+' ? 'selected' : '' }}>
                                            500+ Employees
                                        </option>
                                    </select>
                                    @error('employee_strength')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- City Name -->
                                <div class="single-search-box">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path d="M9 1L2 4v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V4L9 1z" />
                                    </svg>
                                    <div class="custom-select-dropdown">
                                        <input type="text" name="city_name" placeholder="City Name"
                                            class="nice-select @error('city_name') is-invalid @enderror"
                                            value="{{ old('city_name') }}" required autocomplete="off">
                                    </div>
                                    @error('city_name')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Service Requirements - Full Width -->
                                <div class="corporate-requirements-field">
                                    <textarea name="requirements"
                                        placeholder="{{ $settings['corporate_form_requirements_placeholder'] ?? 'Describe your corporate transport requirements...' }}"
                                        class="@error('requirements') is-invalid @enderror" rows="4" required>{{ old('requirements') }}</textarea>
                                    @error('requirements')
                                        <span class="text-danger small">{{ $message }}</span>
                                    @enderror
                                </div>

                                <button type="submit" class="primary-btn1 corporate-submit-btn">
                                    <span>{{ $settings['corporate_form_submit_text'] ?? 'Submit Enquiry' }}</span>
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
                        {{ $settings['corporate_feature_section_heading'] ?? 'Why Choose Our Corporate Transport?' }}
                    </h2>
                    <p class="wow animate fadeInDown" data-wow-delay="300ms" data-wow-duration="1500ms">
                        {{ $settings['corporate_feature_section_description'] ?? 'Professional, reliable, and efficient transportation solutions for your business' }}
                    </p>
                </div>
            </div>
            @php
                $corporateFeatureVector = $settings['corporate_feature_card_vector'] ?? null;
            @endphp
            <div class="row g-4">
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="feature-card">
                        <div class="icon">
                            <img src="{{ !empty($settings['corporate_feature_1_icon']) ? s3_asset($settings['corporate_feature_1_icon']) : asset('assets/img/home4/icon/feature-icon1.svg') }}"
                                alt="{{ $settings['corporate_feature_1_title'] ?? 'Executive Fleet' }}">
                        </div>
                        <h4>{{ $settings['corporate_feature_1_title'] ?? 'Executive Fleet' }}</h4>
                        <p>{{ $settings['corporate_feature_1_description'] ?? 'Premium vehicles maintained to the highest standards for your corporate image and comfort.' }}
                        </p>
                        <img src="{{ $corporateFeatureVector ? s3_asset($corporateFeatureVector) : asset('assets/img/home4/vector/feature-card-vector.svg') }}"
                            alt="" class="vector">
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <div class="feature-card two">
                        <div class="icon">
                            <img src="{{ !empty($settings['corporate_feature_2_icon']) ? s3_asset($settings['corporate_feature_2_icon']) : asset('assets/img/home4/icon/feature-icon2.svg') }}"
                                alt="{{ $settings['corporate_feature_2_title'] ?? 'Professional Chauffeurs' }}">
                        </div>
                        <h4>{{ $settings['corporate_feature_2_title'] ?? 'Professional Chauffeurs' }}</h4>
                        <p>{{ $settings['corporate_feature_2_description'] ?? 'Experienced, uniformed drivers who understand corporate etiquette and punctuality.' }}
                        </p>
                        <img src="{{ $corporateFeatureVector ? s3_asset($corporateFeatureVector) : asset('assets/img/home4/vector/feature-card-vector.svg') }}"
                            alt="" class="vector">
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="600ms" data-wow-duration="1500ms">
                    <div class="feature-card three">
                        <div class="icon">
                            <img src="{{ !empty($settings['corporate_feature_3_icon']) ? s3_asset($settings['corporate_feature_3_icon']) : asset('assets/img/home4/icon/feature-icon3.svg') }}"
                                alt="{{ $settings['corporate_feature_3_title'] ?? 'Account Management' }}">
                        </div>
                        <h4>{{ $settings['corporate_feature_3_title'] ?? 'Account Management' }}</h4>
                        <p>{{ $settings['corporate_feature_3_description'] ?? 'Dedicated account managers and monthly billing options for seamless corporate integration.' }}
                        </p>
                        <img src="{{ $corporateFeatureVector ? s3_asset($corporateFeatureVector) : asset('assets/img/home4/vector/feature-card-vector.svg') }}"
                            alt="" class="vector">
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
                            <span>{{ $settings['corporate_services_kicker'] ?? 'Corporate Solutions' }}</span>
                            <h2>{{ $settings['corporate_services_heading'] ?? 'Tailored Business Transport' }}</h2>
                        </div>
                        <p class="mb-4">
                            {{ $settings['corporate_services_description'] ?? 'Our corporate transport services are designed to meet the unique needs of businesses, from executive travel to employee shuttles and client transportation.' }}
                        </p>

                        <div class="service-features">
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>{{ $settings['corporate_services_feature_1'] ?? 'Executive airport transfers' }}</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>{{ $settings['corporate_services_feature_2'] ?? 'Corporate event transportation' }}</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>{{ $settings['corporate_services_feature_3'] ?? 'Employee shuttle services' }}</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>{{ $settings['corporate_services_feature_4'] ?? 'Client meeting transportation' }}</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>{{ $settings['corporate_services_feature_5'] ?? 'Monthly billing and reporting' }}</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="bi bi-check-circle-fill text-primary me-2"></i>
                                <span>{{ $settings['corporate_services_feature_6'] ?? '24/7 customer support' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 wow animate fadeInRight" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <div class="package-img-area">
                        <img src="{{ !empty($settings['corporate_services_image']) ? s3_asset($settings['corporate_services_image']) : asset('assets/img/home4/package-img.jpg') }}"
                            alt="{{ $settings['corporate_services_heading'] ?? 'Corporate Transport Service' }}"
                            class="img-fluid">
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
                        <span>{{ $settings['corporate_benefits_kicker'] ?? 'Corporate Benefits' }}</span>
                        <h2>{{ $settings['corporate_benefits_heading'] ?? 'What Makes Us Different' }}</h2>
                    </div>
                </div>
            </div>
            <div class="row g-4">
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        <div class="benefit-icon mb-3">
                            <i class="{{ $settings['corporate_benefit_1_icon'] ?? 'bi bi-clock-history' }}"
                                style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                        </div>
                        <h5>{{ $settings['corporate_benefit_1_title'] ?? 'Punctuality Guaranteed' }}</h5>
                        <p>{{ $settings['corporate_benefit_1_description'] ?? 'On-time arrivals with real-time tracking and proactive communication.' }}
                        </p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        <div class="benefit-icon mb-3">
                            <i class="{{ $settings['corporate_benefit_2_icon'] ?? 'bi bi-shield-check' }}"
                                style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                        </div>
                        <h5>{{ $settings['corporate_benefit_2_title'] ?? 'Secure & Safe' }}</h5>
                        <p>{{ $settings['corporate_benefit_2_description'] ?? 'Fully licensed, insured vehicles with background-checked professional drivers.' }}
                        </p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        <div class="benefit-icon mb-3">
                            <i class="{{ $settings['corporate_benefit_3_icon'] ?? 'bi bi-graph-up-arrow' }}"
                                style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                        </div>
                        <h5>{{ $settings['corporate_benefit_3_title'] ?? 'Cost Effective' }}</h5>
                        <p>{{ $settings['corporate_benefit_3_description'] ?? 'Competitive rates with volume discounts and transparent pricing structure.' }}
                        </p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="benefit-card text-center">
                        <div class="benefit-icon mb-3">
                            <i class="{{ $settings['corporate_benefit_4_icon'] ?? 'bi bi-headset' }}"
                                style="font-size: 2.5rem; color: var(--primary-color1);"></i>
                        </div>
                        <h5>{{ $settings['corporate_benefit_4_title'] ?? '24/7 Support' }}</h5>
                        <p>{{ $settings['corporate_benefit_4_description'] ?? 'Round-the-clock customer support and emergency assistance when needed.' }}
                        </p>
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
                        <span>{{ $settings['corporate_faq_kicker'] ?? 'Frequently Asked Questions' }}</span>
                        <h2>{{ $settings['corporate_faq_heading'] ?? 'Corporate Transport Questions' }}</h2>
                    </div>

                    <div class="accordion" id="corporateFAQ">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseOne">
                                    {{ $settings['corporate_faq_1_question'] ?? 'How do I set up a corporate account?' }}
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse show"
                                data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    {{ $settings['corporate_faq_1_answer'] ?? 'Setting up a corporate account is simple. Submit an enquiry through our form, and our corporate sales team will contact you within 24 hours to discuss your requirements and set up your account with preferred payment terms.' }}
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingTwo">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseTwo">
                                    {{ $settings['corporate_faq_2_question'] ?? 'What types of vehicles do you offer for corporate clients?' }}
                                </button>
                            </h2>
                            <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    {{ $settings['corporate_faq_2_answer'] ?? 'We offer a premium fleet including executive sedans, luxury SUVs, people carriers for groups, and minibuses for larger corporate events. All vehicles are less than 3 years old and maintained to the highest standards.' }}
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingThree">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseThree">
                                    {{ $settings['corporate_faq_3_question'] ?? 'Do you provide monthly billing?' }}
                                </button>
                            </h2>
                            <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    {{ $settings['corporate_faq_3_answer'] ?? 'Yes, we offer monthly billing with detailed journey reports for all corporate accounts. Invoices include trip details, passenger information, and cost center allocation for easy expense management.' }}
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingFour">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseFour">
                                    {{ $settings['corporate_faq_4_question'] ?? 'Can employees book directly?' }}
                                </button>
                            </h2>
                            <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    {{ $settings['corporate_faq_4_answer'] ?? 'Yes, we can provide your employees with access to our corporate booking portal where they can book rides directly using their employee ID. All bookings are automatically allocated to your corporate account.' }}
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingFive">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseFive">
                                    {{ $settings['corporate_faq_5_question'] ?? 'What are your service hours?' }}
                                </button>
                            </h2>
                            <div id="collapseFive" class="accordion-collapse collapse" data-bs-parent="#corporateFAQ">
                                <div class="accordion-body">
                                    {{ $settings['corporate_faq_5_answer'] ?? 'Our corporate transport service operates 24/7, 365 days a year. Whether you need early morning airport transfers or late-night client transportation, we are available whenever your business requires it.' }}
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

            // Toggle other service type input
            const serviceTypeSelect = document.getElementById('service-type-select');
            const otherServiceContainer = document.getElementById('other-service-container');
            const otherServiceInput = document.getElementById('other-service-input');

            // Function to handle service type change
            function handleServiceTypeChange() {
                const isOtherSelected = serviceTypeSelect.value === 'other';
                if (isOtherSelected) {
                    otherServiceContainer.style.display = 'block';
                    otherServiceInput.required = true;
                    otherServiceInput.disabled = false;
                    otherServiceInput.focus();
                } else {
                    otherServiceContainer.style.display = 'none';
                    otherServiceInput.required = false;
                    otherServiceInput.disabled = true; // disable so HTML5 won't validate hidden field
                    otherServiceInput.value = '';
                }
            }

            // Listen for changes on the select element
            serviceTypeSelect.addEventListener('change', handleServiceTypeChange);

            // Also trigger on page load if 'other' was previously selected (after form submission)
            handleServiceTypeChange();

            // Ensure before submit the other field is disabled when not needed
            form.addEventListener('submit', function() {
                if (serviceTypeSelect.value !== 'other') {
                    otherServiceInput.disabled = true;
                    otherServiceInput.value = '';
                } else {
                    otherServiceInput.disabled = false;
                }
            });
            preferredCountries: ['lk', 'us', 'gb', 'au'],
                separateDialCode: true,
                utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
        });

        // Update the input value to include country code on form submit
        form.addEventListener('submit', function() {
            if (iti.isValidNumber()) {
                mobileInput.value = iti.getNumber();
            }
        });
        }

        // Initialize nice-select for selects if available
        if (typeof $ !== 'undefined' && $.fn.niceSelect) {
            $('select.form-select').niceSelect();

            // Re-bind change event for nice-select
            $(document).on('change.niceSelect', '#service-type-select', function() {
                handleServiceTypeChange();
            });
        }
        });
    </script>

    @push('styles')
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
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

            /* Intl tel input styling */
            .iti {
                width: 100%;
            }

            .iti__selected-flag {
                background: none;
            }


            /* Nice select styling */
            .nice-select {
                position: relative;
                z-index: 10;
            }

            .nice-select .list {
                z-index: 100;
            }
        </style>
    @endpush

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/intlTelInput.js"></script>
    @endpush
@endsection
