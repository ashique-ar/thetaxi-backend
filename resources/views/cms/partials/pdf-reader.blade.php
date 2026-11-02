<section class="cms-pdf-reader my-4" data-cms-pdf data-pdf-url="{{ $pdfUrl }}" data-preview="{{ $pdfPreview }}" aria-label="{{ $content->title }} PDF preview">
    <a class="btn btn-outline-primary mb-3" href="{{ $pdfUrl }}" target="_blank" rel="noopener">Open PDF</a>
    @if ($pdfPreview === 'magazine')
        <div data-magazine-status role="status" aria-live="polite">Preparing your magazine…</div>
        <div data-magazine-book data-pdf-url="{{ $pdfUrl }}" aria-label="Magazine pages" tabindex="0" hidden></div>
        <div data-magazine-controls hidden><button type="button" data-magazine-prev aria-label="Previous page">‹</button><span data-magazine-page aria-live="polite"></span><button type="button" data-magazine-next aria-label="Next page">›</button></div>
    @else
        <div data-pdf-pages aria-live="polite"></div>
        @if ($pdfPreview === 'carousel')
            <div class="d-flex justify-content-center gap-3 mt-3"><button type="button" class="btn btn-outline-secondary" data-pdf-prev>Previous</button><span data-pdf-page aria-live="polite"></span><button type="button" class="btn btn-outline-secondary" data-pdf-next>Next</button></div>
        @endif
    @endif
</section>
@vite('resources/js/cms-pdf.js')
@if ($pdfPreview === 'magazine')
    @vite('resources/js/magazine.js')
@endif
