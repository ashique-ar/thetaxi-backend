@extends('layouts.app')

@section('title', $contentType->title . ' - TheTaxi')

@section('content')
    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url(assets/img/innerpages/breadcrumb-bg2.jpg);">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $contentType->title }}</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>{{ $contentType->title }}</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Travel Inspiration Page Start-->
    <div class="travel-inspiration-page pt-100 mb-100">
        <div class="container">
            <div class="row gy-md-5 gy-4 mb-60">
                @forelse($contents as $index => $content)
                    <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="{{ ($index % 3) * 200 + 200 }}ms"
                        data-wow-duration="1500ms">
                        <div class="blog-card2 two">
                            <div class="blog-img-wrap">
                                <a href="{{ route('cms.show', [$contentType->slug, $content->slug]) }}" class="blog-img">
                                    <img src="{{ $content->thumbnail ? s3_asset($content->thumbnail) : 'assets/img/home3/blog-img1.jpg' }}"
                                        alt="{{ $content->title }}">
                                </a>
                                <a href="{{ route('cms.index', $contentType->slug) }}" class="location">
                                    <svg width="14" height="14" viewBox="0 0 14 14"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                        <path
                                            d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                    </svg>
                                    {{ $contentType->title }}
                                </a>
                            </div>
                            <div class="blog-content">
                                <a href="{{ route('cms.index', $contentType->slug) }}"
                                    class="blog-date">{{ $content->published_at ? $content->published_at->format('d F, Y') : $content->created_at->format('d F, Y') }}</a>
                                <h4><a
                                        href="{{ route('cms.show', [$contentType->slug, $content->slug]) }}">{{ $content->title }}</a>
                                </h4>
                                <p>{{ $content->excerpt ?? Str::limit(strip_tags($content->content), 150) }}</p>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="text-center py-5">
                            <h3>No {{ strtolower($contentType->title) }} found</h3>
                            <p>There are currently no published {{ strtolower($contentType->title) }} available.</p>
                        </div>
                    </div>
                @endforelse
            </div>

            @if ($contents->hasPages())
                <div class="pagination-area wow animate fadeInUp" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="paginations-button">
                        @if ($contents->previousPageUrl())
                            <a href="{{ $contents->previousPageUrl() }}">
                                <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M8.75 1.25L3.75 5L8.75 8.75" stroke="currentColor" stroke-width="1.5"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                Prev
                            </a>
                        @endif
                    </div>
                    <ul class="paginations">
                        @foreach ($contents->getUrlRange(1, $contents->lastPage()) as $page => $url)
                            <li class="page-item {{ $page == $contents->currentPage() ? 'active' : '' }}">
                                <a href="{{ $url }}">{{ sprintf('%02d', $page) }}</a>
                            </li>
                        @endforeach
                    </ul>
                    <div class="paginations-button">
                        @if ($contents->nextPageUrl())
                            <a href="{{ $contents->nextPageUrl() }}">
                                Next
                                <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1.25 1.25L6.25 5L1.25 8.75" stroke="currentColor" stroke-width="1.5"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
    <!--Content Listing Page End-->
@endsection
