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
    try {
        const pdf = await getDocument(reader.dataset.pdfUrl).promise;
        for (let number = 1; number <= pdf.numPages; number++) {
            const page = await pdf.getPage(number);
            const viewport = page.getViewport({ scale: Math.min(1.5, 1100 / page.getViewport({ scale: 1 }).width) });
            const canvas = document.createElement('canvas');
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);
            await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
            canvas.className = 'cms-pdf-page img-fluid mb-3';
            canvas.setAttribute('aria-label', `Page ${number} of ${pdf.numPages}`);
            pages.append(canvas);
        }
        await pdf.destroy();
        let current = 0;
        const update = () => {
            [...pages.children].forEach((page, index) => page.hidden = carousel && index !== current);
            if (counter) counter.textContent = `${current + 1} / ${pages.children.length}`;
            if (previous) previous.disabled = current === 0;
            if (next) next.disabled = current === pages.children.length - 1;
        };
        previous?.addEventListener('click', () => { current--; update(); });
        next?.addEventListener('click', () => { current++; update(); });
        update();
    } catch (error) {
        pages && (pages.textContent = 'PDF preview could not be loaded. Use Open PDF to read it.');
        console.error('CMS PDF preview failed:', error);
    }
});
