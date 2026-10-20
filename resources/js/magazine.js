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
    const objectUrls = [];

    const announcePage = (page = 0) => {
        const total = flip.getPageCount();
        const first = page + 1;
        const last = Math.min(page + (flip.getOrientation() === 'landscape' ? 2 : 1), total);
        pageStatus.textContent = first === last ? `${first} / ${total}` : `${first}–${last} / ${total}`;
        previous.disabled = page === 0;
        next.disabled = page >= total - (flip.getOrientation() === 'landscape' ? 2 : 1);
    };

    let flip;
    const cleanUp = () => objectUrls.forEach(URL.revokeObjectURL);
    window.addEventListener('pagehide', event => {
        if (!event.persisted) cleanUp();
    }, { once: true });

    (async () => {
        try {
            const pdf = await getDocument(book.dataset.pdfUrl).promise;
            const firstPage = await pdf.getPage(1);
            const original = firstPage.getViewport({ scale: 1 });
            const width = 620;
            const height = Math.round(width * original.height / original.width);
            const images = [];

            for (let number = 1; number <= pdf.numPages; number++) {
                status.textContent = `Preparing page ${number} of ${pdf.numPages}…`;
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
            }
            await pdf.destroy();

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
            controls.hidden = false;
            status.hidden = true;
            announcePage(0);
        } catch (error) {
            status.textContent = 'This magazine could not be displayed here. Use Open PDF to read it.';
            console.error('Magazine reader failed:', error);
            cleanUp();
        }
    })();

    previous.addEventListener('click', () => flip?.flipPrev());
    next.addEventListener('click', () => flip?.flipNext());
    book.addEventListener('keydown', event => {
        if (event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'ArrowLeft') flip?.flipPrev();
        if (event.key === 'ArrowRight') flip?.flipNext();
    });
}
