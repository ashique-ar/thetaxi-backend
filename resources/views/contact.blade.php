@extends('layouts.app')

@section('title', $settings['contact_page_title'] ?? 'Inquiry')

@push('meta')
    @include('partials.seo')
@endpush

@section('content')

    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ s3_asset($settings['contact_breadcrumb_image'] ?? 'assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $settings['contact_hero_heading'] ?? 'Inquiry' }}</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>{{ $settings['contact_hero_subheading'] ?? 'Inquiry' }}</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Contact Page Start-->
    <div class="contact-page pt-100 mb-100">
        <div class="container">
            <div class="contact-form">
                <div class="row justify-content-center">
                    <div class="col-lg-4 col-md-12">
                        <div class="single-contact">
                            <div class="icon">
                                <svg width="36" height="36" viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M17.9981 1.125C15.0037 1.12887 12.133 2.32012 10.0156 4.4375C7.89824 6.55489 6.70699 9.42557 6.70313 12.42C6.70312 16.2056 10.7587 22.2638 13.92 26.4037C9.99937 27.0562 7.51875 28.6087 7.51875 30.4706C7.51875 32.9794 12.0244 34.875 17.9981 34.875C23.9719 34.875 28.4831 32.9794 28.4831 30.4706C28.4831 28.6087 26.0025 27.0562 22.0762 26.4037C25.2375 22.2581 29.2931 16.2056 29.2931 12.42C29.2893 9.42557 28.098 6.55489 25.9806 4.4375C23.8632 2.32012 20.9926 1.12887 17.9981 1.125ZM17.9981 29.6663C16.0237 27.3488 7.82812 17.415 7.82812 12.42C7.82812 9.72275 8.8996 7.13597 10.8068 5.22872C12.7141 3.32148 15.3009 2.25 17.9981 2.25C20.6954 2.25 23.2822 3.32148 25.1894 5.22872C27.0966 7.13597 28.1681 9.72275 28.1681 12.42C28.1681 17.415 19.9725 27.3488 17.9981 29.6663Z" />
                                    <path
                                        d="M17.9966 18.1294C21.4853 18.1294 24.3134 15.3012 24.3134 11.8125C24.3134 8.3238 21.4853 5.49564 17.9966 5.49564C14.5078 5.49564 11.6797 8.3238 11.6797 11.8125C11.6797 15.3012 14.5078 18.1294 17.9966 18.1294Z" />
                                </svg>
                            </div>
                            <h4>{{ $settings['contact_address_1_title'] ?? 'United State' }}</h4>
                            @php
                                $contactPhone = $settings['contact_address_1_phone'] ?? $settings['company_phone'] ?? '';
                                $contactPhoneTel = preg_replace('/[^0-9+]/', '', $contactPhone);
                            @endphp
                            @if($contactPhone)
                                <h6><span>Contact :</span> <a href="tel:{{ $contactPhoneTel }}">{{ $contactPhone }}</a></h6>
                            @endif
                            <p>{{ $settings['contact_address_1_address'] ?? 'Skyline Plaza, 5th Floor, 123 Main Street Los Angeles, CA 90001, USA' }}
                            </p>
                        </div>
                    </div>
                    <div class="col-xl-8 col-lg-10">
                        <div class="contact-form-wrap">
                            <div class="section-title text-center mb-60">
                                <h2>{{ $settings['contact_form_title'] ?? 'Get in Touch!' }}</h2>
                                <p>{{ $settings['contact_form_description'] ?? "We're excited to hear from you! Whether you have a question about our services, want to discuss a new project." }}
                                </p>
                            </div>
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

                            @if (session('success'))
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    {{ session('success') }}
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"
                                        aria-label="Close"></button>
                                </div>
                            @endif

                            <form action="{{ route('contact.store') }}" method="POST">
                                @csrf
                                <input type="hidden" name="inquiry_type" value="general">
                                <input type="hidden" name="_inquiry_form_token"
                                    value="{{ \Illuminate\Support\Facades\Crypt::encryptString((string) now()->timestamp) }}">
                                <div aria-hidden="true"
                                    style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
                                    <label for="contact-company-website">Company website</label>
                                    <input id="contact-company-website" type="text" name="company_website"
                                        value="" tabindex="-1" autocomplete="off">
                                </div>
                                <div class="row g-4 mb-60">
                                    <div class="col-md-6">
                                        <div class="form-inner">
                                            <label>{{ $settings['contact_form_name_label'] ?? 'Full Name' }} *</label>
                                            <input type="text" name="name"
                                                placeholder="{{ $settings['contact_form_name_placeholder'] ?? 'Wasington Mongla' }}"
                                                value="{{ old('name') }}" required>
                                            @error('name')
                                                <span class="text-danger small">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner">
                                            <label>{{ $settings['contact_form_email_label'] ?? 'Email Address' }} *</label>
                                            <input type="email" name="email"
                                                placeholder="{{ $settings['contact_form_email_placeholder'] ?? 'info@example.com' }}"
                                                value="{{ old('email') }}" required>
                                            @error('email')
                                                <span class="text-danger small">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner">
                                            <label>{{ $settings['contact_form_phone_label'] ?? 'Phone Number' }} *</label>
                                            <input type="text" id="contact-phone" name="phone"
                                                placeholder="{{ $settings['contact_form_phone_placeholder'] ?? '+92 567 *** ***' }}"
                                                value="{{ old('phone') }}" required>
                                            @error('phone')
                                                <span class="text-danger small">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner">
                                            <label>Service Type</label>
                                            <select name="service_type_select">
                                                <option value="">Select Service Type</option>
                                                <option value="general_inquiry"
                                                    {{ old('service_type_select') == 'general_inquiry' ? 'selected' : '' }}>
                                                    General Inquiry</option>
                                                <option value="booking"
                                                    {{ old('service_type_select') == 'booking' ? 'selected' : '' }}>Booking
                                                </option>
                                                <option value="corporate"
                                                    {{ old('service_type_select') == 'corporate' ? 'selected' : '' }}>
                                                    Corporate Transport</option>
                                                <option value="complaint"
                                                    {{ old('service_type_select') == 'complaint' ? 'selected' : '' }}>
                                                    Complaint</option>
                                                <option value="feedback"
                                                    {{ old('service_type_select') == 'feedback' ? 'selected' : '' }}>
                                                    Feedback</option>
                                                <option value="other"
                                                    {{ old('service_type_select') == 'other' ? 'selected' : '' }}>Other
                                                </option>
                                            </select>
                                            @error('service_type_select')
                                                <span class="text-danger small">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner">
                                            <label>Vehicle Class</label>
                                            <select name="vehicle_class" class="form-select no-nice">
                                                <option value="">Select Vehicle Class</option>
                                                @foreach ($vehicleClasses as $vehicleClass)
                                                    <option value="{{ $vehicleClass->name }}"
                                                        {{ old('vehicle_class') === $vehicleClass->name ? 'selected' : '' }}>
                                                        {{ $vehicleClass->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('vehicle_class')
                                                <span class="text-danger small">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner">
                                            <label>{{ $settings['contact_form_destination_label'] ?? 'Country' }}</label>
                                            <select name="country" class="form-select no-nice">
                                                <option value="">
                                                    {{ $settings['contact_form_destination_placeholder'] ?? 'Select your Country' }}
                                                </option>
                                                @forelse ($countries as $country)
                                                    <option value="{{ $country->name }}"
                                                        {{ old('country') === $country->name ? 'selected' : '' }}>
                                                        {{ $country->name }}
                                                        @if ($country->code)
                                                            ({{ $country->code }})
                                                        @endif
                                                    </option>
                                                @empty
                                                    <option value="">No countries available</option>
                                                @endforelse
                                            </select>
                                            @error('country')
                                                <span class="text-danger small">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-inner">
                                            <label>{{ $settings['contact_form_message_label'] ?? 'Brief/Message' }} *</label>
                                            <textarea name="message" required
                                                placeholder="{{ $settings['contact_form_message_placeholder'] ?? 'Write somethings about inquiry' }}">{{ old('message') }}</textarea>
                                            @error('message')
                                                <span class="text-danger small">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-inner2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value=""
                                                    id="contactCheck22">
                                                <label class="form-check-label" for="contactCheck22">
                                                    {{ $settings['contact_form_privacy_text'] ?? 'I will agree with yours privacy policy & terms & conditions.' }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="primary-btn1">
                                    <span>
                                        {{ $settings['contact_form_submit_text'] ?? 'Submit Now' }}
                                        <svg width="10" height="10" viewBox="0 0 10 10"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                            </path>
                                        </svg>
                                    </span>
                                    <span>
                                        {{ $settings['contact_form_submit_text'] ?? 'Submit Now' }}
                                        <svg width="10" height="10" viewBox="0 0 10 10"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                            </path>
                                        </svg>
                                    </span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <img src="{{ asset('assets/img/innerpages/vector/contact-page-vector1.svg') }}" alt="" class="vector1">
        <img src="{{ asset('assets/img/innerpages/vector/contact-page-vector2.svg') }}" alt="" class="vector2">
        <img src="{{ asset('assets/img/innerpages/vector/contact-page-vector3.svg') }}" alt="" class="vector3">
    </div>
    <!--Contact Page End-->

    @push('styles')
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
    @endpush

    <!--Contact Map Section Start-->
    <div class="contact-map-section">
        <iframe
            src="{{ $settings['contact_map_embed_url'] ?? 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3649.5647631857846!2d90.36311167605992!3d23.83407118555764!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3755c14c8682a473%3A0xa6c74743d52adb88!2sEgens%20Lab!5e0!3m2!1sen!2sbd!4v1700138349574!5m2!1sen!2sbd' }}"
            allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
    <!--Contact Map Section End-->

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Initialize Select2 for country dropdown
                if (typeof $ !== 'undefined') {
                    const $country = $('select[name="country"]');

                    // If the global niceSelect has already created a duplicate, remove it
                    // and ensure the real select is visible and won't be converted again
                    $country.next('.nice-select').remove();
                    $country.show();
                    $country.addClass('no-nice');

                    $country.select2({
                        placeholder: 'Search and select your country',
                        allowClear: true,
                        width: '100%',
                        language: {
                            noResults: function() {
                                return 'No countries found';
                            }
                        }
                    });
                } else {
                    console.warn('jQuery not loaded - Select2 will not be initialized');
                }

                // Initialize intl-tel-input for contact phone (if library is loaded)
                const contactPhone = document.getElementById('contact-phone');
                if (contactPhone && typeof window.intlTelInput === 'function') {
                    const itiContact = window.intlTelInput(contactPhone, {
                        initialCountry: '{{ $settings['default_country_code'] ?? 'lk' }}',
                        preferredCountries: ['lk', 'us', 'gb', 'au'],
                        separateDialCode: true,
                        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
                    });
                    const contactForm = contactPhone.closest('form');
                    if (contactForm) {
                        contactForm.addEventListener('submit', function() {
                            if (itiContact.isValidNumber()) {
                                contactPhone.value = itiContact.getNumber();
                            }
                        });
                    }
                }
            });
        </script>
        <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/intlTelInput.js"></script>
        <script>
            // Ensure intlTelInput is initialized even if the library loads after DOMContentLoaded
            (function initContactITI() {
                const contactPhone = document.getElementById('contact-phone');
                if (!contactPhone) return;

                function setup() {
                    if (typeof window.intlTelInput !== 'function') return;

                    // Avoid double initialization
                    if (contactPhone._itiInstance) return;

                    const itiContact = window.intlTelInput(contactPhone, {
                        initialCountry: '{{ $settings['default_country_code'] ?? 'lk' }}',
                        preferredCountries: ['lk', 'us', 'gb', 'au'],
                        separateDialCode: true,
                        utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
                    });

                    contactPhone._itiInstance = itiContact;

                    const contactForm = contactPhone.closest('form');
                    if (contactForm) {
                        contactForm.addEventListener('submit', function() {
                            if (itiContact.isValidNumber()) {
                                contactPhone.value = itiContact.getNumber();
                            }
                        });
                    }
                }

                if (typeof window.intlTelInput === 'function') {
                    setup();
                } else {
                    // If script not yet loaded, wait for it to load
                    const script = document.querySelector('script[src*="intlTelInput"]');
                    if (script) {
                        script.addEventListener('load', setup);
                    } else {
                        // fallback: poll for the function
                        const interval = setInterval(function() {
                            if (typeof window.intlTelInput === 'function') {
                                clearInterval(interval);
                                setup();
                            }
                        }, 100);
                        // stop polling after 5 seconds
                        setTimeout(function() {
                            clearInterval(interval);
                        }, 5000);
                    }
                }
            })();
        </script>
    @endpush
@endsection
