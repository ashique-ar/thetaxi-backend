import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { PageFlip } from 'page-flip';

GlobalWorkerOptions.workerSrc = pdfWorker;

const book = document.querySelector('[data-magazine-book]');
if (book) {
    const status = document.querySelector('[data-magazine-status]');
    const controls = document.querySelector('[data-magazine-controls]');
    const pageStatus = document.querySelector('[data-magazine-page]');
    const previous = document.querySelector('[data-magazine-prev]');
    const next = document.querySelector('[data-magazine-next]');
    const loadingLabel = document.querySelector('[data-magazine-loading-label]');
    const progress = document.querySelector('[data-magazine-progress]');
    const zoomLabel = document.querySelector('[data-magazine-zoom-label]');
    const objectUrls = [];

    const announcePage = (page = 0) => {
        const total = flip.getPageCount();
        const first = page + 1;
        const last = Math.min(page + (flip.getOrientation() === 'landscape' ? 2 : 1), total);
        pageStatus.textContent = first === last ? `Page ${first} / ${total}` : `Pages ${first}–${last} / ${total}`;
        previous.disabled = page === 0;
        next.disabled = page >= total - (flip.getOrientation() === 'landscape' ? 2 : 1);
    };

    let flip;
    const cleanUp = () => objectUrls.forEach(URL.revokeObjectURL);
    let zoom = 100;
    const updateZoom = () => {
        book.style.transform = `scale(${zoom / 100})`;
        book.style.transformOrigin = 'center top';
        if (zoomLabel) zoomLabel.textContent = `${zoom}%`;
    };
    document.querySelector('[data-magazine-zoom-out]')?.addEventListener('click', () => { zoom = Math.max(50, zoom - 10); updateZoom(); });
    document.querySelector('[data-magazine-zoom-in]')?.addEventListener('click', () => { zoom = Math.min(150, zoom + 10); updateZoom(); });
    window.addEventListener('pagehide', event => {
        if (!event.persisted) cleanUp();
    }, { once: true });

    (async () => {
        try {
            const loadingTask = getDocument({ url: book.dataset.pdfUrl });
            loadingTask.onProgress = ({ loaded, total }) => {
                const percent = total > 0 ? Math.min(100, Math.round(loaded / total * 100)) : null;
                if (pageStatus) pageStatus.textContent = percent === null ? 'Downloading PDF' : `Downloading ${percent}%`;
                if (loadingLabel) loadingLabel.textContent = percent === null ? 'Downloading your document…' : `Downloading your document… ${percent}%`;
                if (progress) {
                    progress.parentElement?.classList.toggle('is-indeterminate', percent === null);
                    if (percent !== null) progress.style.width = `${percent}%`;
                }
            };
            const pdf = await loadingTask.promise;
            if (progress) progress.style.width = '0%';
            progress?.parentElement?.classList.remove('is-indeterminate');
            const firstPage = await pdf.getPage(1);
            const original = firstPage.getViewport({ scale: 1 });
            const width = 620;
            const height = Math.round(width * original.height / original.width);
            const images = [];

            for (let number = 1; number <= pdf.numPages; number++) {
                if (loadingLabel) loadingLabel.textContent = `Preparing page ${number} of ${pdf.numPages}…`;
                if (pageStatus) pageStatus.textContent = `Loading ${number} / ${pdf.numPages}`;
                const page = number === 1 ? firstPage : await pdf.getPage(number);
                const viewport = page.getViewport({ scale: Math.min(1.5, 1400 / page.getViewport({ scale: 1 }).height) });
                const canvas = document.createElement('canvas');
                canvas.width = Math.ceil(viewport.width);
                canvas.height = Math.ceil(viewport.height);
                await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
                await page.cleanup();
                const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.88));
                if (!blob) throw new Error('Could not render a magazine page.');
                const url = URL.createObjectURL(blob);
                objectUrls.push(url);
                images.push(url);
                canvas.width = canvas.height = 0;
                if (progress) progress.style.width = `${Math.round(number / pdf.numPages * 100)}%`;
            }
            await loadingTask.destroy();

            flip = new PageFlip(book, {
                width,
                height,
                size: 'stretch',
                minWidth: 280,
                maxWidth: width,
                minHeight: Math.min(height, 360),
                maxHeight: 900,
                showCover: true,
                usePortrait: true,
                autoSize: true,
                maxShadowOpacity: 0.35,
                mobileScrollSupport: false,
            });
            flip.on('flip', event => announcePage(event.data));
            flip.on('changeOrientation', () => announcePage(flip.getCurrentPageIndex()));
            flip.loadFromImages(images);

            book.hidden = false;
            updateZoom();
            controls.hidden = false;
            status.hidden = true;
            announcePage(0);
        } catch (error) {
            if (loadingLabel) loadingLabel.textContent = 'This magazine could not be displayed here. Use View PDF to read it.';
            console.error('Magazine reader failed:', error);
            cleanUp();
        }
    })();

    document.querySelector('[data-magazine-fullscreen]')?.addEventListener('click', () => {
        const frame = document.querySelector('[data-magazine-frame]');
        if (document.fullscreenElement) document.exitFullscreen();
        else frame?.requestFullscreen?.();
    });

    previous.addEventListener('click', () => flip?.flipPrev());
    next.addEventListener('click', () => flip?.flipNext());
    book.addEventListener('keydown', event => {
        if (event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'ArrowLeft') flip?.flipPrev();
        if (event.key === 'ArrowRight') flip?.flipNext();
    });
}
