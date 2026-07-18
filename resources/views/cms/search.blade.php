@extends('layouts.app')
@section('seo_exact_title', 'true')

@section('title', 'Search Results - ' . ($settings['site_name'] ?? $settings['brand_name'] ?? 'Company') . '')

@push('meta')
    @include('partials.seo', ['managedSeo' => true])
@endpush

@section('content')
    <!-- Breadcrumb section Start-->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url(assets/img/innerpages/breadcrumb-bg.jpg);">
        <div class="container">
            <div class="banner-content">
                <h1>Search Results</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ url('/') }}">Home</a></li>
                    <li>Search</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- Breadcrumb section End-->

    <!-- Search Results Page Start-->
    <div class="travel-inspiration-page pt-100 mb-100">
        <div class="container">
            <!-- Search Form -->
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-8 col-lg-10">
                    <div class="search-form-area bg-light p-4 rounded">
                        <form action="{{ route('cms.search') }}" method="GET" class="search-form">
                            <div class="row g-3 align-items-end">
                                <div class="col-md-6">
                                    <label for="search-query" class="form-label">Search Query</label>
                                    <input type="text" id="search-query" name="q" placeholder="Search content..."
                                        value="{{ $query }}" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label for="content-type" class="form-label">Category</label>
                                    <select id="content-type" name="type" class="form-select">
                                        <option value="">All Categories</option>
                                        @foreach ($contentTypes as $type)
                                            <option value="{{ $type->slug }}"
                                                {{ $contentTypeSlug === $type->slug ? 'selected' : '' }}>
                                                {{ $type->title }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="primary-btn1 w-100">
                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M7.33333 12.6667C10.2789 12.6667 12.6667 10.2789 12.6667 7.33333C12.6667 4.38781 10.2789 2 7.33333 2C4.38781 2 2 4.38781 2 7.33333C2 10.2789 4.38781 12.6667 7.33333 12.6667Z"
                                                stroke="currentColor" stroke-width="1.33333" stroke-linecap="round"
                                                stroke-linejoin="round" />
                                            <path d="M14 14L11.1 11.1" stroke="currentColor" stroke-width="1.33333"
                                                stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                        Search
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Booking Search Section -->
            <div class="row justify-content-center mb-50">
                <div class="col-xl-10">
                    <div class="booking-search-area bg-white p-4 rounded shadow-sm">
                        <h4 class="mb-3 text-center">Plan Your Trip</h4>
                        @include('components.booking-form', ['search' => $search ?? null])
                    </div>
                </div>
            </div>

            <!-- Content Search Form -->
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-8 col-lg-10">
                    <div class="search-form-area bg-light p-4 rounded">
                        <h5 class="mb-3 text-center">Search Articles & Guides</h5>
                        <form action="{{ route('cms.search') }}" method="GET" class="search-form">
                            <div class="row justify-content-center mb-40">
                                <div class="col-xl-8 col-lg-10">
                                    <div class="search-results-info">
                                        <h4>
                                            @if ($contents->total() > 0)
                                                Found {{ $contents->total() }} result(s) for "{{ $query }}"
                                                @if ($contentTypeSlug && isset($contentTypes->firstWhere('slug', $contentTypeSlug)->title))
                                                    in {{ $contentTypes->firstWhere('slug', $contentTypeSlug)->title }}
                                                @endif
                                            @else
                                                No results found for "{{ $query }}"
                                                @if ($contentTypeSlug && isset($contentTypes->firstWhere('slug', $contentTypeSlug)->title))
                                                    in {{ $contentTypes->firstWhere('slug', $contentTypeSlug)->title }}
                                                @endif
                                            @endif
                                        </h4>
                                    </div>
                                </div>
                            </div>
                            @endif

                            @if ($contents->count() > 0)
                                <div class="row gy-md-5 gy-4 mb-60 align-items-stretch">
                                    @foreach ($contents as $index => $content)
                                        <div class="col-lg-4 col-md-6 wow animate fadeInDown"
                                            data-wow-delay="{{ 200 + $index * 200 }}ms" data-wow-duration="1500ms">
                                            <div class="blog-card2 two">
                                                <div class="blog-img-wrap">
                                                    <a href="{{ route('cms.show', [$content->contentType->slug, $content->slug]) }}"
                                                        class="blog-img">
                                                        <img src="{{ $content->thumbnail && s3_asset($content->thumbnail) ? s3_asset($content->thumbnail) : s3_asset($settings['cms_content_placeholder_image'] ?? 'assets/img/default-blog.jpg') }}"
                                                            alt="{{ $content->title }}">
                                                    </a>
                                                    <a href="{{ route('cms.index', $content->contentType->slug) }}"
                                                        class="location">
                                                        <svg width="14" height="14" viewBox="0 0 14 14"
                                                            xmlns="http://www.w3.org/2000/svg">
                                                            <path
                                                                d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                                            <path
                                                                d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                                        </svg>
                                                        {{ $content->contentType->title }}
                                                    </a>
                                                </div>
                                                <div class="blog-content">
                                                    <a href="{{ route('cms.index', $content->contentType->slug) }}"
                                                        class="blog-date">
                                                        {{ $content->published_at ? $content->published_at->format('d F, Y') : $content->created_at->format('d F, Y') }}
                                                    </a>
                                                    <h4><a
                                                            href="{{ route('cms.show', [$content->contentType->slug, $content->slug]) }}">{{ $content->title }}</a>
                                                    </h4>
                                                    @if ($content->excerpt)
                                                        <p>{{ Str::limit($content->excerpt, 120) }}</p>
                                                    @else
                                                        <p>{{ Str::limit(strip_tags($content->body), 120) }}</p>
                                                    @endif
                                                    @if ($content->is_featured)
                                                        <span class="badge bg-primary">Featured</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($contents->hasPages())
                                    <div class="pagination-area wow animate fadeInUp" data-wow-delay="200ms"
                                        data-wow-duration="1500ms">
                                        @if ($contents->previousPageUrl())
                                            <div class="paginations-button">
                                                <a href="{{ $contents->appends(request()->query())->previousPageUrl() }}">
                                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <g>
                                                            <path
                                                                d="M7.86133 9.28516C7.14704 7.49944 3.57561 5.71373 1.43276 4.99944C3.57561 4.28516 6.7899 3.21373 7.86133 0.713728"
                                                                stroke-width="1.5" stroke-linecap="round" />
                                                        </g>
                                                    </svg>
                                                    Prev
                                                </a>
                                            </div>
                                        @endif

                                        <ul class="paginations">
                                            @foreach ($contents->appends(request()->query())->getUrlRange(1, $contents->lastPage()) as $page => $url)
                                                <li
                                                    class="page-item {{ $page == $contents->currentPage() ? 'active' : '' }}">
                                                    <a href="{{ $url }}">{{ sprintf('%02d', $page) }}</a>
                                                </li>
                                            @endforeach
                                        </ul>

                                        @if ($contents->nextPageUrl())
                                            <div class="paginations-button">
                                                <a href="{{ $contents->appends(request()->query())->nextPageUrl() }}">
                                                    Next
                                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                                        xmlns="http://www.w3.org/2000/svg">
                                                        <g>
                                                            <path
                                                                d="M1.42969 9.28613C2.14397 7.50042 5.7154 5.7147 7.85826 5.00042C5.7154 4.28613 2.50112 3.21471 1.42969 0.714705"
                                                                stroke-width="1.5" stroke-linecap="round" />
                                                        </g>
                                                    </svg>
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @elseif($query)
                                <div class="row">
                                    <div class="col-12 text-center">
                                        <div class="no-results-message py-5">
                                            <h3>No Results Found</h3>
                                            <p>Sorry, we couldn't find any content matching your search criteria.</p>
                                            <div class="mt-4">
                                                <h5>Try:</h5>
                                                <ul class="list-unstyled">
                                                    <li>• Using different keywords</li>
                                                    <li>• Checking your spelling</li>
                                                    <li>• Using more general terms</li>
                                                    <li>• Browsing our content categories</li>
                                                </ul>
                                            </div>
                                            <div class="mt-4">
                                                @foreach ($contentTypes as $type)
                                                    <a href="{{ route('cms.index', $type->slug) }}"
                                                        class="btn btn-outline-primary me-2 mb-2">
                                                        {{ $type->title }}
                                                    </a>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div class="row">
                                    <div class="col-12 text-center">
                                        <div class="search-placeholder py-5">
                                            <h3>Search Our Content</h3>
                                            <p>Enter a search term above to find relevant content.</p>
                                            <div class="mt-4">
                                                <h5>Popular Categories:</h5>
                                                <div class="mt-3">
                                                    @foreach ($contentTypes as $type)
                                                        <a href="{{ route('cms.index', $type->slug) }}"
                                                            class="btn btn-outline-primary me-2 mb-2">
                                                            {{ $type->title }}
                                                        </a>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                    </div>
                </div>
                <!--Search Results Page End-->
            @endsection

            <!-- Search Results -->
            @if ($query)
                <div class="row mb-30">
                    <div class="col-12">
                        <div class="search-results-info">
                            <h4>Search Results for "{{ $query }}"</h4>
                            <p>Found {{ $contents->total() }} result(s)
                                @if ($contentTypeSlug)
                                    in
                                    {{ $contentTypes->where('slug', $contentTypeSlug)->first()->title ?? 'Unknown Category' }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <div class="row gy-md-5 gy-4 mb-60 align-items-stretch">
                @forelse($contents as $content)
                    <div class="col-lg-4 col-md-6 wow animate fadeInDown"
                        data-wow-delay="{{ ($loop->index % 3) * 200 + 200 }}ms" data-wow-duration="1500ms">
                        <div class="blog-card2 two">
                            <div class="blog-img-wrap">
                                <a href="{{ route('cms.show', [$content->contentType->slug, $content->slug]) }}"
                                    class="blog-img">
                                    <img src="{{ s3_asset($content->featured_image ?? ($content->thumbnail ?? ($settings['cms_content_placeholder_image'] ?? 'assets/img/default-blog.jpg'))) }}"
                                        alt="{{ $content->title }}">
                                </a>
                                @if ($content->is_featured)
                                    <a href="{{ route('cms.index', $content->contentType->slug) }}" class="location">
                                        <svg width="18" height="18" viewBox="0 0 18 18"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M8.994 0L11.092 6.169L18 6.177L12.456 10.254L14.547 16.425L8.994 12.358L3.453 16.425L5.544 10.254L0 6.177L6.908 6.169L8.994 0Z" />
                                        </svg>
                                        Featured
                                    </a>
                                @else
                                    <a href="{{ route('cms.index', $content->contentType->slug) }}" class="location">
                                        <svg width="18" height="18" viewBox="0 0 18 18"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <g clip-path="url(#clip0_1139_736)">
                                                <path
                                                    d="M15.364 7.36401H14.0519V6.05193C14.0519 5.34853 13.4953 4.79199 12.7919 4.79199H11.4798V3.47991C11.4798 2.77651 10.9233 2.21997 10.2199 2.21997H8.90777C8.20437 2.21997 7.64783 2.77651 7.64783 3.47991V4.79199H6.33575C5.63235 4.79199 5.07581 5.34853 5.07581 6.05193V7.36401H3.76373C3.06033 7.36401 2.50379 7.92055 2.50379 8.62395V9.93603C2.50379 10.6394 3.06033 11.196 3.76373 11.196H5.07581V12.5081C5.07581 13.2115 5.63235 13.768 6.33575 13.768H7.64783V15.0801C7.64783 15.7835 8.20437 16.34 8.90777 16.34H10.2199C10.9233 16.34 11.4798 15.7835 11.4798 15.0801V13.768H12.7919C13.4953 13.768 14.0519 13.2115 14.0519 12.5081V11.196H15.364C16.0674 11.196 16.6239 10.6394 16.6239 9.93603V8.62395C16.6239 7.92055 16.0674 7.36401 15.364 7.36401Z" />
                                            </g>
                                        </svg>
                                        {{ $content->contentType->title }}
                                    </a>
                                @endif
                            </div>
                            <div class="blog-content">
                                <a href="{{ route('cms.index', $content->contentType->slug) }}" class="blog-date">
                                    {{ $content->published_at ? $content->published_at->format('d F, Y') : $content->created_at->format('d F, Y') }}
                                </a>
                                <h4><a
                                        href="{{ route('cms.show', [$content->contentType->slug, $content->slug]) }}">{{ $content->title }}</a>
                                </h4>
                                <p>{{ $content->excerpt ?? Str::limit(strip_tags($content->body), 120) }}</p>
                                @if ($content->author)
                                    <div class="blog-author">
                                        <small>By {{ $content->author }}</small>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="text-center py-5">
                            @if ($query)
                                <h3>No results found</h3>
                                <p>Sorry, we couldn't find any content matching "{{ $query }}".</p>
                                <p>Try searching with different keywords or browse our categories below.</p>
                            @else
                                <h3>Start Your Search</h3>
                                <p>Use the search form above to find content across our site.</p>
                            @endif

                            <div class="mt-4">
                                <h5>Browse Categories</h5>
                                <div class="category-links">
                                    @foreach ($contentTypes as $type)
                                        <a href="{{ route('cms.index', $type->slug) }}"
                                            class="btn btn-outline-primary btn-sm me-2 mb-2">
                                            @if ($type->icon)
                                                <i class="{{ $type->icon }}"></i>
                                            @endif
                                            {{ $type->title }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforelse
            </div>

            @if ($contents->hasPages())
                <div class="pagination-area wow animate fadeInUp" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="paginations-button">
                        @if ($contents->previousPageUrl())
                            <a href="{{ $contents->appends(request()->query())->previousPageUrl() }}">
                                <svg width="10" height="10" viewBox="0 0 10 10"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M8.75 1.25L3.75 5L8.75 8.75" stroke="currentColor" stroke-width="1.5"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                Prev
                            </a>
                        @endif
                    </div>
                    <ul class="paginations">
                        @foreach ($contents->appends(request()->query())->getUrlRange(1, $contents->lastPage()) as $page => $url)
                            <li class="page-item {{ $page == $contents->currentPage() ? 'active' : '' }}">
                                <a href="{{ $url }}">{{ sprintf('%02d', $page) }}</a>
                            </li>
                        @endforeach
                    </ul>
                    <div class="paginations-button">
                        @if ($contents->nextPageUrl())
                            <a href="{{ $contents->appends(request()->query())->nextPageUrl() }}">
                                Next
                                <svg width="10" height="10" viewBox="0 0 10 10"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M1.25 1.25L6.25 5L1.25 8.75" stroke="currentColor" stroke-width="1.5"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </a>
                        @endif
                    </div>
                </div>
            @endif
            <!--Search Results Page End-->
        @endsection
