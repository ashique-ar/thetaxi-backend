<section class="cms-pdf-reader my-4" data-cms-pdf data-pdf-url="{{ $pdfUrl }}" data-preview="{{ $pdfPreview }}" aria-label="{{ $content->title }} PDF preview">
    <div class="cms-pdf-actions">
        <a href="{{ $pdfUrl }}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> View PDF</a>
        <a href="{{ $pdfUrl }}" download><i class="bi bi-download" aria-hidden="true"></i> Download PDF</a>
    </div>
    @if ($pdfPreview === 'magazine')
        <div class="cms-pdf-frame cms-pdf-magazine" data-magazine-frame>
            <header class="cms-pdf-toolbar">
                <span class="cms-pdf-page-count" data-magazine-page aria-live="polite">Loading PDF…</span>
                <div class="cms-pdf-zoom" aria-label="PDF zoom controls"><button type="button" data-magazine-zoom-out aria-label="Zoom out">−</button><span data-magazine-zoom-label>100%</span><button type="button" data-magazine-zoom-in aria-label="Zoom in">+</button></div>
                <button type="button" class="cms-pdf-fullscreen" data-magazine-fullscreen aria-label="View PDF full screen" title="Full screen"><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i></button>
            </header>
            <div class="cms-pdf-magazine-stage">
                <div class="cms-pdf-loading" data-magazine-status role="status" aria-live="polite">
                    <span class="cms-pdf-spinner" aria-hidden="true"></span>
                    @if ($content->thumbnail)
                        <img class="cms-pdf-cover" src="{{ s3_asset($content->thumbnail) }}" alt="">
                    @else
                        <span class="cms-pdf-cover-placeholder"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></span>
                    @endif
                    <span class="cms-pdf-magazine-copy">{{ $content->title }}</span>
                    <span class="cms-pdf-progress"><span data-magazine-progress></span></span>
                    <span data-magazine-loading-label>Preparing your magazine…</span>
                </div>
                <div data-magazine-book data-pdf-url="{{ $pdfUrl }}" aria-label="Magazine pages" tabindex="0" hidden></div>
            </div>
            <div class="cms-pdf-magazine-controls" data-magazine-controls hidden><button type="button" data-magazine-prev aria-label="Previous page">‹</button><button type="button" data-magazine-next aria-label="Next page">›</button></div>
        </div>
    @else
        <div class="cms-pdf-frame">
            <header class="cms-pdf-toolbar">
                <span class="cms-pdf-page-count" data-pdf-page aria-live="polite">Loading PDF…</span>
                <div class="cms-pdf-zoom" aria-label="PDF zoom controls"><button type="button" data-pdf-zoom-out aria-label="Zoom out" disabled>−</button><span data-pdf-zoom-label>100%</span><button type="button" data-pdf-zoom-in aria-label="Zoom in" disabled>+</button></div>
                <button type="button" class="cms-pdf-fullscreen" data-pdf-fullscreen aria-label="View PDF full screen" title="Full screen"><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i></button>
            </header>
            <div class="cms-pdf-loading" data-pdf-loading role="status" aria-live="polite">
                <span class="cms-pdf-spinner" aria-hidden="true"></span><span data-pdf-loading-label>Preparing your document…</span>
                <span class="cms-pdf-progress"><span data-pdf-progress></span></span>
            </div>
            <div class="cms-pdf-error" data-pdf-error role="alert" hidden>We couldn’t load the PDF preview. You can still open or download the document above.</div>
            <div class="cms-pdf-pages" data-pdf-pages></div>
        </div>
        @if ($pdfPreview === 'carousel')
            <div class="d-flex justify-content-center gap-3 mt-3"><button type="button" class="btn btn-outline-secondary" data-pdf-prev>Previous</button><button type="button" class="btn btn-outline-secondary" data-pdf-next>Next</button></div>
        @endif
    @endif
</section>
@vite('resources/js/cms-pdf.js')
@if ($pdfPreview === 'magazine')
    @vite('resources/js/magazine.js')
@endif
