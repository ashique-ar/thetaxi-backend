@extends('layouts.app')

@section('title', $content->meta_title ?? $content->title . ' - ' . ($settings['site_name'] ?? $settings['brand_name'] ?? 'Company') . '')

@php
    $quotationCountries = $countries ?? \App\Models\Country::orderBy('name')->get(['id', 'name', 'code', 'callcode']);
@endphp

@push('meta')
    @include('partials.seo', ['model' => $content])
@endpush

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        #requestQuotationModal .iti {
            width: 100%;
        }

        /* Article show improvements */
        .article-hero {
            background: linear-gradient(180deg, rgba(0, 0, 0, 0.35), rgba(0, 0, 0, 0.35)), url('{{ $content->thumbnail ? s3_asset($content->thumbnail) : s3_asset($settings['cms_content_placeholder_image'] ?? 'assets/img/innerpages/breadcrumb-bg.jpg') }}') center/cover no-repeat;
            padding: 60px 0;
            color: #fff;
        }

        .article-hero h1 {
            font-size: 2.4rem;
            font-weight: 700;
        }

        .article-page {
            padding: 72px 0 86px;
            background:
                linear-gradient(180deg, rgba(248, 250, 252, 0.95) 0%, rgba(255, 255, 255, 1) 42%),
                #fff;
        }

        .cms-article-page {
            padding-top: 110px;
        }

        .cms-article-main {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
        }

        .cms-article-title {
            max-width: 880px;
            margin: 0 0 12px;
            color: #111827;
            font-size: clamp(2rem, 3vw, 3.15rem);
            line-height: 1.08;
            font-weight: 800;
            letter-spacing: 0;
        }

        .article-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 24px;
            color: #64748b;
        }

        .article-meta small {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .article-image img {
            width: 100%;
            height: auto;
            max-height: 560px;
            object-fit: cover;
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.16);
        }

        .cms-article-page .lead {
            margin: 28px 0;
            padding: 22px 24px;
            border-left: 4px solid var(--primary-color1, #BF2629);
            border-radius: 0 12px 12px 0;
            background: #fff7f7;
            color: #4b5563 !important;
            font-size: 1.1rem;
            line-height: 1.75;
        }

        .reading-progress {
            position: fixed;
            top: 0;
            left: 0;
            height: 4px;
            background: linear-gradient(90deg, #BF2629, #8B1A1C);
            width: 0%;
            z-index: 10000;
            transition: width 0.15s ease-out;
        }

        .article-sidebar {
            position: sticky;
            top: 120px;
        }

        .sidebar-widget {
            background: #fff;
            padding: 20px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 14px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.07);
            margin-bottom: 20px;
        }

        .sidebar-widget h6 {
            margin-bottom: 16px !important;
            color: #111827;
            font-size: 0.95rem;
            font-weight: 800;
        }

        .related-item {
            display: flex;
            gap: 12px;
            padding: 10px 0;
            margin-bottom: 0;
            border-bottom: 1px solid #eef2f7;
        }

        .related-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .related-item img {
            width: 72px;
            height: 56px;
            object-fit: cover;
            border-radius: 6px;
        }

        .share-btns a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            margin-right: 8px;
            color: #fff;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .share-btns a:hover {
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.18);
        }

        .share-btns a.facebook {
            background: #3b5998;
        }

        .share-btns a.twitter {
            background: #1da1f2;
        }

        .share-btns a.linkedin {
            background: #0077b5;
        }

        .share-btns a.email {
            background: #6c757d;
        }

        blockquote.custom-quote {
            background: #f8f9fa;
            padding: 20px;
            border-left: 4px solid #BF2629;
            border-radius: 6px;
            margin: 25px 0;
        }

        /* Preserve CMS-authored whitespace while keeping long content inside the article column. */
        .content-body {
            color: #374151;
            font-size: 1.02rem;
            line-height: 1.86;
            word-wrap: break-word !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
            hyphens: auto !important;
        }

        .content-body p,
        .content-body li {
            white-space: pre-wrap;
        }

        .content-body h1,
        .content-body h2,
        .content-body h3,
        .content-body h4,
        .content-body h5,
        .content-body h6 {
            margin: 34px 0 14px;
            color: #111827;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: 0;
        }

        .content-body h1 {
            font-size: clamp(1.75rem, 2.4vw, 2.45rem);
        }

        .content-body>h1:first-child {
            display: none;
        }

        .content-body h2 {
            position: relative;
            padding-top: 8px;
            font-size: clamp(1.45rem, 2vw, 1.9rem);
        }

        .content-body h2::before {
            content: "";
            display: block;
            width: 54px;
            height: 4px;
            margin-bottom: 14px;
            border-radius: 999px;
            background: var(--primary-color1, #BF2629);
        }

        .content-body h3 {
            font-size: 1.22rem;
        }

        .content-body p {
            margin-bottom: 18px;
        }

        .content-body ul,
        .content-body ol {
            display: grid;
            gap: 10px;
            margin: 18px 0 24px;
            padding-left: 24px;
        }

        .content-body li::marker {
            color: var(--primary-color1, #BF2629);
            font-weight: 800;
        }

        .content-body a {
            color: var(--primary-color1, #BF2629);
            font-weight: 700;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .content-body img {
            max-width: 100% !important;
            height: auto !important;
            display: block;
            margin: 24px 0;
            border-radius: 12px;
        }

        .cms-booking-section {
            margin-top: 58px;
            padding: 28px 28px 10px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 22px 60px rgba(15, 23, 42, 0.12);
        }

        .cms-booking-header {
            margin-bottom: 22px;
        }

        .cms-booking-header h2 {
            margin-bottom: 8px;
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
        }

        .cms-booking-header p {
            margin-bottom: 0;
            color: #6b7280;
        }

        @media (max-width:991px) {
            .cms-article-main {
                padding: 22px;
            }

            .article-sidebar {
                position: static;
                top: auto;
                margin-top: 28px;
            }
        }

        @media (max-width: 1199px) {
            .cms-article-page {
                padding-top: 90px;
            }
        }

        @media (max-width: 575px) {
            .article-page {
                padding-bottom: 58px;
            }

            .cms-article-main,
            .cms-booking-section {
                padding: 18px;
                border-radius: 14px;
            }

            .cms-article-page .lead {
                padding: 18px;
                font-size: 1rem;
            }
        }
    </style>
@endpush

@section('content')

    <div class="reading-progress" id="readingProgress" aria-hidden="true"></div>

    <section class="article-page cms-article-page">
        <div class="container">
            <div class="row">
                <main class="col-xl-8 col-lg-8">
                    <article class="cms-article-main mb-4" data-aos="fade-up">
                        <h1 class="cms-article-title">{{ $content->title }}</h1>
                        <div class="article-meta mt-2">
                            <small>
                            <i class="bi bi-calendar3"></i>
                            {{ $content->published_at ? $content->published_at->format('F d, Y') : $content->created_at->format('F d, Y') }}
                            &nbsp; • &nbsp;
                            <i class="bi bi-person"></i>
                            {{ $content->author ?? ($content->custom_fields['author_name'] ?? ($settings['brand_name'] ?? 'Editorial Team')) }}
                            @if (isset($content->custom_fields['read_time']))
                                &nbsp; • &nbsp; {{ $content->custom_fields['read_time'] }}
                            @endif
                            </small>
                        </div>
                        <div class="article-image mb-4">
                            <img src="{{ $content->thumbnail && s3_asset($content->thumbnail) ? s3_asset($content->thumbnail) : s3_asset($settings['cms_content_placeholder_image'] ?? 'assets/img/default-blog.jpg') }}"
                                alt="{{ $content->title }}">
                        </div>

                        @if ($content->excerpt)
                            <p class="lead text-muted">{{ $content->excerpt }}</p>
                        @endif
                        
                        <div class="content-body mt-3" id="articleBody">
                            {!! $content->body ?? '' !!}
                        </div>

                        @if ($content->gallery_images && count($content->gallery_images) > 0)
                            <div class="row g-3 mt-4">
                                @foreach ($content->gallery_images as $index => $image)
                                    <div class="col-md-6">
                                        <img src="{{ s3_asset($image) }}" alt="{{ $content->title }} gallery"
                                            class="img-fluid rounded">
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($content->custom_fields && isset($content->custom_fields['quote']))
                            <blockquote class="custom-quote mt-4">
                                <p class="mb-2">"{{ $content->custom_fields['quote'] }}"</p>
                                <footer class="text-muted">— {{ $content->custom_fields['quote_author'] ?? 'Anonymous' }}
                                    @if (isset($content->custom_fields['quote_author_title']))
                                        , <small>{{ $content->custom_fields['quote_author_title'] }}</small>
                                    @endif
                                </footer>
                            </blockquote>
                        @endif

                        {{-- @if ($content->custom_fields && isset($content->custom_fields['tags']) && count($content->custom_fields['tags']) > 0)
                            <div class="mt-4">
                                <h6>Tags</h6>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach ($content->custom_fields['tags'] as $tag)
                                        <a href="{{ route('cms.search') }}?q={{ urlencode($tag) }}" class="btn btn-outline-secondary btn-sm">{{ $tag }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endif --}}


                    </article>

                </main>

                <aside class="col-xl-4 col-lg-4">
                    <div class="article-sidebar">
                        <div class="sidebar-widget share-widget">
                            <h6 class="mb-3">Share this article</h6>
                            <div class="share-btns d-flex">
                                <a class="facebook"
                                    href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(request()->fullUrl()) }}"
                                    target="_blank" aria-label="Share on Facebook"><i class="bi bi-facebook"></i></a>
                                <a class="twitter"
                                    href="https://twitter.com/intent/tweet?url={{ urlencode(request()->fullUrl()) }}&text={{ urlencode($content->title) }}"
                                    target="_blank" aria-label="Share on Twitter"><i class="bi bi-twitter"></i></a>
                                <a class="linkedin"
                                    href="https://www.linkedin.com/sharing/share-offsite/?url={{ urlencode(request()->fullUrl()) }}"
                                    target="_blank" aria-label="Share on LinkedIn"><i class="bi bi-linkedin"></i></a>
                                <a class="email"
                                    href="mailto:?subject={{ urlencode($content->title) }}&body={{ urlencode(request()->fullUrl()) }}"
                                    aria-label="Share by email"><i class="bi bi-envelope"></i></a>
                            </div>
                        </div>

                        <div class="sidebar-widget">
                            <h6 class="mb-3">Article info</h6>
                            <ul class="list-unstyled mb-0 small text-muted">
                                <li><strong>Published:</strong>
                                    {{ $content->published_at ? $content->published_at->format('F d, Y') : $content->created_at->format('F d, Y') }}
                                </li>
                                @if ($content->views_count > 0)
                                    <li><strong>Views:</strong> {{ number_format($content->views_count) }}</li>
                                @endif
                                @if ($content->custom_fields && isset($content->custom_fields['read_time']))
                                    <li><strong>Read time:</strong> {{ $content->custom_fields['read_time'] }}</li>
                                @endif
                            </ul>
                        </div>

                        @if ($relatedContents && $relatedContents->count() > 0)
                            <div class="sidebar-widget">
                                <h6 class="mb-3">Related {{ $contentType->title }}</h6>
                                @foreach ($relatedContents as $related)
                                    <a href="{{ route('cms.show', [$contentType->slug, $related->slug]) }}"
                                        class="d-block text-decoration-none text-dark mb-2">
                                        <div class="related-item">
                                            <img src="{{ $related->thumbnail ? s3_asset($related->thumbnail) : s3_asset($settings['cms_content_placeholder_image'] ?? 'assets/img/default-blog.jpg') }}"
                                                alt="{{ $related->title }}">
                                            <div>
                                                <div class="small fw-bold">{{ Str::limit($related->title, 60) }}</div>
                                                <div class="small text-muted">
                                                    {{ $related->published_at ? $related->published_at->format('M d, Y') : $related->created_at->format('M d, Y') }}
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif

                    </div>
                </aside>
            </div>

            {{-- Booking Integration --}}
            <div class="booking-section cms-booking-section mb-5" id="booking-section">

                {{-- Prepare search object from content when location defaults are available --}}
                @php
                    $hasContentBookingLocations = !empty($content->pickup_location) || !empty($content->dropoff_location);

                    if (!isset($search) && isset($content) && $hasContentBookingLocations) {
                        $search = new \stdClass();
                        $search->service_type = $content->service_type;

                        $search->pickup_location = [
                            'address' => $content->pickup_location,
                            'lat' => $content->pickup_lat,
                            'lng' => $content->pickup_lng,
                        ];

                        $search->dropoff_location = [
                            'address' => $content->dropoff_location,
                            'lat' => $content->dropoff_lat,
                            'lng' => $content->dropoff_lng,
                        ];

                        $search->pickup_date = now()->format('Y-m-d');
                        $minDays = $content->min_days ?? 1;
                        if ((int) $minDays > 1) {
                            $search->dropoff_date = now()
                                ->addDays($minDays - 1)
                                ->format('Y-m-d');
                        } else {
                            $search->dropoff_date = $search->pickup_date;
                        }

                        $search->pickup_time = null;
                        $search->dropoff_time = null;
                    }
                @endphp

                <div class="cms-booking-header">
                    <h2>Book Your Ride</h2>
                    <p>
                        {{ $hasContentBookingLocations
                            ? 'The form is prefilled from this page where location data is available.'
                            : 'Use the standard booking form with the same default values used on the home page.' }}
                    </p>
                </div>

                <div class="filter-wrapper text-center hotel mb-5">
                    @include('components.booking-form', ['search' => $search ?? null])
                </div>

                {{-- Suggested Vehicles --}}
                @if ($hasContentBookingLocations && isset($suggestedVehicles) && count($suggestedVehicles) > 0)
                    <div class="suggested-vehicles mt-5">
                        <h4 class="mb-4">Recommended Vehicles for Your Journey</h4>
                        <div class="row g-4">
                            @foreach ($suggestedVehicles as $index => $vehicleData)
                                <div class="col-lg-3 col-md-4 col-sm-6">
                                    <x-vehicle-card :vehicle="$vehicleData" :pricing="$vehicleData['pricing_info'] ?? ($vehicleData['pricing'] ?? [])" :enhancedPricing="$vehicleData['enhanced_pricing'] ?? []"
                                        :serviceFeatures="$vehicleData['service_features'] ?? []" :availability="[
                                            'available' =>
                                                $vehicleData['available_count'] ??
                                                ($vehicleData['availability']['available'] ?? 0),
                                            'total' =>
                                                $vehicleData['total_count'] ??
                                                ($vehicleData['availability']['total'] ?? 0),
                                        ]" :searchId="session('current_search_id')" :showBookNow="true"
                                        :showViewDetails="false" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @elseif($hasContentBookingLocations && isset($search) && !empty($search->from_date))
                    <div class="suggested-vehicles mt-5">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            No vehicles found matching the criteria from this content. Please adjust the search
                            above.
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <!-- Cart Summary Float Component -->
    <x-cart-summary-float />

    <!-- Request Quotation Modal (used by vehicle-card) -->
    <div class="modal fade" id="requestQuotationModal" tabindex="-1" aria-labelledby="requestQuotationModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="requestQuotationModalLabel"><i class="bi bi-calculator"></i> Request
                        Quotation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="quotationRequestForm" method="POST" action="{{ route('quotation.request') }}">
                    @csrf
                    <div class="modal-body">
                        <input type="hidden" name="vehicle_group_id" id="quotation_vehicle_group_id" value="">
                        <input type="hidden" name="search_id" id="quotation_search_id"
                            value="{{ session('current_search_id') ?? '' }}">
                        <div class="mb-3">
                            <label for="quotation_customer_name" class="form-label">Your Name</label>
                            <input type="text" class="form-control" id="quotation_customer_name" name="customer_name"
                                required>
                        </div>
                        <div class="mb-3">
                            <label for="quotation_customer_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="quotation_customer_email"
                                name="customer_email" required>
                        </div>
                        <div class="mb-3">
                            <label for="quotation_phone_country" class="form-label">Country</label>
                            <select class="form-select no-nice quotation-phone-country-select" id="quotation_phone_country"
                                name="phone_country" required>
                                <option value="">Select Country</option>
                                @foreach ($quotationCountries as $country)
                                    <option value="{{ strtolower($country->code ?? '') }}"
                                        {{ strtolower($country->code ?? '') === 'lk' ? 'selected' : '' }}>
                                        {{ $country->name }}
                                        @if ($country->callcode)
                                            (+{{ $country->callcode }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="quotation_customer_phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control quotation-phone-input" id="quotation_customer_phone"
                                name="phone" required>
                            <input type="hidden" name="phone_country_code" class="quotation-phone-country-code">
                            <input type="hidden" name="phone_international" class="quotation-phone-international">
                        </div>
                        <div class="mb-3">
                            <label for="quotation_message" class="form-label">Message</label>
                            <textarea class="form-control" id="quotation_message" name="message" rows="4"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-warning">Submit Request</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/intlTelInput.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        (function() {
            const form = document.getElementById('quotationRequestForm');
            const phoneInput = form ? form.querySelector('.quotation-phone-input') : null;
            const countrySelect = form ? form.querySelector('.quotation-phone-country-select') : null;
            let quotationPhoneIti = null;

            if (countrySelect && $.fn.select2) {
                $(countrySelect).next('.nice-select').remove();
                $(countrySelect).select2({
                    placeholder: 'Select Country',
                    allowClear: true,
                    width: '100%',
                    dropdownParent: $('#requestQuotationModal')
                });
            }

            if (phoneInput && typeof window.intlTelInput === 'function') {
                quotationPhoneIti = window.intlTelInput(phoneInput, {
                    initialCountry: countrySelect ? countrySelect.value : 'lk',
                    preferredCountries: ['lk', 'in', 'us', 'gb', 'ca', 'au'],
                    separateDialCode: true,
                    formatAsYouType: true,
                    utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
                });

                phoneInput.addEventListener('countrychange', function() {
                    const countryData = quotationPhoneIti.getSelectedCountryData();
                    form.querySelector('.quotation-phone-country-code').value = countryData.dialCode || '';
                    if (countrySelect && countryData.iso2) {
                        countrySelect.value = countryData.iso2;
                    }
                });

                if (countrySelect) {
                    $(countrySelect).on('change', function() {
                        quotationPhoneIti.setCountry(this.value);
                    });
                }
            }

            if (form) {
                form.addEventListener('submit', function() {
                    if (!quotationPhoneIti) return;

                    const countryData = quotationPhoneIti.getSelectedCountryData();
                    form.querySelector('.quotation-phone-country-code').value = countryData.dialCode || '';
                    form.querySelector('.quotation-phone-international').value = quotationPhoneIti.getNumber() || '';
                }, true);
            }
        })();

        (function() {
            const enforceCmsHeaderState = () => {
                const header = document.querySelector('header.header-area.style-2.travel-agency3');
                if (!header) return;
                header.classList.add('sticky');
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', enforceCmsHeaderState, {
                    once: true
                });
            } else {
                enforceCmsHeaderState();
            }

            window.addEventListener('scroll', enforceCmsHeaderState, {
                passive: true
            });
        })();

        // Reading progress bar
        (function() {
            const progress = document.getElementById('readingProgress');
            const article = document.getElementById('articleBody');
            if (!progress || !article) return;

            const updateProgress = () => {
                const rect = article.getBoundingClientRect();
                const elementTop = rect.top + window.scrollY;
                const elementHeight = article.offsetHeight;
                const windowScroll = window.scrollY;
                const windowHeight = window.innerHeight;

                const maxScroll = elementTop + elementHeight - windowHeight;
                const percent = Math.min(100, Math.max(0, ((windowScroll - elementTop) / (elementHeight -
                    windowHeight)) * 100));
                progress.style.width = percent + '%';
            };

            document.addEventListener('scroll', updateProgress, {
                passive: true
            });
            window.addEventListener('resize', updateProgress);
            // initial
            setTimeout(updateProgress, 300);
        })();

        // Initialize AOS if available
        if (typeof AOS !== 'undefined') {
            AOS.refresh();
        }

        // Expose search data globally for vehicle-card-scripts component to use
        // This allows the component to get booking parameters when adding to cart
        @if (isset($search))
            @php
                // Safely extract location data
                $pickupAddress = '';
                $pickupLat = null;
                $pickupLng = null;
                $dropoffAddress = '';
                $dropoffLat = null;
                $dropoffLng = null;

                if (isset($search->pickup_location)) {
                    if (is_array($search->pickup_location)) {
                        $pickupAddress = $search->pickup_location['address'] ?? '';
                        // Handle nested array case (malformed data from session)
                        if (is_array($pickupAddress)) {
                            $pickupAddress = $pickupAddress['address'] ?? '';
                        }
                        $pickupLat = $search->pickup_location['lat'] ?? $search->pickup_location['latitude'] ?? null;
                        $pickupLng = $search->pickup_location['lng'] ?? $search->pickup_location['longitude'] ?? null;
                    } elseif (is_string($search->pickup_location)) {
                        $pickupAddress = $search->pickup_location;
                    }
                }

                if (isset($search->dropoff_location)) {
                    if (is_array($search->dropoff_location)) {
                        $dropoffAddress = $search->dropoff_location['address'] ?? '';
                        // Handle nested array case (malformed data from session)
                        if (is_array($dropoffAddress)) {
                            $dropoffAddress = $dropoffAddress['address'] ?? '';
                        }
                        $dropoffLat = $search->dropoff_location['lat'] ?? $search->dropoff_location['latitude'] ?? null;
                        $dropoffLng = $search->dropoff_location['lng'] ?? $search->dropoff_location['longitude'] ?? null;
                    } elseif (is_string($search->dropoff_location)) {
                        $dropoffAddress = $search->dropoff_location;
                    }
                }
            @endphp
            window.bookingSearchData = {
                from_date: '{{ $search->from_date ?? ($search->pickup_date ?? '') }}',
                to_date: '{{ $search->to_date ?? ($search->dropoff_date ?? '') }}',
                from_time: '{{ $search->from_time ?? ($search->pickup_time ?? '') }}',
                to_time: '{{ $search->to_time ?? ($search->dropoff_time ?? '') }}',
                service_type: '{{ $search->service_type ?? '' }}',
                pickup_location: '{{ $pickupAddress }}',
                pickup_lat: {{ $pickupLat ?? 'null' }},
                pickup_lng: {{ $pickupLng ?? 'null' }},
                dropoff_location: '{{ $dropoffAddress }}',
                dropoff_lat: {{ $dropoffLat ?? 'null' }},
                dropoff_lng: {{ $dropoffLng ?? 'null' }},
                service_package_id: '{{ $search->service_package_id ?? ($search->package_id ?? '') }}',
                package_id: '{{ $search->service_package_id ?? ($search->package_id ?? '') }}',
                is_return_trip: {{ isset($search->is_return_trip) && $search->is_return_trip ? 'true' : 'false' }},
                return_trip_date: '{{ $search->return_date ?? '' }}',
                return_trip_time: '{{ $search->return_time ?? '' }}'
            };
        @endif
    </script>
@endpush
