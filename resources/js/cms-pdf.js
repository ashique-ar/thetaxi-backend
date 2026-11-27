import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

GlobalWorkerOptions.workerSrc = pdfWorker;

document.querySelectorAll('[data-cms-pdf]').forEach(async reader => {
    if (reader.dataset.preview === 'magazine') return;
    const pages = reader.querySelector('[data-pdf-pages]');
    const carousel = reader.dataset.preview === 'carousel';
    const previous = reader.querySelector('[data-pdf-prev]');
    const next = reader.querySelector('[data-pdf-next]');
    const counter = reader.querySelector('[data-pdf-page]');
    const loading = reader.querySelector('[data-pdf-loading]');
    const loadingLabel = reader.querySelector('[data-pdf-loading-label]');
    const progress = reader.querySelector('[data-pdf-progress]');
    const errorMessage = reader.querySelector('[data-pdf-error]');
    try {
        const loadingTask = getDocument({ url: reader.dataset.pdfUrl });
        const pdf = await loadingTask.promise;
        const renderedPages = document.createDocumentFragment();
        for (let number = 1; number <= pdf.numPages; number++) {
            if (loadingLabel) loadingLabel.textContent = 'Preparing page ' + number + ' of ' + pdf.numPages + '…';
            if (counter) counter.textContent = 'Loading ' + number + ' / ' + pdf.numPages;
            const page = await pdf.getPage(number);
            const viewport = page.getViewport({ scale: Math.min(1.5, 1100 / page.getViewport({ scale: 1 }).width) });
            const canvas = document.createElement('canvas');
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);
            await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
            canvas.className = 'cms-pdf-page img-fluid mb-3';
            canvas.setAttribute('aria-label', `Page ${number} of ${pdf.numPages}`);
            renderedPages.append(canvas);
            if (progress) progress.style.width = Math.round(number / pdf.numPages * 100) + '%';
        }
        if (progress) progress.style.width = '100%';
        await loadingTask.destroy();
        pages.append(renderedPages);
        if (loading) loading.hidden = true;
        let current = 0;
        let zoom = 100;
        const zoomLabel = reader.querySelector('[data-pdf-zoom-label]');
        const zoomButtons = reader.querySelectorAll('[data-pdf-zoom-in], [data-pdf-zoom-out]');
        const updateZoom = () => {
            pages.style.setProperty('--cms-pdf-zoom', zoom + '%');
            if (zoomLabel) zoomLabel.textContent = zoom + '%';
            zoomButtons.forEach(button => button.disabled = button.hasAttribute('data-pdf-zoom-in') ? zoom >= 150 : zoom <= 50);
        };
        reader.querySelector('[data-pdf-zoom-in]')?.addEventListener('click', () => { zoom = Math.min(150, zoom + 10); updateZoom(); });
        reader.querySelector('[data-pdf-zoom-out]')?.addEventListener('click', () => { zoom = Math.max(50, zoom - 10); updateZoom(); });
        reader.querySelector('[data-pdf-fullscreen]')?.addEventListener('click', () => {
            const frame = reader.querySelector('.cms-pdf-frame');
            if (document.fullscreenElement) document.exitFullscreen();
            else frame?.requestFullscreen?.();
        });
        const update = () => {
            [...pages.children].forEach((page, index) => page.hidden = carousel && index !== current);
            if (counter) counter.textContent = carousel ? `${current + 1} / ${pages.children.length}` : `${pages.children.length} pages`;
            if (previous) previous.disabled = current === 0;
            if (next) next.disabled = current === pages.children.length - 1;
        };
        previous?.addEventListener('click', () => { current--; update(); });
        next?.addEventListener('click', () => { current++; update(); });
        updateZoom();
        update();
    } catch (error) {
        if (loading) loading.hidden = true;
        if (errorMessage) errorMessage.hidden = false;
        else if (pages) pages.textContent = 'PDF preview could not be loaded. Use Open PDF to read it.';
        console.error('CMS PDF preview failed:', error);
    }
});
