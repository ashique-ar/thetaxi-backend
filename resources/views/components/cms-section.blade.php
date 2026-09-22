@props([
    'title',
    'description' => '',
    'items',
    'type' => 'blog',
    'showPrice' => false,
    'showDuration' => false,
    'showRating' => false,
    'viewAllLink' => '#',
    'viewAllText' => 'View All',
    'sectionId' => '',
    'limit' => 6,
    'fallbackItems' => [],
    'customTemplate' => null
])

@php
    $displayItems = $items->count() > 0 ? $items->take($limit) : collect($fallbackItems)->take($limit);
    $sectionClass = $customTemplate === 'blog-card2' ? 'home4-blog-section' : 'home3-travel-inspiration-section';
@endphp

<!-- CMS Content Section Start -->
<div class="{{ $sectionClass }} cms-content-section" @if($sectionId) id="{{ $sectionId }}" @endif>
    <div class="container">
        <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
            <div class="col-xl-6 col-lg-8">
                <div class="section-title text-center">
                    <h2>{{ $title }}</h2>
                    @if($description)
                        <p>{{ $description }}</p>
                    @endif
                </div>
            </div>
        </div>
        
        <div class="row g-4 mb-40 align-items-stretch">
            @if($displayItems->count() > 0)
                @foreach($displayItems as $index => $item)
                    <x-cms-card 
                        :item="$item" 
                        :delayMs="($index + 1) * 200"
                        :type="$type"
                        :showPrice="$showPrice"
                        :showDuration="$showDuration"
                        :showRating="$showRating"
                        :template="$customTemplate"
                    />
                @endforeach
            @else
                <!-- Fallback content when no items available -->
                <div class="col-12 text-center">
                    <p class="text-muted">No {{ $type }} content available at the moment.</p>
                </div>
            @endif
        </div>
        
        @if($displayItems->count() > 0)
            <div class="row wow animate fadeInUp" data-wow-delay="200ms" data-wow-duration="1500ms">
                <div class="col-lg-12 d-flex justify-content-center">
                    <a href="{{ route('cms.index', ['contentType' => $type]) }}" class="primary-btn1 two transparent">
                        <span>
                            {{ $viewAllText }}
                            <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z" />
                            </svg>
                        </span>
                        <span>
                            {{ $viewAllText }}
                            <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z" />
                            </svg>
                        </span>
                    </a>
                </div>
            </div>
        @endif
    </div>
</div>
<!-- CMS Content Section End -->

@once
    @push('styles')
        <style>
            .cms-content-section {
                position: relative;
                overflow: hidden;
            }

            .cms-content-section .section-title {
                max-width: 760px;
                margin: 0 auto;
            }

            .cms-content-section .section-title h2 {
                letter-spacing: 0;
            }

            .cms-content-section .section-title p {
                color: #64748b;
                line-height: 1.7;
            }

            .cms-content-section .primary-btn1.two.transparent {
                border-radius: 8px;
                min-height: 48px;
                padding-inline: 22px;
            }

            @media (max-width: 575px) {
                .cms-content-section .container {
                    padding-inline: 16px;
                }

                .cms-content-section .section-title h2 {
                    font-size: clamp(1.75rem, 9vw, 2.35rem);
                    overflow-wrap: anywhere;
                }

                .cms-content-section .section-title p {
                    font-size: 0.95rem;
                    line-height: 1.55;
                }

                .cms-content-section :where(.mb-50, .mb-40) {
                    margin-bottom: 28px;
                }
            }
        </style>
    @endpush
@endonce
