@extends('layouts.app')

@section('title', $content->meta_title ?? $content->title . ' - TheTaxi')

@if($content->meta_description)
@section('meta')
    <meta name="description" content="{{ $content->meta_description }}">
    @if($content->meta_tags)
    <meta name="keywords" content="{{ $content->meta_tags }}">
    @endif
@endsection
@endif

@section('content')
    <!-- Breadcrumb section Start-->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url(assets/img/innerpages/breadcrumb-bg2.jpg);">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $content->title }}</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ url('/') }}">Home</a></li>
                    <li><a href="{{ route('cms.index', $contentType->slug) }}">{{ $contentType->title }}</a></li>
                    <li>{{ $content->title }}</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- Breadcrumb section End-->

    <!-- Inspiration Details Page Start-->
    <div class="inspiration-details-page pt-100 mb-100">
        <div class="container">
            <div class="row g-lg-4 gy-5 justify-content-between">
                <div class="col-xl-7 col-lg-8">
                    <div class="inspiration-details">
                        <h2>{{ $content->title }}</h2>
                        <span class="line-break"></span>
                        
                        @if($content->excerpt)
                            <p>{{ $content->excerpt }}</p>
                            <span class="line-break"></span>
                            <span class="line-break"></span>
                        @endif

                        @if($content->featured_image)
                            <div class="inspiration-image mb-50">
                                <img src="{{ $content->featured_image }}" alt="{{ $content->title }}">
                                <span>{{ $contentType->title }} - {{ $content->title }}</span>
                            </div>
                        @endif

                        <!-- Main Content -->
                        <div class="content-body">
                            {!! $content->body !!}
                        </div>

                        @if($content->gallery_images && count($content->gallery_images) > 0)
                            <span class="line-break"></span>
                            <span class="line-break"></span>
                            <div class="row g-4 mb-50">
                                @foreach($content->gallery_images as $index => $image)
                                    @if($index == 0)
                                        <div class="col-md-7">
                                            <img src="{{ $image }}" alt="Gallery image">
                                        </div>
                                    @elseif($index == 1)
                                        <div class="col-md-5">
                                            <img src="{{ $image }}" alt="Gallery image">
                                        </div>
                                    @else
                                        @break
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        @if($content->custom_fields && isset($content->custom_fields['quote']))
                            <blockquote>
                                <svg width="28" height="125" viewBox="0 0 28 125" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M23 10L27.7735 0H16.2265L21 10H23ZM22 50L22.725 50.6888L23 50.3993V50H22ZM3 70L2.275 69.3112L0.670689 71H3V70ZM22 70H23V69H22V70ZM21 115L16.2265 125H27.7735L23 115H21ZM21 9V50H23V9H21ZM21.275 49.3112L2.275 69.3112L3.725 70.6888L22.725 50.6888L21.275 49.3112ZM3 71H22V69H3V71ZM21 70V116H23V70H21Z" />
                                </svg>
                                <div class="content">
                                    <svg class="quote" width="100" height="74" viewBox="0 0 100 74" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M76.0844 0.333984C62.1979 0.333984 52.1722 11.7089 52.1722 28.5534C52.2591 53.0243 70.802 70.326 97.5533 73.6474C100.031 73.958 100.988 70.5417 98.7054 69.5366C88.4449 65.0074 83.2581 59.2617 82.5886 53.5764C82.0886 49.3275 84.4146 45.6049 87.3406 44.9061C94.9186 43.0987 99.9967 33.734 99.9967 24.0586C99.9967 17.7665 97.4774 11.732 92.993 7.28277C88.5086 2.83354 82.4264 0.333984 76.0844 0.333984ZM23.9123 0.333984C10.0258 0.333984 0 11.7089 0 28.5534C0.0869522 53.0243 18.6298 70.326 45.3811 73.6474C47.8593 73.958 48.8158 70.5417 46.5333 69.5366C36.2727 65.0074 31.0859 59.2617 30.4164 53.5764C29.9164 49.3275 32.2424 45.6049 35.1684 44.9061C42.7464 43.0987 47.8245 33.734 47.8245 24.0586C47.8245 17.7665 45.3052 11.732 40.8208 7.28277C36.3364 2.83354 30.2542 0.333984 23.9123 0.333984Z"
                                            fill="#F0F0F0" />
                                    </svg>
                                    <p>{{ $content->custom_fields['quote'] }}</p>
                                    <div class="name-deg">
                                        <h5>{{ $content->custom_fields['quote_author'] ?? 'Anonymous' }}</h5>
                                        @if(isset($content->custom_fields['quote_author_title']))
                                            <span>{{ $content->custom_fields['quote_author_title'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </blockquote>
                        @endif

                        @if($content->custom_fields && isset($content->custom_fields['tags']) && count($content->custom_fields['tags']) > 0)
                            <span class="line-break"></span>
                            <span class="line-break"></span>
                            <div class="activite-tag">
                                <h6>Related Topics:</h6>
                                <ul>
                                    @foreach($content->custom_fields['tags'] as $tag)
                                        <li><a href="{{ route('cms.search') }}?q={{ urlencode($tag) }}">{{ $tag }}</a></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-xl-4 col-lg-4">
                    <div class="blog-sidebar">
                        <!-- Content Info -->
                        <div class="blog-sidebar-widget mb-40">
                            <h4 class="sidebar-widget-title">{{ $contentType->title }} Details</h4>
                            <div class="content-info">
                                <ul>
                                    <li><strong>Published:</strong> {{ $content->published_at ? $content->published_at->format('F d, Y') : $content->created_at->format('F d, Y') }}</li>
                                    @if($content->views_count > 0)
                                        <li><strong>Views:</strong> {{ number_format($content->views_count) }}</li>
                                    @endif
                                    @if($content->custom_fields && isset($content->custom_fields['read_time']))
                                        <li><strong>Read Time:</strong> {{ $content->custom_fields['read_time'] }}</li>
                                    @endif
                                    @if($content->custom_fields && isset($content->custom_fields['author_name']))
                                        <li><strong>Author:</strong> {{ $content->custom_fields['author_name'] }}</li>
                                    @endif
                                </ul>
                            </div>
                        </div>

                        {{-- @if($relatedContents && $relatedContents->count() > 0)
                            <!-- Related Content -->
                            <div class="blog-sidebar-widget mb-40">
                                <h4 class="sidebar-widget-title">Related {{ $contentType->title }}</h4>
                                <div class="related-content">
                                    @foreach($relatedContents as $related)
                                        <div class="single-related-content mb-20">
                                            <div class="related-content-img">
                                                @if($related->featured_image)
                                                    <img src="{{ $related->featured_image }}" alt="{{ $related->title }}">
                                                @else
                                                    <img src="assets/img/default-blog.jpg" alt="{{ $related->title }}">
                                                @endif
                                            </div>
                                            <div class="related-content-details">
                                                <h6><a href="{{ route('cms.show', [$contentType->slug, $related->slug]) }}">{{ $related->title }}</a></h6>
                                                <span class="date">{{ $related->published_at ? $related->published_at->format('M d, Y') : $related->created_at->format('M d, Y') }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif --}}

                        <!-- Share Widget -->
                        <div class="blog-sidebar-widget">
                            <h4 class="sidebar-widget-title">Share This {{ $contentType->title }}</h4>
                            <div class="social-share">
                                <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(request()->fullUrl()) }}" target="_blank" class="facebook">
                                    <i class="fab fa-facebook-f"></i>
                                </a>
                                <a href="https://twitter.com/intent/tweet?url={{ urlencode(request()->fullUrl()) }}&text={{ urlencode($content->title) }}" target="_blank" class="twitter">
                                    <i class="fab fa-twitter"></i>
                                </a>
                                <a href="https://www.linkedin.com/sharing/share-offsite/?url={{ urlencode(request()->fullUrl()) }}" target="_blank" class="linkedin">
                                    <i class="fab fa-linkedin-in"></i>
                                </a>
                                <a href="mailto:?subject={{ urlencode($content->title) }}&body={{ urlencode(request()->fullUrl()) }}" class="email">
                                    <i class="fas fa-envelope"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if($content->allow_comments)
                <!-- Comments Section -->
                <div class="row mt-70">
                    <div class="col-xl-7 col-lg-8">
                        <div class="comments-section">
                            <h4>Comments</h4>
                            <!-- Add your comments system here -->
                            <p class="text-muted">Comments feature coming soon...</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
    <!--Inspiration Details Page End-->

@endsection