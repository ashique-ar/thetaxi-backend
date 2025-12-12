@extends('layouts.app')

@section('title', $content->meta_title ?? $content->title . ' - TheTaxi')

@push('meta')
    @if ($content->meta_description)
        <meta name="description" content="{{ $content->meta_description }}">
    @endif
    @if ($content->meta_tags)
        <meta name="keywords" content="{{ $content->meta_tags }}">
    @endif
    <meta property="og:title" content="{{ $content->meta_title ?? $content->title }}">
    <meta property="og:description"
        content="{{ $content->meta_description ?? Str::limit(strip_tags($content->excerpt ?? $content->body), 150) }}">
    <meta property="og:image"
        content="{{ $content->thumbnail ? s3_asset($content->thumbnail) : asset('assets/img/default-blog.jpg') }}">
@endpush

@push('styles')
    <style>
        /* Article show improvements */
        .article-hero {
            background: linear-gradient(180deg, rgba(0, 0, 0, 0.35), rgba(0, 0, 0, 0.35)), url('{{ $content->thumbnail ? s3_asset($content->thumbnail) : asset('assets/img/innerpages/breadcrumb-bg2.jpg') }}') center/cover no-repeat;
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

        .article-body {
            font-size: 18px;
            line-height: 1.8;
            color: #333;
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

        @media (max-width:991px) {
            .article-sidebar {
                position: static;
                top: auto;
            }
        }
    </style>
@endpush

@section('content')

    <div class="reading-progress" id="readingProgress" aria-hidden="true"></div>

    {{-- <section class="article-hero">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-2">
                            <li class="breadcrumb-item"><a href="{{ url('/') }}" class="text-white-50">Home</a></li>
                            <li class="breadcrumb-item"><a href="{{ route('cms.index', $contentType->slug) }}"
                                    class="text-white-50">{{ $contentType->title }}</a></li>
                            <li class="breadcrumb-item active text-white" aria-current="page">{{ $content->title }}</li>
                        </ol>
                    </nav>

                </div>
            </div>
        </div>
    </section> --}}

    <section class="article-page">
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
                            <img src="{{ $content->thumbnail ? s3_asset($content->thumbnail) : asset('assets/img/default-blog.jpg') }}"
                                alt="{{ $content->title }}">
                        </div>

                        @if ($content->excerpt)
                            <p class="lead text-muted">{{ $content->excerpt }}</p>
                        @endif

                        <div class="article-body content-body" id="articleBody">
                            {!! $content->body !!}
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
        </div>
    </section>

@endsection

@push('scripts')
    <script>
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
    </script>
@endpush
