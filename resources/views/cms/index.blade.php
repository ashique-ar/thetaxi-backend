@extends('layouts.app')

@section('title', $contentType->title . ' - ' . ($settings['site_name'] ?? $settings['brand_name'] ?? 'Company') . '')
@section('seo_exact_title', 'true')

@push('meta')
    @include('partials.seo', [
        'pageTitle' => $contentType->title,
        'metaDescription' => $contentType->description ?? '',
        'managedSeo' => true,
    ])
@endpush

@push('styles')
    <style>
        /* Enhanced CMS Index Styling */
        .cms-header {
            background: linear-gradient(135deg, #BF2629 0%, #8B1A1C 100%);
            color: white;
            padding: 80px 0;
            position: relative;
            overflow: hidden;
        }

        .cms-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('assets/img/innerpages/breadcrumb-bg.jpg') center/cover;
            opacity: 0.1;
            z-index: 1;
        }

        .cms-header .container {
            position: relative;
            z-index: 2;
        }

        .cms-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .cms-header .subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 2rem;
        }

        .cms-filters {
            background: white;
            padding: 2rem 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 3rem;
        }

        .search-box {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .search-box input {
            width: 100%;
            padding: 15px 50px 15px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 50px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .search-box input:focus {
            border-color: #BF2629;
            outline: none;
            box-shadow: 0 0 0 3px rgba(191, 38, 41, 0.1);
        }

        .search-box .search-btn {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            background: #BF2629;
            border: none;
            padding: 10px 15px;
            border-radius: 50px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .search-box .search-btn:hover {
            background: #8B1A1C;
        }

        .filter-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .filter-tag {
            padding: 8px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 25px;
            background: white;
            color: #666;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .filter-tag:hover,
        .filter-tag.active {
            border-color: #BF2629;
            background: #BF2629;
            color: white;
        }

        .content-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .results-count {
            font-size: 1rem;
            color: #666;
        }

        .view-toggle {
            display: flex;
            gap: 5px;
        }

        .view-toggle button {
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-toggle button.active {
            background: #BF2629;
            color: white;
            border-color: #BF2629;
        }

        .enhanced-blog-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
            transition: all 0.4s ease;
            overflow: hidden;
            height: 100%;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .enhanced-blog-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .enhanced-blog-card .card-image {
            position: relative;
            overflow: hidden;
            height: 250px;
        }

        .enhanced-blog-card .card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }

        .enhanced-blog-card:hover .card-image img {
            transform: scale(1.1);
        }

        .enhanced-blog-card .card-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, transparent 0%, rgba(0, 0, 0, 0.7) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .enhanced-blog-card:hover .card-overlay {
            opacity: 1;
        }

        .enhanced-blog-card .category-badge {
            position: absolute;
            top: 15px;
            left: 15px;
            background: #BF2629;
            color: white;
            padding: 0 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .enhanced-blog-card .featured-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #FFD700;
            color: #333;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 700;
        }

        .enhanced-blog-card .card-content {
            padding: 25px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .enhanced-blog-card .card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 13px;
            color: #888;
        }

        .enhanced-blog-card .card-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 12px;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .enhanced-blog-card .card-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .enhanced-blog-card .card-title a:hover {
            color: #BF2629;
        }

        .enhanced-blog-card .card-excerpt {
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .enhanced-blog-card .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
            margin-top: auto;
        }

        .enhanced-blog-card .read-more {
            background: linear-gradient(135deg, #BF2629, #8B1A1C);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .enhanced-blog-card .read-more:hover {
            background: linear-gradient(135deg, #8B1A1C, #BF2629);
            transform: translateX(3px);
        }

        .list-view .enhanced-blog-card {
            display: flex;
            flex-direction: row;
            margin-bottom: 2rem;
        }

        .list-view .enhanced-blog-card .card-image {
            width: 300px;
            height: 200px;
            flex-shrink: 0;
        }

        .list-view .enhanced-blog-card .card-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .no-results {
            text-align: center;
            padding: 4rem 2rem;
            color: #666;
        }

        .no-results .icon {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 1rem;
        }

        .pagination-enhanced {
            margin-top: 4rem;
            padding: 2rem 0;
        }

        /* CMS pagination refinement */
        .pagination-enhanced .pagination-area {
            justify-content: center;
            gap: 12px;
            padding: 14px 18px;
            /* border: 1px solid #eee;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06); */
        }

        .pagination-enhanced .paginations {
            gap: 8px;
        }

        .pagination-enhanced .paginations .page-item a {
            width: auto;
            min-width: 40px;
            height: 40px;
            padding: 0 12px;
            border-radius: 10px;
            font-weight: 600;
            border-color: #e5e7eb;
        }

        .pagination-enhanced .paginations .page-item.disabled span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 12px;
            border-radius: 10px;
            color: #9ca3af;
            border: 1px solid #e5e7eb;
            font-weight: 600;
            background: #f9fafb;
        }

        .pagination-enhanced .paginations .page-item a:hover {
            background-color: #BF2629;
            color: #fff;
            border-color: #BF2629;
            transform: translateY(-1px);
        }

        .pagination-enhanced .paginations .page-item.active a {
            background-color: #BF2629;
            color: #fff;
            border-color: #BF2629;
            box-shadow: 0 6px 14px rgba(191, 38, 41, 0.25);
        }

        .pagination-enhanced .paginations-button a {
            min-width: 96px;
            max-width: none;
            height: 40px;
            border-radius: 10px;
            font-size: 14px;
            gap: 6px;
            border-color: #e5e7eb;
        }

        .pagination-enhanced .paginations-button a:hover {
            box-shadow: inset 0 0 0 10em #BF2629, 0 6px 14px rgba(191, 38, 41, 0.25);
        }

        @media (max-width: 576px) {
            .pagination-enhanced .pagination-area {
                padding: 12px;
                gap: 10px;
            }

            .pagination-enhanced .paginations .page-item a {
                min-width: 34px;
                height: 34px;
                padding: 0 10px;
                font-size: 12px;
            }

            .pagination-enhanced .paginations .page-item.disabled span {
                min-width: 34px;
                height: 34px;
                padding: 0 10px;
                font-size: 12px;
            }

            .pagination-enhanced .paginations-button a {
                min-width: 80px;
                height: 34px;
                font-size: 12px;
            }
        }

        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #BF2629;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .back-to-top.show {
            opacity: 1;
            visibility: visible;
        }

        .back-to-top:hover {
            background: #8B1A1C;
            transform: translateY(-2px);
        }

        /* Page loading state */
        body.loading {
            overflow: hidden;
        }

        body.loading .content-container {
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.5s ease;
        }

        body:not(.loading) .content-container {
            opacity: 1;
            transform: translateY(0);
        }

        /* Loading states */
        .loading {
            opacity: 0.6;
            pointer-events: none;
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #BF2629;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Search suggestions */
        .search-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .search-suggestion {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s ease;
        }

        .search-suggestion:hover {
            background-color: #f8f9fa;
        }

        .search-suggestion:last-child {
            border-bottom: none;
        }

        /* Enhanced hover effects */
        .enhanced-blog-card {
            transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
        }

        .enhanced-blog-card:hover {
            transform: translateY(-12px) rotateX(5deg);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
        }

        .filter-tag {
            position: relative;
            overflow: hidden;
        }

        .filter-tag::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .filter-tag:hover::before {
            left: 100%;
        }

        /* Enhanced card animations */
        .enhanced-blog-card .card-content {
            transition: all 0.3s ease;
        }

        .enhanced-blog-card:hover .card-content {
            transform: translateY(-5px);
        }

        .enhanced-blog-card .read-more {
            position: relative;
            overflow: hidden;
        }

        .enhanced-blog-card .read-more::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.6s ease;
        }

        .enhanced-blog-card .read-more:hover::before {
            left: 100%;
        }

        @media (max-width: 768px) {
            .cms-header h1 {
                font-size: 2rem;
            }

            .list-view .enhanced-blog-card {
                flex-direction: column;
            }

            .list-view .enhanced-blog-card .card-image {
                width: 100%;
                height: 200px;
            }

            .content-stats {
                flex-direction: column;
                gap: 1rem;
            }

            .filter-tags {
                justify-content: center;
            }

            .search-box {
                margin-bottom: 1rem;
            }

            .cms-filters {
                padding: 1.5rem 0;
            }

            .enhanced-blog-card:hover {
                transform: translateY(-5px);
            }
        }

        @media (max-width: 576px) {
            .cms-header {
                padding: 60px 0;
            }

            .cms-header h1 {
                font-size: 1.8rem;
            }

            .filter-tags {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-tag {
                text-align: center;
                margin-bottom: 0.5rem;
            }

            .view-toggle {
                justify-content: center;
            }

            .enhanced-blog-card .card-content {
                padding: 20px;
            }

            .enhanced-blog-card .card-title {
                font-size: 1.2rem;
            }
        }
    </style>
@endpush

@section('content')
    <!-- Enhanced Header Section -->
    <div class="cms-header">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-3">
                            <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-white-50">Home</a></li>
                            <li class="breadcrumb-item active text-white" aria-current="page">{{ $contentType->title }}</li>
                        </ol>
                    </nav>
                    <h1>{{ $contentType->title }}</h1>
                    @if ($contentType->description)
                        <p class="subtitle">{{ $contentType->description }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Filters Section -->
    <div class="cms-filters">
        <div class="container">
            <form method="GET" action="{{ route('cms.index', $contentType->slug) }}" id="filterForm">
                <div class="row align-items-center">
                    <div class="col-lg-6 col-md-12">
                        <div class="search-box">
                            <input type="text" name="search"
                                placeholder="Search {{ strtolower($contentType->title) }}..."
                                value="{{ request('search') }}" class="form-control">
                            <button type="submit" class="search-btn">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-12">
                        <div class="filter-tags">
                            <span class="me-2"><strong>Sort by:</strong></span>
                            <a href="{{ route('cms.index', $contentType->slug) }}?{{ http_build_query(array_merge(request()->except('sort'), ['sort' => 'latest'])) }}"
                                class="filter-tag {{ request('sort', 'latest') === 'latest' ? 'active' : '' }}">Latest</a>
                            <a href="{{ route('cms.index', $contentType->slug) }}?{{ http_build_query(array_merge(request()->except('sort'), ['sort' => 'oldest'])) }}"
                                class="filter-tag {{ request('sort') === 'oldest' ? 'active' : '' }}">Oldest</a>
                            <a href="{{ route('cms.index', $contentType->slug) }}?{{ http_build_query(array_merge(request()->except('sort'), ['sort' => 'title'])) }}"
                                class="filter-tag {{ request('sort') === 'title' ? 'active' : '' }}">A-Z</a>
                            <a href="{{ route('cms.index', $contentType->slug) }}?{{ http_build_query(array_merge(request()->except('featured'), ['featured' => '1'])) }}"
                                class="filter-tag {{ request('featured') === '1' ? 'active' : '' }}">Featured</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Content Section -->
    <div class="container">
        <!-- Content Stats and View Toggle -->
        <div class="content-stats">
            <div class="results-count">
                <strong>{{ $contents->total() }}</strong> {{ strtolower($contentType->title) }} found
                @if (request('search'))
                    for "<strong>{{ request('search') }}</strong>"
                @endif
            </div>
            <div class="view-toggle">
                <button type="button" class="view-btn" data-view="grid">
                    <i class="bi bi-grid"></i>
                </button>
                <button type="button" class="view-btn" data-view="list">
                    <i class="bi bi-list"></i>
                </button>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-container" id="contentContainer">
            <div class="row gy-4" id="contentGrid">

                <!-- Travel Inspiration Page Start-->
                <div class="travel-inspiration-page pt-100 mb-100">
                    <div class="container">
                        <div class="row gy-md-5 gy-4 mb-60">
                            @forelse($contents as $index => $content)
                                <div class="col-lg-4 col-md-6 col-sm-12" data-aos="fade-up"
                                    data-aos-delay="{{ ($index % 3) * 100 }}">
                                    <article class="enhanced-blog-card">
                                        <div class="card-image">
                                            <img src="{{ $content->thumbnail && s3_asset($content->thumbnail) ? s3_asset($content->thumbnail) : s3_asset($settings['cms_content_placeholder_image'] ?? 'assets/img/default-blog.jpg') }}"
                                                alt="{{ $content->title }}" loading="lazy">
                                            <div class="card-overlay"></div>
                                            <div class="category-badge">{{ $contentType->title }}</div>
                                            @if ($content->is_featured ?? false)
                                                <div class="featured-badge">Featured</div>
                                            @endif
                                        </div>
                                        <div class="card-content">
                                            <div class="card-meta">
                                                <span class="date">
                                                    <i class="bi bi-calendar3"></i>
                                                    {{ $content->published_at ? $content->published_at->format('M d, Y') : $content->created_at->format('M d, Y') }}
                                                </span>
                                                <span class="views">
                                                    <i class="bi bi-eye"></i>
                                                    {{ $content->views_count ?? 0 }}
                                                </span>
                                            </div>
                                            <h3 class="card-title">
                                                <a href="{{ route('cms.show', [$contentType->slug, $content->slug]) }}">
                                                    {{ $content->title }}
                                                </a>
                                            </h3>
                                            <p class="card-excerpt">
                                                {{ $content->excerpt ?? Str::limit(strip_tags($content->content), 120) }}
                                            </p>
                                            <div class="card-footer">
                                                <a href="{{ route('cms.show', [$contentType->slug, $content->slug]) }}"
                                                    class="read-more">
                                                    Read More
                                                    <i class="bi bi-arrow-right"></i>
                                                </a>
                                                @if ($content->author)
                                                    <small class="author">By {{ $content->author }}</small>
                                                @endif
                                            </div>
                                        </div>
                                    </article>
                                </div>
                            @empty
                                <div class="col-12">
                                    <div class="no-results">
                                        <div class="icon">
                                            <i class="bi bi-search"></i>
                                        </div>
                                        <h3>No {{ strtolower($contentType->title) }} found</h3>
                                        <p>
                                            @if (request('search'))
                                                No results found for "<strong>{{ request('search') }}</strong>". Try
                                                adjusting your search terms.
                                            @else
                                                There are currently no published {{ strtolower($contentType->title) }}
                                                available.
                                            @endif
                                        </p>
                                        @if (request()->hasAny(['search', 'sort', 'featured']))
                                            <a href="{{ route('cms.index', $contentType->slug) }}"
                                                class="filter-tag">Clear All Filters</a>
                                        @endif
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <!-- Enhanced Pagination -->
                    @if ($contents->hasPages())
                        <div class="pagination-enhanced">
                            <div class="pagination-area" data-aos="fade-up">
                                <div class="paginations-button">
                                    @if ($contents->previousPageUrl())
                                        <a href="{{ $contents->previousPageUrl() }}">
                                            <svg width="10" height="10" viewBox="0 0 10 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path d="M8.75 1.25L3.75 5L8.75 8.75" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            Prev
                                        </a>
                                    @endif
                                </div>
                                <ul class="paginations">
                                    @php
                                        $currentPage = $contents->currentPage();
                                        $lastPage = $contents->lastPage();
                                        $range = 2;
                                        $start = max(2, $currentPage - $range);
                                        $end = min($lastPage - 1, $currentPage + $range);
                                    @endphp

                                    @if ($lastPage >= 1)
                                        <li class="page-item {{ $currentPage === 1 ? 'active' : '' }}">
                                            <a href="{{ $contents->url(1) }}">{{ sprintf('%02d', 1) }}</a>
                                        </li>
                                    @endif

                                    @if ($start > 2)
                                        <li class="page-item disabled">
                                            <span>…</span>
                                        </li>
                                    @endif

                                    @for ($page = $start; $page <= $end; $page++)
                                        <li class="page-item {{ $page === $currentPage ? 'active' : '' }}">
                                            <a href="{{ $contents->url($page) }}">{{ sprintf('%02d', $page) }}</a>
                                        </li>
                                    @endfor

                                    @if ($end < $lastPage - 1)
                                        <li class="page-item disabled">
                                            <span>…</span>
                                        </li>
                                    @endif

                                    @if ($lastPage > 1)
                                        <li class="page-item {{ $currentPage === $lastPage ? 'active' : '' }}">
                                            <a href="{{ $contents->url($lastPage) }}">{{ sprintf('%02d', $lastPage) }}</a>
                                        </li>
                                    @endif
                                </ul>
                                <div class="paginations-button">
                                    @if ($contents->nextPageUrl())
                                        <a href="{{ $contents->nextPageUrl() }}">
                                            Next
                                            <svg width="10" height="10" viewBox="0 0 10 10"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path d="M1.25 1.25L6.25 5L1.25 8.75" stroke="currentColor"
                                                    stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Back to Top Button -->
                <a href="#" class="back-to-top" id="backToTop">
                    <i class="bi bi-arrow-up"></i>
                </a>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        // Add page loading state
        document.body.classList.add('loading');

        document.addEventListener('DOMContentLoaded', function() {
            // Remove loading state once page is ready
            setTimeout(() => {
                document.body.classList.remove('loading');
            }, 100);
            // View toggle functionality
            const viewButtons = document.querySelectorAll('.view-btn');
            const contentContainer = document.getElementById('contentContainer');
            const contentGrid = document.getElementById('contentGrid');

            // Set initial view
            let currentView = localStorage.getItem('cms-view') || 'grid';
            setView(currentView);

            viewButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const view = this.getAttribute('data-view');
                    setView(view);
                    localStorage.setItem('cms-view', view);
                });
            });

            function setView(view) {
                viewButtons.forEach(btn => {
                    btn.classList.toggle('active', btn.getAttribute('data-view') === view);
                });

                if (view === 'list') {
                    contentContainer.classList.add('list-view');
                    contentGrid.classList.remove('row');
                } else {
                    contentContainer.classList.remove('list-view');
                    contentGrid.classList.add('row');
                }
            }

            // Back to top functionality
            const backToTop = document.getElementById('backToTop');

            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    backToTop.classList.add('show');
                } else {
                    backToTop.classList.remove('show');
                }
            });

            backToTop.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            // Search form enhancement
            const searchForm = document.getElementById('filterForm');
            const searchInput = searchForm.querySelector('input[name="search"]');

            // Auto-submit search on Enter
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchForm.submit();
                }
            });

            // Add loading states
            const filterLinks = document.querySelectorAll('.filter-tag');
            filterLinks.forEach(link => {
                link.addEventListener('click', function() {
                    // Add loading state
                    const originalText = this.innerHTML;
                    this.classList.add('loading');
                    this.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';

                    // Restore original text if navigation fails
                    setTimeout(() => {
                        this.classList.remove('loading');
                        this.innerHTML = originalText;
                    }, 5000);
                });
            });

            // Enhanced search functionality
            const searchInput = searchForm.querySelector('input[name="search"]');
            let searchTimeout;

            // Add search suggestions (mock data - replace with actual API call)
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();

                if (query.length > 2) {
                    searchTimeout = setTimeout(() => {
                        // Add visual feedback
                        this.style.borderColor = '#BF2629';
                        this.style.boxShadow = '0 0 0 3px rgba(191, 38, 41, 0.1)';
                    }, 300);
                } else {
                    this.style.borderColor = '#e0e0e0';
                    this.style.boxShadow = 'none';
                }
            });

            // Add keyboard navigation for accessibility
            document.addEventListener('keydown', function(e) {
                // Press 'S' to focus search
                if (e.key === 's' || e.key === 'S') {
                    if (document.activeElement !== searchInput) {
                        e.preventDefault();
                        searchInput.focus();
                    }
                }

                // Press 'Escape' to clear search
                if (e.key === 'Escape') {
                    if (document.activeElement === searchInput) {
                        searchInput.value = '';
                        searchInput.blur();
                    }
                }
            });

            // Smooth scrolling for pagination
            const paginationLinks = document.querySelectorAll('.paginations a');
            paginationLinks.forEach(link => {
                link.addEventListener('click', function() {
                    // Add loading state to clicked pagination link
                    this.style.opacity = '0.6';
                    this.style.pointerEvents = 'none';
                });
            });

            // Add hover effects for cards
            const cards = document.querySelectorAll('.enhanced-blog-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.zIndex = '10';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.zIndex = '1';
                });
            });

            // Add intersection observer for lazy loading
            if ('IntersectionObserver' in window) {
                const imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            img.src = img.dataset.src || img.src;
                            img.classList.remove('lazy');
                            observer.unobserve(img);
                        }
                    });
                });

                document.querySelectorAll('.enhanced-blog-card img').forEach(img => {
                    imageObserver.observe(img);
                });
            }

            // Add performance optimization for scroll events
            let ticking = false;

            function updateOnScroll() {
                // Back to top visibility
                if (window.pageYOffset > 300) {
                    backToTop.classList.add('show');
                } else {
                    backToTop.classList.remove('show');
                }

                ticking = false;
            }

            window.addEventListener('scroll', function() {
                if (!ticking) {
                    requestAnimationFrame(updateOnScroll);
                    ticking = true;
                }
            });
        });
    </script>
@endpush
