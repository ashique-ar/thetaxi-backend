@extends('layouts.app')
@section('seo_exact_title', 'true')

@section('title', 'Featured Content - ' . ($settings['site_name'] ?? $settings['brand_name'] ?? 'Company') . '')

@push('meta')
    @include('partials.seo', ['managedSeo' => true])
@endpush

@section('content')
    <!-- Breadcrumb section Start-->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url(assets/img/innerpages/breadcrumb-bg.jpg);">
        <div class="container banner-content">
            <div class="">
                <h1>Featured Content</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>Featured</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- Breadcrumb section End-->

    <!-- Featured Content Page Start-->
    <div class="travel-inspiration-page pt-2 mb-100">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-8 col-lg-10">
                    <div class="section-title text-center">
                        <h2>Featured Content</h2>
                        <p>Discover our most popular and trending content across all categories.</p>
                    </div>
                </div>
            </div>

            <div class="row gy-md-5 gy-4 mb-60">
                @forelse($featuredContent as $content)
                    <div class="col-lg-4 col-md-6 wow animate fadeInDown"
                        data-wow-delay="{{ ($loop->index % 3) * 200 + 200 }}ms" data-wow-duration="1500ms">
                        <div class="blog-card2 two">
                            <div class="blog-img-wrap">
                                <a href="{{ route('cms.show', [$content->contentType->slug, $content->slug]) }}"
                                    class="blog-img">
                                    <img src="{{ s3_asset($content->featured_image ?? ($content->thumbnail ?? ($settings['cms_content_placeholder_image'] ?? 'assets/img/default-blog.jpg'))) }}"
                                        alt="{{ $content->title }}">
                                </a>
                                <a href="{{ route('cms.index', $content->contentType->slug) }}" class="location">
                                    <svg width="18" height="18" viewBox="0 0 18 18"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M8.994 0L11.092 6.169L18 6.177L12.456 10.254L14.547 16.425L8.994 12.358L3.453 16.425L5.544 10.254L0 6.177L6.908 6.169L8.994 0Z" />
                                    </svg>
                                    Featured
                                </a>
                            </div>
                            <div class="blog-content">
                                <a href="{{ route('cms.index', $content->contentType->slug) }}" class="blog-date">
                                    {{ $content->published_at ? $content->published_at->format('d F, Y') : $content->created_at->format('d F, Y') }}
                                </a>
                                <span class="content-type">{{ $content->contentType->title }}</span>
                                <h4><a
                                        href="{{ route('cms.show', [$content->contentType->slug, $content->slug]) }}">{{ $content->title }}</a>
                                </h4>
                                @if ($content->excerpt)
                                    <p>{{ Str::limit($content->excerpt, 120) }}</p>
                                @else
                                    <p>{{ Str::limit(strip_tags($content->body), 120) }}</p>
                                @endif
                                @if ($content->author)
                                    <div class="blog-author">
                                        <small>By {{ $content->author }}</small>
                                    </div>
                                @endif
                                @if ($content->views_count > 0)
                                    <div class="blog-views">
                                        <small>{{ number_format($content->views_count) }} views</small>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="text-center py-5">
                            <h3>No featured content available</h3>
                            <p>There is currently no featured content to display.</p>
                            <a href="{{ route('home') }}" class="primary-btn1 mt-3">Explore All Content</a>
                        </div>
                    </div>
                @endforelse
            </div>

            @if ($featuredContent->hasPages())
                <div class="pagination-area wow animate fadeInUp" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="paginations-button">
                        @if ($featuredContent->previousPageUrl())
                            <a href="{{ $featuredContent->previousPageUrl() }}">
                                <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M8.75 1.25L3.75 5L8.75 8.75" stroke="currentColor" stroke-width="1.5"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                Prev
                            </a>
                        @endif
                    </div>
                    <ul class="paginations">
                        @foreach ($featuredContent->getUrlRange(1, $featuredContent->lastPage()) as $page => $url)
                            <li class="page-item {{ $page == $featuredContent->currentPage() ? 'active' : '' }}">
                                <a href="{{ $url }}">{{ sprintf('%02d', $page) }}</a>
                            </li>
                        @endforeach
                    </ul>
                    <div class="paginations-button">
                        @if ($featuredContent->nextPageUrl())
                            <a href="{{ $featuredContent->nextPageUrl() }}">
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
    <!--Featured Content Page End-->
@endsection
