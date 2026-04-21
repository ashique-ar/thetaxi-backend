@extends('layouts.app')

@section('title', $content->meta_title ?? $content->title . ' - TheTaxi')

@push('meta')
    @include('partials.seo', ['model' => $content])
@endpush

@push('styles')
    <style>
        /* Article show improvements */
        .article-hero {
            background: linear-gradient(180deg, rgba(0, 0, 0, 0.35), rgba(0, 0, 0, 0.35)), url('{{ $content->thumbnail ? s3_asset($content->thumbnail) : asset('assets/img/innerpages/breadcrumb-bg.jpg') }}') center/cover no-repeat;
            padding: 60px 0;
            color: #fff;
        }

        .article-hero h1 {
            font-size: 2.4rem;
            font-weight: 700;
        }

        .article-page {
            padding: 60px 0;
        }

        .cms-article-page {
            padding-top: 110px;
        }

        .article-image img {
            width: 100%;
            height: auto;
            border-radius: 10px;
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
            padding: 18px;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
        }

        .related-item {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
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
            width: 38px;
            height: 38px;
            border-radius: 6px;
            margin-right: 8px;
            color: #fff;
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

        /* Ensure article content wraps and long words / non-breaking spaces don't force horizontal scrolling */
        .content-body {
            white-space: normal !important;
            word-wrap: break-word !important;
            overflow-wrap: anywhere !important;
            word-break: break-word !important;
            hyphens: auto !important;
        }

        .content-body img {
            max-width: 100% !important;
            height: auto !important;
            display: block;
            margin: 12px 0;
        }

        .cms-booking-section {
            margin-top: 48px;
            padding-top: 8px;
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
            .article-sidebar {
                position: static;
                top: auto;
            }
        }

        @media (max-width: 1199px) {
            .cms-article-page {
                padding-top: 90px;
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
                    <h1>{{ $content->title }}</h1>
                    <div class="article-meta mt-2">
                        <small>
                            <i class="bi bi-calendar3"></i>
                            {{ $content->published_at ? $content->published_at->format('F d, Y') : $content->created_at->format('F d, Y') }}
                            &nbsp; • &nbsp;
                            <i class="bi bi-person"></i>
                            {{ $content->author ?? ($content->custom_fields['author_name'] ?? 'TheTaxi') }}
                            @if (isset($content->custom_fields['read_time']))
                                &nbsp; • &nbsp; {{ $content->custom_fields['read_time'] }}
                            @endif
                        </small>
                    </div>
                    <article class="mb-4" data-aos="fade-up">
                        <div class="article-image mb-4">
                            <img src="{{ $content->thumbnail && s3_asset($content->thumbnail) ? s3_asset($content->thumbnail) : asset('assets/img/default-blog.jpg') }}"
                                alt="{{ $content->title }}">
                        </div>

                        @if ($content->excerpt)
                            <p class="lead text-muted">{{ $content->excerpt }}</p>
                        @endif

                        @php
                            $rawBody = $content->body ?? '';
                            // Replace HTML entity non-breaking spaces and unicode NBSP with regular spaces
                            $body = str_replace('&nbsp;', ' ', $rawBody);
                            $body = preg_replace('/\x{00A0}/u', ' ', $body);
                            // Collapse sequences of multiple spaces into a single space (avoid runaway spacing)
                            $body = preg_replace('/[ \t]{2,}/', ' ', $body);
                        @endphp
                        <div class="content-body" id="articleBody">
                            {!! $body !!}
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
                                            <img src="{{ $related->thumbnail ? s3_asset($related->thumbnail) : asset('assets/img/default-blog.jpg') }}"
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
    <script>
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
