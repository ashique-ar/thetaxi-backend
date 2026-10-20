@extends('layouts.app')

@section('title', $page->seo_title ?: $page->title)
@section('seo_exact_title', 'true')

@push('meta')
    <meta name="description" content="{{ $page->seo_description ?: $page->excerpt }}">
    @if ($page->seo_keywords)<meta name="keywords" content="{{ $page->seo_keywords }}">@endif
@endpush

@push('styles')
<style>
    .managed-page{--reader-bg:#101722;--reader-ink:#17202c;color:var(--reader-ink)}
    .managed-page__heading{max-width:72rem;margin:0 auto;padding:clamp(24px,5vw,56px) 24px 20px}
    .managed-page--template-full-width .managed-page__heading,.managed-page--template-full-width .managed-page__body{max-width:none}
    .managed-page__heading h1{margin:0;font-size:clamp(1.75rem,4vw,3.25rem);line-height:1.1;font-weight:750;overflow-wrap:anywhere}
    .managed-page__summary{max-width:72ch;margin:14px 0 0;color:#536172;line-height:1.65}
    .managed-page__body{max-width:72rem;margin:0 auto;padding:12px 24px 48px;line-height:1.75;overflow-wrap:anywhere}
    .managed-page__body img,.managed-page__body video{max-width:100%;height:auto}
    .managed-page__body iframe{max-width:100%}
    .managed-page__reader{background:var(--reader-bg);padding:12px clamp(12px,3vw,40px) 32px}
    .managed-page__reader-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;max-width:1600px;margin:0 auto 12px;color:#f8fafc}
    .managed-page__reader-title{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:650}
    .managed-page__reader-actions{display:flex;gap:10px;flex:none}
    .managed-page__reader-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border:1px solid #8290a2;border-radius:8px;color:#fff;text-decoration:none;font-weight:600}
    .managed-page__reader-actions a:focus-visible{outline:3px solid #fbbf24;outline-offset:3px}
    .managed-page__frame{display:block;width:100%;max-width:1600px;height:min(88svh,1200px);min-height:68vh;margin:0 auto;border:0;background:#fff;border-radius:4px}
    .managed-page--pdf-only .managed-page__reader{padding:10px 0 16px}
    .managed-page--pdf-only .managed-page__reader-bar{max-width:none;padding:0 clamp(12px,2vw,28px)}
    .managed-page--pdf-only .managed-page__frame{max-width:none;height:calc(100svh - 112px);min-height:70vh;border-radius:0}
    .managed-page--magazine .managed-page__reader{background:#171410}
    .managed-page--magazine .managed-page__reader-title{font-family:Georgia,serif;letter-spacing:.02em}
    .managed-page__book-wrap{display:grid;justify-items:center;gap:18px;max-width:1440px;margin:auto}
    .managed-page__book{width:min(100%,1320px);height:min(78svh,900px);min-height:420px;margin:auto;overflow:hidden}
    .managed-page__book img{display:block;width:100%;height:100%;object-fit:contain}
    .managed-page__book-controls{display:flex;align-items:center;justify-content:center;gap:16px;color:#fff}
    .managed-page__book-controls button{min-width:44px;min-height:44px;padding:0 16px;border:1px solid #8290a2;border-radius:8px;background:#171410;color:#fff;font:inherit;cursor:pointer}
    .managed-page__book-controls button:focus-visible{outline:3px solid #fbbf24;outline-offset:3px}
    .managed-page__book-controls button:disabled{opacity:.45;cursor:not-allowed}
    .managed-page__book-status{min-width:5.5rem;text-align:center;font-variant-numeric:tabular-nums}
    .managed-page__book-message{color:#fff;text-align:center;padding:24px}
    .managed-page--magazine .managed-page__frame{max-width:1320px;box-shadow:0 16px 56px #0008}
    .managed-page--pdf-only.managed-page--magazine .managed-page__frame{max-width:none;box-shadow:none}
    .managed-page__fallback{padding:24px;text-align:center;color:#fff}
    .managed-page__preview{padding:10px 16px;background:#fff4cc;color:#523900;text-align:center;font-weight:650}
    .page-password-form{display:grid;gap:10px;max-width:28rem}
    .page-password-form input{width:100%;min-width:0;max-width:100%;min-height:48px;padding:10px 12px;border:1px solid #68778a;border-radius:6px}
    .page-password-form input:focus-visible{outline:3px solid #1d4ed8;outline-offset:2px}
    .page-password-form button{justify-self:start;min-height:44px;padding:0 20px;border:0;border-radius:6px;background:#17202c;color:#fff;font-weight:650;cursor:pointer}
    .page-password-error{color:#b42318}
    @media(max-width:600px){
        .managed-page__heading{padding:24px 16px 16px}
        .managed-page__body{padding:8px 16px 32px}
        .managed-page__reader{padding:10px 0 16px}
        .managed-page__reader-bar{padding:0 12px;align-items:flex-start;flex-direction:column}
        .managed-page__reader-actions{width:100%}
        .managed-page__reader-actions a{flex:1;padding:0 10px}
        .managed-page__frame{height:82svh;min-height:68vh;border-radius:0}
        .managed-page--pdf-only .managed-page__frame{height:calc(100svh - 154px);min-height:70vh}
        .managed-page__book{height:70svh;min-height:360px}
        .managed-page--magazine .managed-page__frame{box-shadow:none}
    }
    @media(prefers-reduced-motion:reduce){.managed-page *{scroll-behavior:auto!important}}
</style>
@endpush

@section('content')
@php
    $hasBody = trim(strip_tags($page->content ?? '')) !== '';
    $isPdf = in_array($page->content_type, ['pdf-reader', 'magazine'], true) && $pdfUrl;
    $pdfOnly = $isPdf && !$hasBody;
    $readerUrl = $pdfUrl ? $pdfUrl . (str_contains($pdfUrl, '#') ? '' : '#view=FitH') : null;
@endphp
<article class="managed-page managed-page--template-{{ \Illuminate\Support\Str::slug($page->template ?: 'default') }} {{ $page->content_type === 'magazine' ? 'managed-page--magazine' : '' }} {{ $pdfOnly ? 'managed-page--pdf-only' : '' }}">
    @if ($previewMode)<div class="managed-page__preview" role="status">Preview · This page may not be published yet.</div>@endif
    @if (!$pdfOnly)
        <header class="managed-page__heading">
            <h1>{{ $page->title }}</h1>
            @if ($page->excerpt)<p class="managed-page__summary">{{ $page->excerpt }}</p>@endif
        </header>
    @endif

    @if ($requiresPassword)
        <section class="managed-page__body" aria-labelledby="page-password-title">
            <h2 id="page-password-title">This page is password protected</h2>
            <p>Enter the password provided to you to continue.</p>
            <form method="POST" action="{{ route('pages.access', $page->slug) }}" class="page-password-form">
                @csrf
                <label for="page-password">Page password</label>
                <input id="page-password" name="password" type="password" required autocomplete="current-password">
                @error('password')<p class="page-password-error" role="alert">{{ $message }}</p>@enderror
                <button type="submit">Continue</button>
            </form>
        </section>
    @else

    @if ($isPdf)
        <section class="managed-page__reader" aria-label="{{ $page->content_type === 'magazine' ? 'Magazine reader' : 'PDF reader' }}">
            <div class="managed-page__reader-bar">
                <span class="managed-page__reader-title">{{ $page->title }}</span>
                <nav class="managed-page__reader-actions" aria-label="Document actions">
                    <a href="{{ $pdfUrl }}" target="_blank" rel="noopener">Open PDF</a>
                    <a href="{{ $pdfUrl }}" download>Download</a>
                </nav>
            </div>
            @if ($page->content_type === 'magazine')
                <div class="managed-page__book-wrap">
                    <div class="managed-page__book-message" data-magazine-status role="status" aria-live="polite">Preparing your magazine…</div>
                    <div class="managed-page__book" data-magazine-book data-pdf-url="{{ $pdfUrl }}" aria-label="{{ $page->title }} magazine pages. Use the arrow keys to turn pages." tabindex="0" hidden></div>
                    <div class="managed-page__book-controls" data-magazine-controls hidden>
                        <button type="button" data-magazine-prev aria-label="Previous page">‹</button>
                        <span class="managed-page__book-status" data-magazine-page aria-live="polite"></span>
                        <button type="button" data-magazine-next aria-label="Next page">›</button>
                    </div>
                </div>
                <noscript><p class="managed-page__fallback">JavaScript is required for the page-turning reader. <a href="{{ $pdfUrl }}" style="color:#fff;text-decoration:underline">Open the PDF</a>.</p></noscript>
                @push('scripts')
                    @vite('resources/js/magazine.js')
                @endpush
            @else
                <iframe class="managed-page__frame" src="{{ $readerUrl }}" title="{{ $page->title }} PDF document" loading="eager" allow="fullscreen"></iframe>
            @endif
            <p class="managed-page__fallback">If the document does not appear, <a href="{{ $pdfUrl }}" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline">open the PDF in a new tab</a>.</p>
        </section>
    @endif

    @if ($hasBody)
        <div class="managed-page__body">{!! $page->content !!}</div>
    @endif
    @endif
</article>
@endsection
